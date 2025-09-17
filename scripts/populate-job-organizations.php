#!/usr/bin/env php
<?php
/**
 * Script to populate organization field for existing jobs based on their authors' organization
 *
 * This script:
 * 1. Finds all jobs that don't have an organization assigned
 * 2. Looks up the author's organization from their user profile
 * 3. Assigns that organization to the job
 * 4. Saves the job entry
 */

// Bootstrap Craft
define('CRAFT_BASE_PATH', __DIR__ . '/..');
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;

class JobOrganizationPopulator
{
    private $dryRun = true;
    private $processed = 0;
    private $updated = 0;
    private $errors = 0;

    public function __construct($dryRun = true)
    {
        $this->dryRun = $dryRun;
    }

    public function run()
    {
        echo "Starting Job Organization Population Script\n";
        echo "Mode: " . ($this->dryRun ? "DRY RUN (no changes will be made)" : "LIVE (changes will be made)") . "\n";
        echo str_repeat("=", 60) . "\n";

        // Get all jobs in the jobs section
        $jobs = Entry::find()
            ->section('jobs')
            ->status(['live', 'pending', 'disabled'])
            ->all();

        echo "Found " . count($jobs) . " total jobs to process\n\n";

        foreach ($jobs as $job) {
            $this->processJob($job);
        }

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SUMMARY:\n";
        echo "Processed: {$this->processed} jobs\n";
        echo "Updated: {$this->updated} jobs\n";
        echo "Errors: {$this->errors} jobs\n";

        if ($this->dryRun) {
            echo "\nThis was a DRY RUN. To apply changes, run with --live flag\n";
        }
    }

    private function processJob(Entry $job)
    {
        $this->processed++;

        echo "Processing Job #{$job->id}: \"{$job->title}\"\n";

        // Check if job already has an organization
        $currentOrg = $job->organization->one();
        if ($currentOrg) {
            echo "  → Already has organization: {$currentOrg->title}\n";
            return;
        }

        // Get the job author
        $author = $job->getAuthor();
        if (!$author) {
            echo "  → ERROR: No author found\n";
            $this->errors++;
            return;
        }

        echo "  → Author: {$author->fullName} ({$author->email})\n";

        // Get author's organization
        $authorOrg = $author->organization->one();
        if (!$authorOrg) {
            echo "  → WARNING: Author has no organization assigned\n";
            return;
        }

        echo "  → Author's Organization: {$authorOrg->title}\n";

        // Assign organization to job
        if (!$this->dryRun) {
            try {
                $job->setFieldValue('organization', [$authorOrg->id]);

                // Skip validation to avoid URL field issues
                if (Craft::$app->elements->saveElement($job, false)) {
                    echo "  → ✓ Successfully updated job organization\n";
                    $this->updated++;
                } else {
                    echo "  → ERROR: Failed to save job\n";
                    if ($job->hasErrors()) {
                        foreach ($job->getErrors() as $field => $errors) {
                            echo "    - {$field}: " . implode(', ', $errors) . "\n";
                        }
                    }
                    $this->errors++;
                }
            } catch (Exception $e) {
                echo "  → ERROR: " . $e->getMessage() . "\n";
                $this->errors++;
            }
        } else {
            echo "  → Would assign organization: {$authorOrg->title}\n";
            $this->updated++;
        }

        echo "\n";
    }
}

// Parse command line arguments
$dryRun = true;
if (in_array('--live', $argv)) {
    $dryRun = false;
    echo "WARNING: Running in LIVE mode. Changes will be made to the database!\n";
    echo "Press Enter to continue or Ctrl+C to cancel...";
    readline();
}

// Run the populator
$populator = new JobOrganizationPopulator($dryRun);
$populator->run();