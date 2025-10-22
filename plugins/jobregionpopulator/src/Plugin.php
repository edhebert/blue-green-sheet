<?php

namespace plugins\jobregionpopulator;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public static $plugin;
    public string $schemaVersion = '1.0.2';

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register the region auto-population event handler
        Event::on(
            Entry::class,
            Entry::EVENT_BEFORE_SAVE,
            [$this, 'handleEntrySave']
        );

        Craft::info('Job Region Populator: Plugin loaded and event handler registered', __METHOD__);
    }

    /**
     * Handle entry save to auto-populate region field
     */
    public function handleEntrySave(ModelEvent $event)
    {
        /** @var Entry $entry */
        $entry = $event->sender;

        // Only process entries in the 'jobs' section
        if (!$entry->section || $entry->section->handle !== 'jobs') {
            return;
        }

        Craft::info('Job Region Populator: Processing jobs entry: ' . $entry->title, __METHOD__);

        // Get field values and extract actual values from field data objects
        $countryField = $entry->getFieldValue('country');
        $stateField = $entry->getFieldValue('jobState');

        $country = $countryField ? $countryField->value : null;
        $state = $stateField ? $stateField->value : null;

        Craft::info("Job Region Populator: Country: {$country}, State: {$state}", __METHOD__);

        // Determine region based on country and state
        $regionHandle = $this->determineRegion($country, $state);

        if ($regionHandle !== null) {
            // Find the region category by slug (handle)
            $regionCategory = \craft\elements\Category::find()
                ->group('jobRegion')
                ->slug($regionHandle)
                ->one();

            if ($regionCategory) {
                $entry->setFieldValue('jobRegion', [$regionCategory->id]);
                Craft::info("Job Region Populator: Set region to '{$regionHandle}' ({$regionCategory->title})", __METHOD__);
            } else {
                Craft::warning("Job Region Populator: Could not find region category with handle '{$regionHandle}'", __METHOD__);
            }
        }
    }

    /**
     * Determine region handle based on country and state values
     */
    private function determineRegion($country, $state)
    {
        if ($country === 'international') {
            return 'international';
        }

        if ($country === 'unitedStates' && $state) {
            // Map states to region handles based on your actual category handles
            $stateRegionMap = [
                // Pacific: CA, OR, WA, AK, HI
                'CA' => 'pacific-ca-or-wa-ak-hi',
                'OR' => 'pacific-ca-or-wa-ak-hi',
                'WA' => 'pacific-ca-or-wa-ak-hi',
                'AK' => 'pacific-ca-or-wa-ak-hi',
                'HI' => 'pacific-ca-or-wa-ak-hi',

                // Mid-Atlantic: DC, DE, MD, VA, WV
                'DC' => 'mid-atlantic-dc-de-md-va-wv',
                'DE' => 'mid-atlantic-dc-de-md-va-wv',
                'MD' => 'mid-atlantic-dc-de-md-va-wv',
                'VA' => 'mid-atlantic-dc-de-md-va-wv',
                'WV' => 'mid-atlantic-dc-de-md-va-wv',

                // Southeast: FL, GA, AL, NC, SC, KY, MS, TN
                'FL' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'GA' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'AL' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'NC' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'SC' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'KY' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'MS' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',
                'TN' => 'southeast-fl-ga-al-nc-sc-ky-ms-tn',

                // South Central West: AR, LA, OK, TX
                'AR' => 'south-central-west-ar-la-ok-tx',
                'LA' => 'south-central-west-ar-la-ok-tx',
                'OK' => 'south-central-west-ar-la-ok-tx',
                'TX' => 'south-central-west-ar-la-ok-tx',

                // Great Lakes: IL, IN, MI, OH, WI
                'IL' => 'great-lakes-il-in-mi-oh-wi',
                'IN' => 'great-lakes-il-in-mi-oh-wi',
                'MI' => 'great-lakes-il-in-mi-oh-wi',
                'OH' => 'great-lakes-il-in-mi-oh-wi',
                'WI' => 'great-lakes-il-in-mi-oh-wi',

                // New England: CT, ME, MA, NH, RI, VT
                'CT' => 'new-england-ct-me-ma-nh-ri-vt',
                'ME' => 'new-england-ct-me-ma-nh-ri-vt',
                'MA' => 'new-england-ct-me-ma-nh-ri-vt',
                'NH' => 'new-england-ct-me-ma-nh-ri-vt',
                'RI' => 'new-england-ct-me-ma-nh-ri-vt',
                'VT' => 'new-england-ct-me-ma-nh-ri-vt',

                // Tri-State: NY, NJ, PA
                'NY' => 'tri-state-ny-nj-pa',
                'NJ' => 'tri-state-ny-nj-pa',
                'PA' => 'tri-state-ny-nj-pa',

                // Central: IA, KS, MN, MO, NE, ND, SD
                'IA' => 'central-ia-ks-mn-mo-ne-nd-sd',
                'KS' => 'central-ia-ks-mn-mo-ne-nd-sd',
                'MN' => 'central-ia-ks-mn-mo-ne-nd-sd',
                'MO' => 'central-ia-ks-mn-mo-ne-nd-sd',
                'NE' => 'central-ia-ks-mn-mo-ne-nd-sd',
                'ND' => 'central-ia-ks-mn-mo-ne-nd-sd',
                'SD' => 'central-ia-ks-mn-mo-ne-nd-sd',

                // Mountain: CO, ID, MT, UT, WY
                'CO' => 'mountain-co-id-mt-ut-wy',
                'ID' => 'mountain-co-id-mt-ut-wy',
                'MT' => 'mountain-co-id-mt-ut-wy',
                'UT' => 'mountain-co-id-mt-ut-wy',
                'WY' => 'mountain-co-id-mt-ut-wy',

                // Southwest: AZ, NM, NV
                'AZ' => 'southwest-az-nm-nv',
                'NM' => 'southwest-az-nm-nv',
                'NV' => 'southwest-az-nm-nv',
            ];

            return $stateRegionMap[$state] ?? null;
        }

        return null;
    }
}
