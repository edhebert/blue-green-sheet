#!/usr/bin/env php
<?php
/**
 * Script to create Formie job posting form programmatically
 *
 * This script creates a complete job posting form with:
 * - All job fields matching the jobs section
 * - Conditional location logic (US vs International)
 * - Payment options (Credit Card vs Invoice)
 * - Duration selection (6mo vs 12mo)
 * - Staff notification system
 */

// Bootstrap Craft
define('CRAFT_BASE_PATH', __DIR__ . '/..');
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\models\FormTemplate;
use verbb\formie\models\EmailTemplate;
use verbb\formie\models\Notification;
use verbb\formie\models\FormSettings;
use verbb\formie\fields\formfields\SingleLineText;
use verbb\formie\fields\formfields\MultiLineText;
use verbb\formie\fields\formfields\RichText;
use verbb\formie\fields\formfields\Dropdown;
use verbb\formie\fields\formfields\Radio;
use verbb\formie\fields\formfields\Categories;
use verbb\formie\fields\formfields\Entries;
use verbb\formie\fields\formfields\Number;
use verbb\formie\fields\formfields\Hidden;
use verbb\formie\fields\formfields\Group;
use verbb\formie\fields\formfields\Html;
use verbb\formie\Formie;

class JobPostingFormCreator
{
    private $form;

    public function run()
    {
        echo "Creating Job Posting Form...\n";
        echo str_repeat("=", 50) . "\n";

        try {
            $this->createForm();
            $this->configureSettings();
            $this->createFields();
            $this->setupNotifications();
            $this->saveForm();

            echo "\n✅ SUCCESS: Job posting form created!\n";
            echo "Form Handle: job-posting\n";
            echo "Form ID: " . $this->form->id . "\n";
            echo "You can access it at: /forms/job-posting\n";

        } catch (Exception $e) {
            echo "\n❌ ERROR: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }
    }

    private function createForm()
    {
        echo "Creating form structure...\n";

        $this->form = new Form();
        $this->form->title = 'Job Posting Form';
        $this->form->handle = 'job-posting';

        $settings = new FormSettings();
        $settings->submitMethod = 'page-reload';
        $settings->submitAction = 'message';
        $settings->submitActionMessage = 'Thank you! Your job posting has been submitted successfully.';
        $settings->loadingIndicator = 'spinner';
        $settings->loadingIndicatorText = 'Processing your job posting...';
        $settings->validationOnSubmit = true;
        $settings->validationOnFocus = false;
        $settings->errorMessage = 'Please fix the errors below and try again.';
        $settings->redirectUrl = '';
        $settings->requireUser = true;
        $settings->requireUserMessage = 'You must be logged in to post a job.';

        $this->form->settings = $settings;
    }

    private function configureSettings()
    {
        echo "Configuring form settings...\n";

        // Set up form template (using default)
        $formTemplate = Formie::$plugin->getFormTemplates()->getTemplateByHandle('default');
        if ($formTemplate) {
            $this->form->templateId = $formTemplate->id;
        }
    }

    private function createFields()
    {
        echo "Creating form fields...\n";

        $fields = [];

        // Page 1: Job Information
        $page1 = [
            'label' => 'Job Information',
            'rows' => []
        ];

        // Row 1: Job Title
        $page1['rows'][] = [
            'fields' => [
                $this->createSingleLineText([
                    'label' => 'Job Title',
                    'handle' => 'jobTitle',
                    'required' => true,
                    'placeholder' => 'e.g., Head of School, Director of Admissions',
                    'instructions' => 'Enter the official job title for this position.'
                ])
            ]
        ];

        // Row 2: Job Category and Region (side by side)
        $page1['rows'][] = [
            'fields' => [
                $this->createCategories([
                    'label' => 'Job Category',
                    'handle' => 'jobCategory',
                    'required' => true,
                    'source' => 'categoryGroup:' . $this->getCategoryGroupUid('jobCategories'),
                    'instructions' => 'Select the category that best describes this position.'
                ]),
                $this->createHidden([
                    'handle' => 'jobRegion',
                    'defaultValue' => '', // Will be populated by custom logic
                ])
            ]
        ];

        // Row 3: Organization (auto-populated, hidden)
        $page1['rows'][] = [
            'fields' => [
                $this->createHidden([
                    'handle' => 'organization',
                    'defaultValue' => '{user:organization}', // Will be populated by custom handler
                ])
            ]
        ];

        // Row 4: Job Description
        $page1['rows'][] = [
            'fields' => [
                $this->createRichText([
                    'label' => 'Job Description',
                    'handle' => 'jobDescription',
                    'required' => true,
                    'instructions' => 'Provide a detailed description of the position, responsibilities, and requirements.'
                ])
            ]
        ];

        // Row 5: Opportunity Statement
        $page1['rows'][] = [
            'fields' => [
                $this->createRichText([
                    'label' => 'Opportunity Statement',
                    'handle' => 'jobOpportunityStatement',
                    'required' => false,
                    'instructions' => 'A brief, compelling summary of what makes this opportunity special.'
                ])
            ]
        ];

        // Row 6: Salary Range
        $page1['rows'][] = [
            'fields' => [
                $this->createSingleLineText([
                    'label' => 'Salary Range - Low (in thousands)',
                    'handle' => 'jobSalaryLow',
                    'required' => false,
                    'placeholder' => 'e.g., 75',
                    'instructions' => 'Enter the minimum salary in thousands (optional).'
                ]),
                $this->createSingleLineText([
                    'label' => 'Salary Range - High (in thousands)',
                    'handle' => 'jobSalaryHigh',
                    'required' => false,
                    'placeholder' => 'e.g., 100',
                    'instructions' => 'Enter the maximum salary in thousands (optional).'
                ])
            ]
        ];

        // Row 7: School Website
        $page1['rows'][] = [
            'fields' => [
                $this->createSingleLineText([
                    'label' => 'School/Organization Website',
                    'handle' => 'jobSchoolUrl',
                    'required' => false,
                    'placeholder' => 'https://example.com',
                    'instructions' => 'Website URL for the hiring organization.'
                ])
            ]
        ];

        // Row 8: Application Instructions
        $page1['rows'][] = [
            'fields' => [
                $this->createMultiLineText([
                    'label' => 'Application Instructions',
                    'handle' => 'jobApplicationInstructions',
                    'required' => true,
                    'instructions' => 'Provide clear instructions on how candidates should apply for this position.'
                ])
            ]
        ];

        // Row 9: Country Selection (for location logic)
        $page1['rows'][] = [
            'fields' => [
                $this->createDropdown([
                    'label' => 'Country',
                    'handle' => 'country',
                    'required' => true,
                    'options' => [
                        ['label' => '— Select Country —', 'value' => ''],
                        ['label' => 'United States', 'value' => 'unitedStates'],
                        ['label' => 'International', 'value' => 'international'],
                    ],
                    'instructions' => 'Select the country where this job is located.'
                ])
            ]
        ];

        // Row 10: US Location Fields (conditional)
        $page1['rows'][] = [
            'fields' => [
                $this->createSingleLineText([
                    'label' => 'City',
                    'handle' => 'jobCity',
                    'required' => false, // Will be conditionally required
                    'placeholder' => 'e.g., Boston',
                    'enableConditions' => true,
                    'conditions' => [
                        'showRule' => 'show',
                        'conditionRule' => 'all',
                        'conditions' => [
                            [
                                'field' => 'country',
                                'condition' => '=',
                                'value' => 'unitedStates'
                            ]
                        ]
                    ]
                ]),
                $this->createDropdown([
                    'label' => 'State',
                    'handle' => 'jobState',
                    'required' => false, // Will be conditionally required
                    'options' => $this->getStateOptions(),
                    'enableConditions' => true,
                    'conditions' => [
                        'showRule' => 'show',
                        'conditionRule' => 'all',
                        'conditions' => [
                            [
                                'field' => 'country',
                                'condition' => '=',
                                'value' => 'unitedStates'
                            ]
                        ]
                    ]
                ]),
                $this->createSingleLineText([
                    'label' => 'Zip Code',
                    'handle' => 'jobZip',
                    'required' => false, // Will be conditionally required
                    'placeholder' => 'e.g., 02101',
                    'enableConditions' => true,
                    'conditions' => [
                        'showRule' => 'show',
                        'conditionRule' => 'all',
                        'conditions' => [
                            [
                                'field' => 'country',
                                'condition' => '=',
                                'value' => 'unitedStates'
                            ]
                        ]
                    ]
                ])
            ]
        ];

        // Row 11: International Location Field (conditional)
        $page1['rows'][] = [
            'fields' => [
                $this->createSingleLineText([
                    'label' => 'Location (City, Country)',
                    'handle' => 'jobLocation',
                    'required' => false, // Will be conditionally required
                    'placeholder' => 'e.g., London, United Kingdom',
                    'instructions' => 'Enter the city and country for this international position.',
                    'enableConditions' => true,
                    'conditions' => [
                        'showRule' => 'show',
                        'conditionRule' => 'all',
                        'conditions' => [
                            [
                                'field' => 'country',
                                'condition' => '=',
                                'value' => 'international'
                            ]
                        ]
                    ]
                ])
            ]
        ];

        // Page 2: Payment Information
        $page2 = [
            'label' => 'Payment Information',
            'rows' => []
        ];

        // Row 1: Posting Duration
        $page2['rows'][] = [
            'fields' => [
                $this->createRadio([
                    'label' => 'Posting Duration',
                    'handle' => 'postingDuration',
                    'required' => true,
                    'options' => [
                        ['label' => '6 Months - $300', 'value' => '6months'],
                        ['label' => '12 Months - $400', 'value' => '12months'],
                    ],
                    'instructions' => 'Select how long you want your job posting to remain active.'
                ])
            ]
        ];

        // Row 2: Payment Method
        $page2['rows'][] = [
            'fields' => [
                $this->createRadio([
                    'label' => 'Payment Method',
                    'handle' => 'paymentMethod',
                    'required' => true,
                    'options' => [
                        ['label' => 'Credit Card (Pay Now)', 'value' => 'creditCard'],
                        ['label' => 'Request Invoice (Pay Later)', 'value' => 'invoice'],
                    ],
                    'instructions' => 'Choose how you would like to pay for this job posting.'
                ])
            ]
        ];

        // Row 3: Invoice Instructions (conditional)
        $page2['rows'][] = [
            'fields' => [
                $this->createHtml([
                    'handle' => 'invoiceInstructions',
                    'labelPosition' => 'hidden',
                    'htmlContent' => '<div class="alert alert-info">
                        <h4>Invoice Payment Selected</h4>
                        <p>If you choose to be invoiced, your job posting will be published immediately. A member of the Blue Green Sheet team will contact you within 1-2 business days to arrange payment.</p>
                    </div>',
                    'enableConditions' => true,
                    'conditions' => [
                        'showRule' => 'show',
                        'conditionRule' => 'all',
                        'conditions' => [
                            [
                                'field' => 'paymentMethod',
                                'condition' => '=',
                                'value' => 'invoice'
                            ]
                        ]
                    ]
                ])
            ]
        ];

        // Add pages to form
        $this->form->setFormConfig([
            'pages' => [$page1, $page2]
        ]);
    }

    private function createSingleLineText($config)
    {
        $field = new SingleLineText();
        $field->label = $config['label'];
        $field->handle = $config['handle'];
        $field->required = $config['required'] ?? false;
        $field->placeholder = $config['placeholder'] ?? '';
        $field->instructions = $config['instructions'] ?? '';

        if (isset($config['defaultValue'])) {
            $field->defaultValue = $config['defaultValue'];
        }

        if (isset($config['enableConditions'])) {
            $field->enableConditions = $config['enableConditions'];
            $field->conditions = $config['conditions'] ?? [];
        }

        return $field;
    }

    private function createMultiLineText($config)
    {
        $field = new MultiLineText();
        $field->label = $config['label'];
        $field->handle = $config['handle'];
        $field->required = $config['required'] ?? false;
        $field->placeholder = $config['placeholder'] ?? '';
        $field->instructions = $config['instructions'] ?? '';
        $field->rows = $config['rows'] ?? 5;

        return $field;
    }

    private function createRichText($config)
    {
        $field = new RichText();
        $field->label = $config['label'];
        $field->handle = $config['handle'];
        $field->required = $config['required'] ?? false;
        $field->instructions = $config['instructions'] ?? '';

        return $field;
    }

    private function createDropdown($config)
    {
        $field = new Dropdown();
        $field->label = $config['label'];
        $field->handle = $config['handle'];
        $field->required = $config['required'] ?? false;
        $field->instructions = $config['instructions'] ?? '';
        $field->options = $config['options'] ?? [];

        if (isset($config['enableConditions'])) {
            $field->enableConditions = $config['enableConditions'];
            $field->conditions = $config['conditions'] ?? [];
        }

        return $field;
    }

    private function createRadio($config)
    {
        $field = new Radio();
        $field->label = $config['label'];
        $field->handle = $config['handle'];
        $field->required = $config['required'] ?? false;
        $field->instructions = $config['instructions'] ?? '';
        $field->options = $config['options'] ?? [];

        return $field;
    }

    private function createCategories($config)
    {
        $field = new Categories();
        $field->label = $config['label'];
        $field->handle = $config['handle'];
        $field->required = $config['required'] ?? false;
        $field->instructions = $config['instructions'] ?? '';
        $field->source = $config['source'] ?? '';

        return $field;
    }

    private function createHidden($config)
    {
        $field = new Hidden();
        $field->handle = $config['handle'];
        $field->defaultValue = $config['defaultValue'] ?? '';

        return $field;
    }

    private function createHtml($config)
    {
        $field = new Html();
        $field->handle = $config['handle'];
        $field->labelPosition = $config['labelPosition'] ?? 'above';
        $field->htmlContent = $config['htmlContent'] ?? '';

        if (isset($config['enableConditions'])) {
            $field->enableConditions = $config['enableConditions'];
            $field->conditions = $config['conditions'] ?? [];
        }

        return $field;
    }

    private function getCategoryGroupUid($handle)
    {
        $group = Craft::$app->categories->getGroupByHandle($handle);
        return $group ? $group->uid : '';
    }

    private function getStateOptions()
    {
        $states = [
            ['label' => '— Select State —', 'value' => ''],
            ['label' => 'Alabama', 'value' => 'AL'],
            ['label' => 'Alaska', 'value' => 'AK'],
            ['label' => 'Arizona', 'value' => 'AZ'],
            ['label' => 'Arkansas', 'value' => 'AR'],
            ['label' => 'California', 'value' => 'CA'],
            ['label' => 'Colorado', 'value' => 'CO'],
            ['label' => 'Connecticut', 'value' => 'CT'],
            ['label' => 'Delaware', 'value' => 'DE'],
            ['label' => 'Florida', 'value' => 'FL'],
            ['label' => 'Georgia', 'value' => 'GA'],
            ['label' => 'Hawaii', 'value' => 'HI'],
            ['label' => 'Idaho', 'value' => 'ID'],
            ['label' => 'Illinois', 'value' => 'IL'],
            ['label' => 'Indiana', 'value' => 'IN'],
            ['label' => 'Iowa', 'value' => 'IA'],
            ['label' => 'Kansas', 'value' => 'KS'],
            ['label' => 'Kentucky', 'value' => 'KY'],
            ['label' => 'Louisiana', 'value' => 'LA'],
            ['label' => 'Maine', 'value' => 'ME'],
            ['label' => 'Maryland', 'value' => 'MD'],
            ['label' => 'Massachusetts', 'value' => 'MA'],
            ['label' => 'Michigan', 'value' => 'MI'],
            ['label' => 'Minnesota', 'value' => 'MN'],
            ['label' => 'Mississippi', 'value' => 'MS'],
            ['label' => 'Missouri', 'value' => 'MO'],
            ['label' => 'Montana', 'value' => 'MT'],
            ['label' => 'Nebraska', 'value' => 'NE'],
            ['label' => 'Nevada', 'value' => 'NV'],
            ['label' => 'New Hampshire', 'value' => 'NH'],
            ['label' => 'New Jersey', 'value' => 'NJ'],
            ['label' => 'New Mexico', 'value' => 'NM'],
            ['label' => 'New York', 'value' => 'NY'],
            ['label' => 'North Carolina', 'value' => 'NC'],
            ['label' => 'North Dakota', 'value' => 'ND'],
            ['label' => 'Ohio', 'value' => 'OH'],
            ['label' => 'Oklahoma', 'value' => 'OK'],
            ['label' => 'Oregon', 'value' => 'OR'],
            ['label' => 'Pennsylvania', 'value' => 'PA'],
            ['label' => 'Rhode Island', 'value' => 'RI'],
            ['label' => 'South Carolina', 'value' => 'SC'],
            ['label' => 'South Dakota', 'value' => 'SD'],
            ['label' => 'Tennessee', 'value' => 'TN'],
            ['label' => 'Texas', 'value' => 'TX'],
            ['label' => 'Utah', 'value' => 'UT'],
            ['label' => 'Vermont', 'value' => 'VT'],
            ['label' => 'Virginia', 'value' => 'VA'],
            ['label' => 'Washington', 'value' => 'WA'],
            ['label' => 'West Virginia', 'value' => 'WV'],
            ['label' => 'Wisconsin', 'value' => 'WI'],
            ['label' => 'Wyoming', 'value' => 'WY'],
        ];

        return $states;
    }

    private function setupNotifications()
    {
        echo "Setting up notifications...\n";

        // Staff notification for all job postings
        $staffNotification = new Notification();
        $staffNotification->name = 'Staff Alert - New Job Posted';
        $staffNotification->enabled = true;
        $staffNotification->subject = 'New Job Posted: {submission:jobTitle}';
        $staffNotification->to = 'staff@bluegreensheet.com'; // Update with actual staff email
        $staffNotification->content = $this->getStaffNotificationTemplate();

        $this->form->setNotifications([$staffNotification]);
    }

    private function getStaffNotificationTemplate()
    {
        return '
<h2>New Job Posting Submitted</h2>

<p><strong>Job Title:</strong> {submission:jobTitle}</p>
<p><strong>Organization:</strong> {submission:organization}</p>
<p><strong>Category:</strong> {submission:jobCategory}</p>
<p><strong>Location:</strong>
    {if submission:country == "unitedStates"}
        {submission:jobCity}, {submission:jobState} {submission:jobZip}
    {else}
        {submission:jobLocation}
    {/if}
</p>

<p><strong>Duration:</strong> {submission:postingDuration}</p>
<p><strong>Payment Method:</strong> {submission:paymentMethod}</p>

{if submission:paymentMethod == "invoice"}
<p><strong>⚠️ ACTION REQUIRED:</strong> This job was posted with invoice payment. Please contact the client to arrange payment.</p>
{/if}

<p><strong>Posted By:</strong> {user:fullName} ({user:email})</p>
<p><strong>Submitted:</strong> {dateCreated|date("F j, Y \\a\\t g:i A")}</p>

<hr>

<p><strong>Job Description:</strong></p>
{submission:jobDescription}

{if submission:jobOpportunityStatement}
<p><strong>Opportunity Statement:</strong></p>
{submission:jobOpportunityStatement}
{/if}

<p><strong>Application Instructions:</strong></p>
{submission:jobApplicationInstructions}

{if submission:jobSalaryLow or submission:jobSalaryHigh}
<p><strong>Salary Range:</strong>
    {if submission:jobSalaryLow and submission:jobSalaryHigh}
        ${submission:jobSalaryLow}K - ${submission:jobSalaryHigh}K
    {elseif submission:jobSalaryLow}
        From ${submission:jobSalaryLow}K
    {elseif submission:jobSalaryHigh}
        Up to ${submission:jobSalaryHigh}K
    {/if}
</p>
{/if}

{if submission:jobSchoolUrl}
<p><strong>Website:</strong> <a href="{submission:jobSchoolUrl}">{submission:jobSchoolUrl}</a></p>
{/if}

<p><a href="{cpUrl}formie/submissions/{submission:id}">View Full Submission</a></p>
';
    }

    private function saveForm()
    {
        echo "Saving form...\n";

        if (!Craft::$app->elements->saveElement($this->form)) {
            $errors = $this->form->getErrors();
            throw new Exception('Failed to save form: ' . implode(', ', $errors));
        }
    }
}

// Run the form creator
$creator = new JobPostingFormCreator();
$creator->run();