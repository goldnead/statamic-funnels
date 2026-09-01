<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where funnels live
    |--------------------------------------------------------------------------
    |
    | The URL prefix. With the default, a funnel called `fruehlingskurs` is at
    | `/f/fruehlingskurs` and its steps at `/f/fruehlingskurs/{slug}`.
    |
    | Short on purpose: these URLs are read out loud, printed on a flyer and
    | typed by hand more often than any other page on the site.
    |
    */

    'route_prefix' => 'f',

    /*
    |--------------------------------------------------------------------------
    | Optional siblings
    |--------------------------------------------------------------------------
    |
    | Each of these does nothing unless the addon is installed *and* the switch
    | is on. Installing a funnel addon must not start writing into somebody's
    | CRM or granting access on its own.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Shipped styling
    |--------------------------------------------------------------------------
    |
    | Whether the shipped template links its stylesheet. Off for a site with its
    | own design; the markup keeps its class names either way.
    |
    */

    'styles' => true,

    /*
    |--------------------------------------------------------------------------
    | A field for a code
    |--------------------------------------------------------------------------
    |
    | Whether an offer page shows a box to type a coupon into. On by default,
    | because a site that has no coupons has nothing to type and loses nothing.
    | Off is for sites that never discount and would rather not put the idea in
    | anybody's head.
    |
    */

    'coupons' => true,

    /*
    |--------------------------------------------------------------------------
    | Where a step's own template may live
    |--------------------------------------------------------------------------
    |
    | A step can name its own Antlers template. Empty means anywhere under the
    | views directory; a folder name confines it, which is worth doing on a site
    | where the people editing funnels are not the people who write templates.
    | A namespaced name (`vendor::view`) is refused either way.
    |
    */

    'template_prefix' => '',

    /*
    |--------------------------------------------------------------------------
    | Where "forgot your password" lives
    |--------------------------------------------------------------------------
    |
    | The account step refuses to touch an account that already exists and
    | points the visitor here instead. Empty falls back to the Control Panel's
    | own reset form; a site with a front-end login names its page.
    |
    */

    'password_reset_url' => null,

    'integrations' => [
        // Hand a captured address to goldnead/statamic-leadhub as a contact.
        'leadhub' => false,

        // Grant what was bought through goldnead/statamic-entitlements. Off
        // because the payment addon already offers the same bridge, and two
        // addons granting the same thing is worse than neither.
        'entitlements' => false,
    ],
];
