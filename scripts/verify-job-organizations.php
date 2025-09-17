#!/usr/bin/env php
<?php
/**
 * Script to verify organization relationships for jobs
 */

// Bootstrap Craft
define('CRAFT_BASE_PATH', __DIR__ . '/..');
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;

// Get jobs with and without organizations
$jobsWithOrg = Entry::find()->section('jobs')->organization(':notempty:')->count();
$jobsWithoutOrg = Entry::find()->section('jobs')->organization(':empty:')->count();
$totalJobs = Entry::find()->section('jobs')->count();

echo "Job Organization Relationship Verification\n";
echo str_repeat("=", 45) . "\n";
echo "Total Jobs: {$totalJobs}\n";
echo "Jobs with Organization: {$jobsWithOrg}\n";
echo "Jobs without Organization: {$jobsWithoutOrg}\n";
echo "Coverage: " . round(($jobsWithOrg / $totalJobs) * 100, 1) . "%\n";

if ($jobsWithoutOrg > 0) {
    echo "\nJobs without organization:\n";
    $jobsWithoutOrgList = Entry::find()->section('jobs')->organization(':empty:')->all();
    foreach ($jobsWithoutOrgList as $job) {
        $author = $job->getAuthor();
        $authorOrg = $author ? $author->organization->one() : null;
        echo "- Job #{$job->id}: \"{$job->title}\" by " . ($author ? $author->fullName : 'Unknown') . " ";
        echo "(" . ($authorOrg ? $authorOrg->title : 'No org') . ")\n";
    }
}