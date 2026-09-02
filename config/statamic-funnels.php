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

    /*
    |--------------------------------------------------------------------------
    | A picture of every page
    |--------------------------------------------------------------------------
    |
    | After a save, each page step is photographed and the editor shows the
    | picture on its card. This needs a browser: `spatie/browsershot` (a
    | Composer `suggest`, not a requirement) and a Chromium the addon can find,
    | on `PATH` or named in `chrome_path`. Without one, nothing is rendered,
    | the cards look as they always did, and the editor says so quietly.
    |
    | Pictures go on `disk`, which has to be a public one — the Control Panel
    | loads them by URL. `width` and `height` are the stored size in pixels;
    | the card draws them at 16:10, so keep that ratio.
    |
    | `cookies` (name => value) travel with the browser into the page. Left
    | empty, and with goldnead/statamic-consent installed, its consent cookie
    | is sent with every service granted — otherwise every picture is a picture
    | of the cookie banner. `hide_selectors` are CSS selectors hidden before
    | the shot, for a banner no cookie can silence.
    |
    */

    'thumbnails' => [
        'enabled' => true,
        'disk' => 'public',
        'width' => 640,
        'height' => 400,
        'chrome_path' => env('FUNNELS_CHROME_PATH'),
        'cookies' => [],
        'hide_selectors' => [],
    ],

    'integrations' => [
        // Hand a captured address to goldnead/statamic-leadhub as a contact.
        'leadhub' => false,

        // Grant what was bought through goldnead/statamic-entitlements. Off
        // because the payment addon already offers the same bridge, and two
        // addons granting the same thing is worse than neither.
        'entitlements' => false,
    ],
];
