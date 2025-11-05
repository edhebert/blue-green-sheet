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
                    Craft::$app->getSession()->setNotice('Welcome to BlueGreen Sheet! Your account is now active. Please login below to complete your profile.');
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
                                $session->setNotice('Your job posting has been published! An invoice for $' . $amount . ' will soon be sent to your email from a member of the BlueGreen Sheet staff.');
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
                     * Handle successful Stripe Checkout - activate job after payment
                     */
                    public function actionPaymentSuccess()
                    {
                        $this->requireLogin();

                        $request = Craft::$app->getRequest();
                        $session = Craft::$app->getSession();

                        try {
                            // Get job ID from URL parameters
                            $jobId = $request->getParam('jobId');

                            Craft::info("Stripe payment success callback - JobID: {$jobId}", __METHOD__);

                            if (!$jobId) {
                                throw new \Exception('Missing job ID.');
                            }

                            // Load the job entry (including disabled/draft entries)
                            $job = \craft\elements\Entry::find()
                                ->id($jobId)
                                ->status(null)
                                ->one();

                            if (!$job) {
                                Craft::error("Job not found with ID: {$jobId}", __METHOD__);
                                throw new \Exception('Job posting not found.');
                            }

                            // Verify the current user owns this job
                            $currentUser = Craft::$app->getUser()->getIdentity();
                            if ($job->authorId != $currentUser->id && !$currentUser->admin) {
                                throw new \Exception('You do not have permission to modify this job posting.');
                            }

                            // Check if job is already paid (prevent double processing)
                            if ($job->paid) {
                                Craft::info("Job {$jobId} already marked as paid", __METHOD__);
                                $session->setNotice('This job posting has already been activated.');
                                return $this->redirect('profile');
                            }

                            // Determine duration and amount - we need to get this from the Stripe checkout session
                            // For now, check which price was used by looking at the session_id parameter
                            $sessionId = $request->getParam('session_id');
                            $duration = 6; // Default
                            $amount = 300; // Default

                            if ($sessionId) {
                                try {
                                    // Retrieve checkout session from Stripe to get the price details
                                    \Stripe\Stripe::setApiKey(\craft\helpers\App::env('STRIPE_SECRET_KEY'));
                                    $checkoutSession = \Stripe\Checkout\Session::retrieve([
                                        'id' => $sessionId,
                                        'expand' => ['line_items'],
                                    ]);

                                    if ($checkoutSession->payment_status === 'paid') {
                                        // Get the price from line items
                                        if (!empty($checkoutSession->line_items->data)) {
                                            $priceId = $checkoutSession->line_items->data[0]->price->id;
                                            $amountInCents = $checkoutSession->line_items->data[0]->amount_total;
                                            $amount = $amountInCents / 100;

                                            // Determine duration based on amount
                                            if ($amount == 400) {
                                                $duration = 12;
                                            } else {
                                                $duration = 6;
                                            }

                                            Craft::info("Payment verified via Stripe - Amount: \${$amount}, Duration: {$duration} months", __METHOD__);
                                        }
                                    } else {
                                        throw new \Exception('Payment was not completed.');
                                    }
                                } catch (\Stripe\Exception\ApiErrorException $e) {
                                    Craft::error('Stripe API error retrieving session: ' . $e->getMessage(), __METHOD__);
                                    // Continue with defaults if we can't verify
                                }
                            }

                            // Activate the job (set enabled = true)
                            $job->enabled = true;

                            // Mark as paid
                            $job->setFieldValue('paid', true);

                            // Set payment type to 'stripe'
                            $job->setFieldValue('paymentType', 'stripe');

                            // Save Stripe session ID for transaction tracking
                            if ($sessionId) {
                                $job->setFieldValue('stripeSessionId', $sessionId);
                            }

                            // Set expiration date based on duration
                            $expiryDate = new DateTime();
                            $expiryDate->add(new DateInterval('P' . $duration . 'M')); // Add months
                            $job->expiryDate = $expiryDate;

                            // Save the job
                            if (Craft::$app->getElements()->saveElement($job)) {
                                // Send notifications
                                $this->sendJobPostingNotifications($job, $amount, $duration, 'stripe');

                                Craft::info("Stripe payment successful - Job {$jobId} activated", __METHOD__);
                                $session->setNotice('Payment successful! Your job posting has been published.');
                                return $this->redirect('profile');
                            } else {
                                $errors = $job->getErrors();
                                $errorMessage = 'Failed to publish your job posting.';
                                if (!empty($errors)) {
                                    $errorMessage .= ' Errors: ' . implode(', ', array_map(function($err) {
                                        return is_array($err) ? implode(', ', $err) : $err;
                                    }, $errors));
                                }
                                throw new \Exception($errorMessage);
                            }

                        } catch (\Exception $e) {
                            Craft::error('Stripe payment success error: ' . $e->getMessage(), __METHOD__);
                            $session->setError('An error occurred verifying your payment: ' . $e->getMessage());
                            return $this->redirect('profile');
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
                            $adminSubject = 'Invoice Request - New Job Posting: ' . $job->title;
                        } else {
                            $adminSubject = 'New Job Posting: ' . $job->title;
                        }

                        // Build admin notification email body
                        $adminHtmlBody = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';

                        if ($paymentMethod === 'invoice') {
                            $adminHtmlBody .= '<h2 style="color: #d9534f;">Invoice Request - New Job Posting</h2>';
                            $adminHtmlBody .= '<p>A new job posting has been submitted with invoice payment:</p>';
                        } else {
                            $adminHtmlBody .= '<h2 style="color: #5cb85c;">New Job Posting</h2>';
                            $adminHtmlBody .= '<p>A new job posting has been created and paid:</p>';
                        }

                        $adminHtmlBody .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Job Title:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($job->title) . '</td></tr>';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>School:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($school ? htmlspecialchars($school->title) : 'Not specified') . '</td></tr>';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Posted by Organization:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($organization ? htmlspecialchars($organization->title) : 'Unknown') . '</td></tr>';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Posted by User:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($user->fullName) . ' (' . htmlspecialchars($user->email) . ')</td></tr>';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Duration:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $duration . ' month(s)</td></tr>';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Amount:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">$' . $amount . '</td></tr>';
                        $adminHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Payment Method:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ucfirst($paymentMethod) . '</td></tr>';
                        $adminHtmlBody .= '</table>';

                        // Add action links for admin
                        $jobUrl = $job->getUrl() ?? '';
                        $editUrl = \craft\helpers\UrlHelper::cpUrl('entries/jobs/' . $job->id);

                        $adminHtmlBody .= '<p style="margin: 20px 0;">';
                        if ($jobUrl) {
                            $adminHtmlBody .= '<a href="' . htmlspecialchars($jobUrl) . '" style="display: inline-block; padding: 10px 20px; margin-right: 10px; background-color: #5cb85c; color: white; text-decoration: none; border-radius: 4px;">Visit public-facing job URL</a>';
                        }
                        $adminHtmlBody .= '<a href="' . htmlspecialchars($editUrl) . '" style="display: inline-block; padding: 10px 20px; background-color: #337ab7; color: white; text-decoration: none; border-radius: 4px;">Edit this job in the BGS Control Panel</a>';
                        $adminHtmlBody .= '</p>';

                        if ($paymentMethod === 'invoice') {
                            $adminHtmlBody .= '<div style="background-color: #fcf8e3; border: 2px solid #d9534f; padding: 15px; margin: 20px 0; border-radius: 4px;">';
                            $adminHtmlBody .= '<h3 style="color: #d9534f; margin-top: 0;">ACTION REQUIRED</h3>';
                            $adminHtmlBody .= '<p><strong>Please send an invoice for $' . $amount . ' to ' . htmlspecialchars($user->email) . '</strong></p>';
                            $adminHtmlBody .= '</div>';
                            $adminHtmlBody .= '<p>The job posting is now live and will expire in ' . $duration . ' month(s).</p>';
                        } elseif ($paymentMethod === 'credit' && $paymentIntent) {
                            $adminHtmlBody .= '<p><strong>Payment processed via Stripe</strong><br>';
                            $adminHtmlBody .= 'Payment Intent ID: ' . htmlspecialchars($paymentIntent->id) . '</p>';
                        }

                        $adminHtmlBody .= '</body></html>';

                        // Send admin notification
                        $adminMessage = new \craft\mail\Message();
                        $adminMessage->setTo($adminEmails)
                                    ->setSubject($adminSubject)
                                    ->setHtmlBody($adminHtmlBody);

                        try {
                            Craft::$app->getMailer()->send($adminMessage);
                        } catch (\Exception $e) {
                            Craft::error('Failed to send admin job posting notification: ' . $e->getMessage(), __METHOD__);
                        }

                        // Send jobPoster confirmation email
                        if ($user && $user->email) {
                            $posterSubject = 'Your Job Posting is Now Live: ' . $job->title;

                            $posterHtmlBody = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';
                            $posterHtmlBody .= '<h2 style="color: #5cb85c;">Your Job Posting is Now Live!</h2>';
                            $posterHtmlBody .= '<p>Dear ' . htmlspecialchars($user->firstName) . ',</p>';
                            $posterHtmlBody .= '<p>Thank you for posting your job listing to the BlueGreen Sheet job board! We are excited to help you find the perfect candidate for your school.</p>';

                            $posterHtmlBody .= '<p><strong>Job Posting Details:</strong></p>';
                            $posterHtmlBody .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
                            $posterHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Job Title:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . htmlspecialchars($job->title) . '</td></tr>';
                            $posterHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>School:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($school ? htmlspecialchars($school->title) : 'Not specified') . '</td></tr>';
                            $posterHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Duration:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . $duration . ' month(s)</td></tr>';
                            $posterHtmlBody .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd;"><strong>Posting Cost:</strong></td><td style="padding: 8px; border-bottom: 1px solid #ddd;">$' . $amount . '</td></tr>';
                            $posterHtmlBody .= '</table>';

                            // Add public-facing job URL button
                            if ($jobUrl) {
                                $posterHtmlBody .= '<p style="margin: 20px 0; text-align: center;">';
                                $posterHtmlBody .= '<a href="' . htmlspecialchars($jobUrl) . '" style="display: inline-block; padding: 12px 30px; background-color: #5cb85c; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">View Your Job Posting</a>';
                                $posterHtmlBody .= '</p>';
                            }

                            $posterHtmlBody .= '<p>Your job posting will remain active for ' . $duration . ' month(s) from the date of posting. Candidates can view your job and apply directly through the BlueGreen Sheet platform.</p>';

                            if ($paymentMethod === 'invoice') {
                                $posterHtmlBody .= '<p>You selected invoice payment. An invoice for $' . $amount . ' will be sent to you shortly from our billing team.</p>';
                            }

                            $posterHtmlBody .= '<p>If you have any questions or need assistance, please do not hesitate to reach out to our support team.</p>';
                            $posterHtmlBody .= '<p>Best regards,<br><strong>The BlueGreen Sheet Team</strong></p>';
                            $posterHtmlBody .= '</body></html>';

                            $posterMessage = new \craft\mail\Message();
                            $posterMessage->setTo($user->email)
                                         ->setSubject($posterSubject)
                                         ->setHtmlBody($posterHtmlBody);

                            try {
                                Craft::$app->getMailer()->send($posterMessage);
                            } catch (\Exception $e) {
                                Craft::error('Failed to send job poster confirmation email: ' . $e->getMessage(), __METHOD__);
                            }
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
