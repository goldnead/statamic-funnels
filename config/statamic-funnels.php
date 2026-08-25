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

    'integrations' => [
        // Hand a captured address to goldnead/statamic-leadhub as a contact.
        'leadhub' => false,

        // Grant what was bought through goldnead/statamic-entitlements. Off
        // because the payment addon already offers the same bridge, and two
        // addons granting the same thing is worse than neither.
        'entitlements' => false,
    ],
];
