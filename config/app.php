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
                return ['ed+bgs@theblueocean.com']; // <-- update this if you don't set env vars
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

                    // Build HTML email body
                    $htmlBody = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';
                    $htmlBody .= '<h2 style="color: #5cb85c;">New User Activated</h2>';
                    $htmlBody .= '<p>A new user has activated their account:</p>';

                    $htmlBody .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
                    $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Email:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($user->email) . '</td></tr>';
                    $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Name:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($user->fullName) . '</td></tr>';
                    $htmlBody .= '</table>';

                    // Add link to view user in control panel
                    $userCpUrl = \craft\helpers\UrlHelper::cpUrl('users/' . $user->id);
                    $htmlBody .= '<p style="margin: 20px 0;">';
                    $htmlBody .= '<a href="' . htmlspecialchars($userCpUrl) . '" style="display: inline-block; padding: 10px 20px; background-color: #337ab7; color: white; text-decoration: none; border-radius: 4px;">View user in the BGS Control Panel</a>';
                    $htmlBody .= '</p>';

                    $htmlBody .= '</body></html>';

                    $msg = (new Message())
                        ->setTo($to)
                        ->setSubject('New user activated')
                        ->setHtmlBody($htmlBody);
                    Craft::$app->getMailer()->send($msg);

                    // Set welcome message for the user
                    Craft::$app->getSession()->setNotice('Welcome to Blue Green Sheet! Your account is now active. Please login below to complete your profile.');
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

                    // Build HTML email body
                    $htmlBody = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';
                    $htmlBody .= '<h2 style="color: #5cb85c;">New Organization Added</h2>';
                    $htmlBody .= '<p>A new organization entry was created:</p>';

                    $htmlBody .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
                    $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Organization Name:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($el->title) . '</td></tr>';
                    $htmlBody .= '</table>';

                    // Add action links
                    $htmlBody .= '<p style="margin: 20px 0;">';
                    if ($publicUrl) {
                        $htmlBody .= '<a href="' . htmlspecialchars($publicUrl) . '" style="display: inline-block; padding: 10px 20px; margin-right: 10px; background-color: #5cb85c; color: white; text-decoration: none; border-radius: 4px;">Visit public-facing organization page</a>';
                    }
                    $htmlBody .= '<a href="' . htmlspecialchars($cpUrl) . '" style="display: inline-block; padding: 10px 20px; background-color: #337ab7; color: white; text-decoration: none; border-radius: 4px;">Edit this organization in the BGS Control Panel</a>';
                    $htmlBody .= '</p>';

                    $htmlBody .= '</body></html>';

                    $msg = (new Message())
                        ->setTo($to)
                        ->setSubject('New organization added')
                        ->setHtmlBody($htmlBody);
                    Craft::$app->getMailer()->send($msg);

                    // Set thank you message for the user who created the organization
                    $currentUser = Craft::$app->getUser()->getIdentity();
                    if ($currentUser && $el->authorId == $currentUser->id) {
                        $session = Craft::$app->getSession();
                        // Clear any default Craft messages
                        $session->removeFlash('notice');
                        $session->removeFlash('success');
                        // Set our custom message
                        $session->setNotice('Thank you for adding ' . $el->title . '! This organization is now connected to your profile and you can start posting jobs.');
                    }
                }
            );

            // C) Set welcome message on first login (when user has no organization yet)
            Event::on(
                \craft\web\User::class,
                \craft\web\User::EVENT_AFTER_LOGIN,
                static function(\yii\web\UserEvent $e) {
                    $user = $e->identity;

                    // Check if user has no organization assigned yet (first-time login)
                    if ($user && !$user->organization->one()) {
                        $session = Craft::$app->getSession();
                        $session->setNotice('Login successful! Now select or create your organization to complete your profile.');
                    }
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

                        try {
                            // Get form data
                            $jobId = $request->getBodyParam('jobId');
                            $duration = $request->getBodyParam('duration');
                            $paymentMethod = $request->getBodyParam('paymentMethod');

                            Craft::info("Invoice payment started - JobID: {$jobId}, Duration: {$duration}, Method: {$paymentMethod}", __METHOD__);

                            if (!$jobId || !$duration || !$paymentMethod) {
                                $session->setError('Missing required parameters: jobId, duration, or paymentMethod');
                                Craft::error("Missing params - JobID: {$jobId}, Duration: {$duration}, Method: {$paymentMethod}", __METHOD__);
                                return $this->redirectToPostedUrl();
                            }

                            // Calculate amount based on duration
                            $amount = ($duration == 12) ? 400 : 300;

                            // Load the job entry (including disabled/draft entries)
                            $job = \craft\elements\Entry::find()
                                ->id($jobId)
                                ->status(null)
                                ->one();

                            if (!$job) {
                                Craft::error("Job not found with ID: {$jobId}", __METHOD__);
                                $session->setError('Job posting not found.');
                                return $this->redirectToPostedUrl();
                            }

                            Craft::info("Job found: {$job->title} (ID: {$jobId})", __METHOD__);

                        // Verify the current user owns this job
                        $currentUser = Craft::$app->getUser()->getIdentity();
                        if ($job->authorId != $currentUser->id && !$currentUser->admin) {
                            $session->setError('You do not have permission to modify this job posting.');
                            return $this->redirectToPostedUrl();
                        }

                        // Activate the job (set enabled = true)
                        $job->enabled = true;

                        // Mark as paid
                        $job->setFieldValue('paid', true);

                        // Set payment type to 'invoice'
                        $job->setFieldValue('paymentType', 'invoice');

                        // Set expiration date based on duration
                        $expiryDate = new DateTime();
                        $expiryDate->add(new DateInterval('P' . $duration . 'M')); // Add months
                        $job->expiryDate = $expiryDate;

                            // Save the job
                            if (Craft::$app->getElements()->saveElement($job)) {
                                // Send notifications
                                $this->sendJobPostingNotifications($job, $amount, $duration, $paymentMethod);

                                Craft::info("Invoice payment successful - Job {$jobId} activated", __METHOD__);
                                $session->setNotice('Your job posting has been published! An invoice for $' . $amount . ' will soon be sent to your email from a member of the Blue Green Sheet staff.');
                                return $this->redirectToPostedUrl();
                            } else {
                                $errors = $job->getErrors();
                                $errorMessage = 'Failed to publish your job posting.';
                                if (!empty($errors)) {
                                    $errorMessage .= ' Errors: ' . implode(', ', array_map(function($err) {
                                        return is_array($err) ? implode(', ', $err) : $err;
                                    }, $errors));
                                }
                                Craft::error("Failed to save job {$jobId}: {$errorMessage}", __METHOD__);
                                $session->setError($errorMessage);
                                return $this->redirectToPostedUrl();
                            }
                        } catch (\Exception $e) {
                            Craft::error("Invoice payment error: " . $e->getMessage(), __METHOD__);
                            $session->setError('An error occurred processing your payment: ' . $e->getMessage());
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
                        $organization = $user->organization->one() ?? null;
                        $school = $job->school->one() ?? null;

                        if ($paymentMethod === 'invoice') {
                            $subject = 'Invoice Request - New Job Posting: ' . $job->title;
                        } else {
                            $subject = 'New Job Posting: ' . $job->title;
                        }

                        // Build HTML email body
                        $htmlBody = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';

                        if ($paymentMethod === 'invoice') {
                            $htmlBody .= '<h2 style="color: #d9534f;">Invoice Request - New Job Posting</h2>';
                            $htmlBody .= '<p>A new job posting has been submitted with invoice payment:</p>';
                        } else {
                            $htmlBody .= '<h2 style="color: #5cb85c;">New Job Posting</h2>';
                            $htmlBody .= '<p>A new job posting has been created and paid:</p>';
                        }

                        $htmlBody .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Job Title:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($job->title) . '</td></tr>';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>School:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($school ? htmlspecialchars($school->title) : 'Not specified') . '</td></tr>';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Posted by Organization:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($organization ? htmlspecialchars($organization->title) : 'Unknown') . '</td></tr>';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Posted by User:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($user->fullName) . ' (' . htmlspecialchars($user->email) . ')</td></tr>';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Duration:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $duration . ' month(s)</td></tr>';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Amount:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">$' . $amount . '</td></tr>';
                        $htmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Payment Method:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ucfirst($paymentMethod) . '</td></tr>';
                        $htmlBody .= '</table>';

                        // Add action links
                        $jobUrl = $job->getUrl() ?? '';
                        $editUrl = \craft\helpers\UrlHelper::cpUrl('entries/jobs/' . $job->id);

                        $htmlBody .= '<p style="margin: 20px 0;">';
                        if ($jobUrl) {
                            $htmlBody .= '<a href="' . htmlspecialchars($jobUrl) . '" style="display: inline-block; padding: 10px 20px; margin-right: 10px; background-color: #5cb85c; color: white; text-decoration: none; border-radius: 4px;">Visit public-facing job URL</a>';
                        }
                        $htmlBody .= '<a href="' . htmlspecialchars($editUrl) . '" style="display: inline-block; padding: 10px 20px; background-color: #337ab7; color: white; text-decoration: none; border-radius: 4px;">Edit this job in the BGS Control Panel</a>';
                        $htmlBody .= '</p>';

                        if ($paymentMethod === 'invoice') {
                            $htmlBody .= '<div style="background-color: #fcf8e3; border: 2px solid #d9534f; padding: 15px; margin: 20px 0; border-radius: 4px;">';
                            $htmlBody .= '<h3 style="color: #d9534f; margin-top: 0;">ACTION REQUIRED</h3>';
                            $htmlBody .= '<p><strong>Please send an invoice for $' . $amount . ' to ' . htmlspecialchars($user->email) . '</strong></p>';
                            $htmlBody .= '</div>';
                            $htmlBody .= '<p>The job posting is now live and will expire in ' . $duration . ' month(s).</p>';
                        } elseif ($paymentMethod === 'credit' && $paymentIntent) {
                            $htmlBody .= '<p><strong>Payment processed via Stripe</strong><br>';
                            $htmlBody .= 'Payment Intent ID: ' . htmlspecialchars($paymentIntent->id) . '</p>';
                        }

                        $htmlBody .= '</body></html>';

                        $message = new \craft\mail\Message();
                        $message->setTo($adminEmails)
                               ->setSubject($subject)
                               ->setHtmlBody($htmlBody);

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
                    // Register the controller class with a simple namespace
                    Craft::$app->controllerMap['job-posting'] = JobPostingController::class;
                }
            );

            // Auto-generate job slug from school + title
            Event::on(
                Elements::class,
                Elements::EVENT_BEFORE_SAVE_ELEMENT,
                static function(ElementEvent $e) {
                    $el = $e->element;

                    // Only process Job entries
                    if (!$el instanceof EntryElement) {
                        return;
                    }

                    $section = $el->getSection();
                    if (!$section || $section->handle !== 'jobs') {
                        return;
                    }

                    // Get the school relation
                    $school = $el->school->one();
                    if (!$school) {
                        // No school selected - let Craft generate slug normally from title
                        return;
                    }

                    // Build slug from school slug + entry title
                    $schoolSlug = $school->slug ?? '';

                    // Double-check that school slug exists and isn't empty
                    if (empty($schoolSlug)) {
                        // No valid school slug - let Craft generate slug normally from title
                        return;
                    }

                    $titleSlug = \craft\helpers\ElementHelper::generateSlug($el->title);

                    if ($titleSlug) {
                        $el->slug = $schoolSlug . '_' . $titleSlug;
                    }
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
