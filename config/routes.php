<?php
/**
 * Site URL Rules
 *
 * You can define custom site URL rules here, which Craft will check in addition
 * to routes defined in Settings → Routes.
 *
 * Read about Craft’s routing behavior (and this file’s structure), here:
 * @link https://craftcms.com/docs/5.x/system/routing.html
 */

return [
    // Override Craft's default email verification route to use our custom template
    'verifyemail' => ['template' => 'verify-email'],

    // Job editing route
    'jobs/edit/<entryId:\d+>' => ['template' => 'jobs/edit'],
];
