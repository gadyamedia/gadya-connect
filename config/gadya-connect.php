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
     * One-click sign-in for the Gadya team. The portal's pass signs its
     * bearer in as this account, created the first time it is used. The
     * role is written only when the users table has a role column; null
     * uses Gadya CMS's administrator role.
     */
    'sso' => [
        'name' => 'Gadya Support',
        'email' => env('GADYA_CONNECT_SSO_EMAIL', 'support@gadya.media'),
        'role' => null,
    ],

    /*
     * A plain Get help page at /gadya-connect/help for sites without
     * Filament, for anyone signed in. "auto" turns it on only when Filament
     * is not installed (Filament sites have the admin page instead).
     */
    'help_page' => env('GADYA_CONNECT_HELP_PAGE', 'auto'),

    /*
     * Where people can write when the site cannot reach the portal.
     */
    'help_address' => env('GADYA_CONNECT_HELP_ADDRESS', 'help@support.gadya.media'),

    /*
     * Seconds to wait for the portal before giving up on a check-in.
     */
    'timeout' => 15,

];
