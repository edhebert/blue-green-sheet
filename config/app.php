<?php
// config/app.php

use craft\helpers\App;
use Craft;
use yii\base\Event;
use craft\services\Elements;
use craft\services\Users as UsersService;
use craft\events\ElementEvent;
use craft\events\UserEvent;
use craft\elements\User as UserElement;
use craft\elements\Entry as EntryElement;
use craft\web\twig\variables\CraftVariable;
use craft\mail\Message;

return [
    // keep your existing app ID behavior
    'id' => App::env('CRAFT_APP_ID') ?: 'CraftCMS',

    // add runtime hooks
    'bootstrap' => [
        static function () {
            // Helper: ensure the given user belongs to the 'jobPosters' group.
            $ensureJobPosters = static function (UserElement $u): void {
                if (!$u->id) {
                    return;
                }
                $group = Craft::$app->getUserGroups()->getGroupByHandle('jobPosters');
                if (!$group) {
                    return;
                }

                // Merge existing group IDs with jobPosters, then assign
                $existing = Craft::$app->getUserGroups()->getGroupsByUserId($u->id);
                $ids = array_map(static fn($g) => $g->id, $existing);
                if (!in_array($group->id, $ids, true)) {
                    $ids[] = $group->id;
                    // Craft 5: assignUserToGroups() is on the Users service
                    Craft::$app->getUsers()->assignUserToGroups($u->id, $ids);
                }
            };

            // 1) After a new element is saved from the SITE (front end) — catch new users
            Event::on(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                static function (ElementEvent $e) use ($ensureJobPosters) {
                    if (!$e->isNew) {
                        return;
                    }
                    if (!Craft::$app->getRequest()->getIsSiteRequest()) {
                        return; // ignore CP-created users
                    }
                    $el = $e->element;
                    if ($el instanceof UserElement) {
                        $ensureJobPosters($el);
                    }
                }
            );

            // 2) After email verification — belt-and-suspenders assignment
            Event::on(
                UsersService::class,
                UsersService::EVENT_AFTER_VERIFY_EMAIL,
                static function (UserEvent $e) use ($ensureJobPosters) {
                    $u = $e->user;
                    if ($u instanceof UserElement) {
                        $ensureJobPosters($u);
                    }
                }
            );

            // 3) After activation — final safety net
            Event::on(
                UsersService::class,
                UsersService::EVENT_AFTER_ACTIVATE_USER,
                static function (UserEvent $e) use ($ensureJobPosters) {
                    $u = $e->user;
                    if ($u instanceof UserElement) {
                        $ensureJobPosters($u);
                    }
                }
            );

            // --- BEGIN: expose craft.projectTools.options(handle) in Twig ---
            /**
             * Lightweight tools exposed to Twig as `craft.projectTools`.
             */
            if (!class_exists('ProjectTools')) {
                class ProjectTools {
                    /**
                     * Return options for a Dropdown or Radio Buttons field by handle.
                     * Each item = ['label' => string, 'value' => string, 'default' => bool]
                     */
                    public function options(string $handle): array
                    {
                        $field = Craft::$app->getFields()->getFieldByHandle($handle);
                        if (!$field) {
                            return [];
                        }

                        // Check if field supports options (Dropdown or RadioButtons)
                        if ($field instanceof \craft\fields\Dropdown || $field instanceof \craft\fields\RadioButtons) {
                            $out = [];
                            foreach ($field->options as $opt) {
                                // $opt may be an array or OptionModel
                                $label   = is_array($opt) ? ($opt['label'] ?? $opt['value'] ?? '') : ($opt->label ?? '');
                                $value   = is_array($opt) ? ($opt['value'] ?? $opt['label'] ?? '') : ($opt->value ?? '');
                                $default = is_array($opt) ? (bool)($opt['default'] ?? false) : (bool)($opt->default ?? false);
                                if ($label === '' && $value === '') { continue; }
                                $out[] = ['label' => $label, 'value' => $value, 'default' => $default];
                            }
                            return $out;
                        }

                        // Not a Dropdown/Radio: nothing to return
                        return [];
                    }
                }
            }

            // Register Twig variable
            Event::on(
                CraftVariable::class,
                CraftVariable::EVENT_INIT,
                static function($e) {
                    $e->sender->set('projectTools', new ProjectTools());
                }
            );
            // --- END: expose craft.projectTools.options(handle) in Twig ---

            // --- BEGIN: admin notification emails ---

            /**
             * Helper to resolve admin recipient list from env or fallback.
             * Use a comma-separated list in .env, e.g. ADMIN_NOTIFY_EMAILS="admin1@example.com, admin2@example.com"
             */
            $resolveAdminRecipients = static function(): array {
                $env = trim((string)App::env('ADMIN_NOTIFY_EMAILS'));
                if ($env !== '') {
                    return array_values(array_filter(array_map('trim', explode(',', $env))));
                }
                // fallback to system email if set, else change this to your preferred address
                $systemEmail = trim((string)App::env('SYSTEM_EMAIL'));
                if ($systemEmail !== '') {
                    return [$systemEmail];
                }
                return ['ed@theblueocean.com']; // <-- update this if you don't set env vars
            };

            // A) Notify when a user activates their account
            Event::on(
                UsersService::class,
                UsersService::EVENT_AFTER_ACTIVATE_USER,
                static function(UserEvent $e) use ($resolveAdminRecipients) {
                    $user = $e->user;
                    if (!$user instanceof UserElement) {
                        return;
                    }
                    $to = $resolveAdminRecipients();
                    if (!$to) {
                        return;
                    }
                    $msg = (new Message())
                        ->setTo($to)
                        ->setSubject('New user activated')
                        ->setTextBody("A new user has activated their account:\n\nEmail: {$user->email}\nName: {$user->friendlyName}");
                    Craft::$app->getMailer()->send($msg);
                }
            );

            // B) Notify when a new Organization entry is created (avoid duplicates)
            Event::on(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                static function(ElementEvent $e) use ($resolveAdminRecipients) {
                    if (!$e->isNew) {
                        return; // only on first creation
                    }

                    $el = $e->element;
                    if (!$el instanceof EntryElement) {
                        return;
                    }

                    $section = $el->getSection();
                    if (!$section || $section->handle !== 'organizations') {
                        return;
                    }

                    // --- de-dup guards ---
                    // Only canonical (not a draft/revision/provisional)
                    if (!$el->getIsCanonical() || $el->getIsDraft() || $el->getIsRevision()) {
                        return;
                    }
                    // Skip propagation saves (multi-site)
                    if (property_exists($el, 'propagating') && $el->propagating) {
                        return;
                    }
                    // If you run multi-site and want *only* one site to notify, uncomment:
                    // $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
                    // if ((int)$el->siteId !== (int)$primarySiteId) { return; }

                    $to = $resolveAdminRecipients();
                    if (!$to) {
                        return;
                    }

                    $msg = (new Message())
                        ->setTo($to)
                        ->setSubject('New organization added')
                        ->setTextBody("A new organization entry was created:\n\nTitle: {$el->title}\nURL: " . ($el->getUrl() ?? '(no URL)'));
                    Craft::$app->getMailer()->send($msg);
                }
            );

            // --- END: admin notification emails ---
        },
    ],
];
