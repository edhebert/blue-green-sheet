#!/usr/bin/env php
<?php
/**
 * Simplified script to create basic Formie job posting form
 * We'll create the form structure and then manually configure fields
 */

// Bootstrap Craft
define('CRAFT_BASE_PATH', __DIR__ . '/..');
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use verbb\formie\elements\Form;
use verbb\formie\models\FormSettings;
use verbb\formie\Formie;

class SimpleJobFormCreator
{
    public function run()
    {
        echo "Creating basic Job Posting Form...\n";
        echo str_repeat("=", 50) . "\n";

        try {
            // Check if form already exists
            $existingForm = Form::find()->handle('jobPosting')->one();
            if ($existingForm) {
                echo "Form 'jobPosting' already exists with ID: " . $existingForm->id . "\n";
                echo "Delete it first if you want to recreate it.\n";
                return;
            }

            // Create basic form
            $form = new Form();
            $form->title = 'Job Posting Form';
            $form->handle = 'jobPosting';

            // Configure basic settings
            $settings = new FormSettings();
            $settings->submitMethod = 'page-reload';
            $settings->submitAction = 'message';
            $settings->submitActionMessage = 'Thank you! Your job posting has been submitted successfully.';
            $settings->requireUser = true;
            $settings->requireUserMessage = 'You must be logged in to post a job.';

            $form->settings = $settings;

            // Save the form
            if (Craft::$app->elements->saveElement($form)) {
                echo "✅ SUCCESS: Basic job posting form created!\n";
                echo "Form ID: " . $form->id . "\n";
                echo "Form Handle: jobPosting\n";
                echo "\n";
                echo "🔧 NEXT STEPS:\n";
                echo "1. Go to Control Panel > Formie > Forms\n";
                echo "2. Edit the 'Job Posting Form'\n";
                echo "3. Add the following fields manually:\n";
                echo "   - Job Title (Single Line Text, required)\n";
                echo "   - Job Category (Categories, required)\n";
                echo "   - Job Description (Rich Text, required)\n";
                echo "   - Opportunity Statement (Rich Text)\n";
                echo "   - Salary Low/High (Single Line Text)\n";
                echo "   - School URL (Single Line Text)\n";
                echo "   - Application Instructions (Multi Line Text, required)\n";
                echo "   - Country (Dropdown: US/International, required)\n";
                echo "   - City, State, Zip (Single Line Text, conditional on US)\n";
                echo "   - Location (Single Line Text, conditional on International)\n";
                echo "   - Posting Duration (Radio: 6mo/$300, 12mo/$400, required)\n";
                echo "   - Payment Method (Radio: Credit Card, Invoice, required)\n";
                echo "\n";
                echo "4. Set up conditional logic for location fields\n";
                echo "5. Configure Stripe integration for credit card payments\n";
                echo "6. Set up staff email notifications\n";

            } else {
                echo "❌ ERROR: Failed to save form\n";
                foreach ($form->getErrors() as $attribute => $errors) {
                    echo "  $attribute: " . implode(', ', $errors) . "\n";
                }
            }

        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
        }
    }
}

// Run the creator
$creator = new SimpleJobFormCreator();
$creator->run();