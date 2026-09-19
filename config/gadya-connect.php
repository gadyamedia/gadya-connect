<?php

return [

    /*
     * The Gadya Media portal this site reports to.
     */
    'portal_url' => env('GADYA_CONNECT_PORTAL', 'https://app.gadya.media'),

    /*
     * How often the site checks in. The portal treats a site that has not
     * checked in for twenty minutes as silent, so keep this well under that.
     */
    'report_every_minutes' => 5,

    /*
     * Schedule the check-in automatically. Needs the Laravel scheduler to be
     * running (`php artisan schedule:run` every minute), which is also what
     * the portal is watching for.
     */
    'schedule' => true,

    /*
     * The gate that may connect or disconnect the site and switch sign-in
     * for the Gadya team on or off. Null uses gadya-cms.settings when Gadya
     * CMS is installed, then manage-users, then any signed-in admin user.
     */
    'gate' => null,

    /*
     * Seconds to wait for the portal before giving up on a check-in.
     */
    'timeout' => 15,

];
