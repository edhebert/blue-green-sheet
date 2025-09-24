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
                    $htmlBody = "<p>A new user has activated their account:</p>";
                    $htmlBody .= "<p><strong>Email:</strong> {$user->email}</p>";
                    $htmlBody .= "<p><strong>Name:</strong> {$user->fullName}</p>";

                    $msg = (new Message())
                        ->setTo($to)
                        ->setSubject('New user activated')
                        ->setHtmlBody($htmlBody);
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

                    $cpUrl = \craft\helpers\UrlHelper::cpUrl('entries/organizations/' . $el->id);
                    $publicUrl = $el->getUrl();

                    $htmlBody = "<p>A new organization entry was created:</p>";
                    $htmlBody .= "<p><strong>Title:</strong> {$el->title}</p>";
                    $htmlBody .= "<p><a href=\"{$cpUrl}\">View in Control Panel</a></p>";
                    if ($publicUrl) {
                        $htmlBody .= "<p><a href=\"{$publicUrl}\">View Public Page</a></p>";
                    }

                    $msg = (new Message())
                        ->setTo($to)
                        ->setSubject('New organization added')
                        ->setHtmlBody($htmlBody);
                    Craft::$app->getMailer()->send($msg);
                }
            );

            // --- END: admin notification emails ---

            // --- BEGIN: Job posting payment and activation handlers ---

            /**
             * Custom controller to handle job posting payment and activation
             */
            if (!class_exists('JobPostingController')) {
                class JobPostingController extends \craft\web\Controller
                {
                    protected array|int|bool $allowAnonymous = false;

                    /**
                     * Handle invoice payment - immediately activate job posting
                     */
                    public function actionActivateJobPosting()
                    {
                        $this->requirePostRequest();
                        $this->requireLogin();

                        $request = Craft::$app->getRequest();
                        $session = Craft::$app->getSession();

                        // Get form data
                        $amount = $request->getRequiredBodyParam('amount');
                        $duration = $request->getRequiredBodyParam('duration');
                        $paymentMethod = $request->getRequiredBodyParam('paymentMethod');

                        // Get the job entry ID from session (set during job creation)
                        $jobId = $session->get('pendingJobId');
                        if (!$jobId) {
                            $session->setError('No pending job found. Please create your job posting again.');
                            return $this->redirectToPostedUrl();
                        }

                        // Load the job entry
                        $job = \craft\elements\Entry::find()->id($jobId)->one();
                        if (!$job) {
                            $session->setError('Job posting not found.');
                            return $this->redirectToPostedUrl();
                        }

                        // Activate the job (set enabled = true)
                        $job->enabled = true;

                        // Mark as paid (for invoice payment)
                        $job->setFieldValue('paid', true);

                        // Set expiration date based on duration
                        $expiryDate = new DateTime();
                        $expiryDate->add(new DateInterval('P' . $duration . 'M')); // Add months
                        $job->expiryDate = $expiryDate;

                        // Save the job
                        if (Craft::$app->getElements()->saveElement($job)) {
                            // Clear the pending job from session
                            $session->remove('pendingJobId');

                            // Send notifications
                            $this->sendJobPostingNotifications($job, $amount, $duration, $paymentMethod);

                            $session->setNotice('Your job posting has been published successfully!');
                            return $this->redirect($job->getUrl());
                        } else {
                            $session->setError('Failed to publish your job posting. Please try again.');
                            return $this->redirectToPostedUrl();
                        }
                    }

                    /**
                     * Handle Stripe payment processing
                     */
                    public function actionProcessStripePayment()
                    {
                        $this->requirePostRequest();
                        $this->requireLogin();

                        $request = Craft::$app->getRequest();
                        $session = Craft::$app->getSession();

                        try {
                            // Get form data
                            $amount = (int)$request->getRequiredBodyParam('amount');
                            $duration = $request->getRequiredBodyParam('duration');
                            $paymentMethodId = $request->getRequiredBodyParam('paymentMethodId');

                            // Get the job entry ID from session
                            $jobId = $session->get('pendingJobId');
                            if (!$jobId) {
                                throw new \Exception('No pending job found. Please create your job posting again.');
                            }

                            // Load the job entry
                            $job = \craft\elements\Entry::find()->id($jobId)->one();
                            if (!$job) {
                                throw new \Exception('Job posting not found.');
                            }

                            // Initialize Stripe
                            \Stripe\Stripe::setApiKey(\craft\helpers\App::env('STRIPE_SECRET_KEY'));

                            // Create payment intent
                            $paymentIntent = \Stripe\PaymentIntent::create([
                                'amount' => $amount * 100, // Convert to cents
                                'currency' => 'usd',
                                'payment_method' => $paymentMethodId,
                                'confirmation_method' => 'manual',
                                'confirm' => true,
                                'return_url' => \craft\helpers\UrlHelper::siteUrl('jobs/payment-success'),
                                'metadata' => [
                                    'job_id' => $job->id,
                                    'job_title' => $job->title,
                                    'duration' => $duration,
                                    'user_id' => Craft::$app->getUser()->getId(),
                                ],
                            ]);

                            // Handle payment intent status
                            if ($paymentIntent->status === 'requires_action' &&
                                $paymentIntent->next_action->type === 'use_stripe_sdk') {

                                // 3D Secure authentication required
                                return $this->asJson([
                                    'requires_action' => true,
                                    'payment_intent' => [
                                        'id' => $paymentIntent->id,
                                        'client_secret' => $paymentIntent->client_secret
                                    ]
                                ]);

                            } elseif ($paymentIntent->status === 'succeeded') {

                                // Payment successful - activate job
                                $this->activateJobAfterPayment($job, $amount, $duration, 'credit', $paymentIntent);

                                $session->setNotice('Payment successful! Your job posting has been published.');
                                return $this->redirect($job->getUrl());

                            } else {
                                throw new \Exception('Payment failed. Status: ' . $paymentIntent->status);
                            }

                        } catch (\Stripe\Exception\CardException $e) {
                            $session->setError('Payment failed: ' . $e->getError()->message);
                            return $this->redirectToPostedUrl();
                        } catch (\Exception $e) {
                            Craft::error('Stripe payment error: ' . $e->getMessage(), __METHOD__);
                            $session->setError('Payment processing failed: ' . $e->getMessage());
                            return $this->redirectToPostedUrl();
                        }
                    }

                    /**
                     * Activate job after successful payment
                     */
                    private function activateJobAfterPayment($job, $amount, $duration, $paymentMethod, $paymentIntent = null)
                    {
                        // Activate the job (set enabled = true)
                        $job->enabled = true;

                        // Mark as paid
                        $job->setFieldValue('paid', true);

                        // Set expiration date based on duration
                        $expiryDate = new DateTime();
                        $expiryDate->add(new DateInterval('P' . $duration . 'M')); // Add months
                        $job->expiryDate = $expiryDate;

                        // Save payment information in a custom field or note
                        // You could add a custom field for payment tracking if needed

                        // Save the job
                        if (Craft::$app->getElements()->saveElement($job)) {
                            // Clear the pending job from session
                            Craft::$app->getSession()->remove('pendingJobId');

                            // Send notifications
                            $this->sendJobPostingNotifications($job, $amount, $duration, $paymentMethod, $paymentIntent);

                            return true;
                        }

                        return false;
                    }

                    /**
                     * Send notification emails for new job postings
                     */
                    private function sendJobPostingNotifications($job, $amount, $duration, $paymentMethod, $paymentIntent = null)
                    {
                        $resolveAdminRecipients = function(): array {
                            $env = trim((string)\craft\helpers\App::env('ADMIN_NOTIFY_EMAILS'));
                            if ($env !== '') {
                                return array_values(array_filter(array_map('trim', explode(',', $env))));
                            }
                            $systemEmail = trim((string)\craft\helpers\App::env('SYSTEM_EMAIL'));
                            if ($systemEmail !== '') {
                                return [$systemEmail];
                            }
                            return ['ed@theblueocean.com'];
                        };

                        $adminEmails = $resolveAdminRecipients();
                        if (!$adminEmails) {
                            return;
                        }

                        $user = Craft::$app->getUser()->getIdentity();
                        $organization = $user->organization ?? null;

                        $subject = 'New Job Posting: ' . $job->title;
                        $body = "A new job posting has been created and published:\n\n";
                        $body .= "Job Title: {$job->title}\n";
                        $body .= "Organization: " . ($organization ? $organization->title : 'Unknown') . "\n";
                        $body .= "Posted by: {$user->fullName} ({$user->email})\n";
                        $body .= "Duration: {$duration} month(s)\n";
                        $body .= "Payment: \${$amount} via {$paymentMethod}\n";
                        $body .= "Job URL: " . ($job->getUrl() ?? 'URL not available') . "\n\n";

                        if ($paymentMethod === 'invoice') {
                            $body .= "NOTE: This was an invoice posting - please send invoice for \${$amount}.\n";
                        } elseif ($paymentMethod === 'credit' && $paymentIntent) {
                            $body .= "Payment processed via Stripe (ID: {$paymentIntent->id})\n";
                        }

                        $message = new \craft\mail\Message();
                        $message->setTo($adminEmails)
                               ->setSubject($subject)
                               ->setTextBody($body);

                        try {
                            Craft::$app->getMailer()->send($message);
                        } catch (\Exception $e) {
                            Craft::error('Failed to send job posting notification: ' . $e->getMessage(), __METHOD__);
                        }
                    }
                }
            }

            // Register the custom controller
            Event::on(
                \craft\web\Application::class,
                \craft\web\Application::EVENT_INIT,
                static function() {
                    // Register custom controller actions
                    $urlManager = Craft::$app->getUrlManager();
                    $urlManager->addRules([
                        'actions/entries/activate-job-posting' => 'custom/job-posting/activate-job-posting',
                        'actions/entries/process-stripe-payment' => 'custom/job-posting/process-stripe-payment',
                    ]);

                    // Register the controller class
                    Craft::$app->controllerMap['custom/job-posting'] = JobPostingController::class;
                }
            );

            // Store job ID in session after job entry is created (for payment flow)
            Event::on(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                static function(ElementEvent $e) {
                    if (!$e->isNew) {
                        return;
                    }

                    $el = $e->element;
                    if (!$el instanceof EntryElement) {
                        return;
                    }

                    $section = $el->getSection();
                    if (!$section || $section->handle !== 'jobs') {
                        return;
                    }

                    // Only for site requests (not CP)
                    if (!Craft::$app->getRequest()->getIsSiteRequest()) {
                        return;
                    }

                    $request = Craft::$app->getRequest();
                    $submitType = $request->getBodyParam('submitType', 'payment');

                    // If saving as draft, set success message but don't redirect here
                    if ($submitType === 'draft') {
                        Craft::$app->getSession()->setNotice('Job posting saved as draft. You can complete it later from your profile.');
                        return; // Let Craft handle the redirect to profile page
                    }

                    // Store the job ID in session for payment processing
                    Craft::$app->getSession()->set('pendingJobId', $el->id);
                }
            );

            // --- END: Job posting payment and activation handlers ---
        },
    ],
];
