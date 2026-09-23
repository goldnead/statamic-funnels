<?php

/*
 * Labels for the settings screen.
 *
 * Field keys are the config path with the dots flattened
 * (`thumbnails.width` → `thumbnails_width`).
 */

return [

    'permission_group' => 'Funnels',
    'permission_manage' => 'Manage funnel settings',
    'permission_tracking' => 'Edit tracking code',
    'permission_tracking_description' => 'Head and purchase code, Meta pixel ID and their consent services. This is JavaScript that runs on the funnel pages.',

    'groups' => [

        'pages' => [
            'title' => 'Funnel pages',
            'description' => 'What a visitor sees on a funnel page. The URL prefix stays in config/statamic-funnels.php: it is read while the routes are registered, so it would only take effect on the next deploy, and these addresses appear on printed material.',
        ],

        'thumbnails' => [
            'title' => 'Page pictures',
            'description' => 'The pictures on the cards in the funnel editor. The disk and the Chromium path stay in config/statamic-funnels.php because they describe the machine rather than the site; so do the cookies and the hidden selectors, which are a map and a selector list.',
        ],

        'reach' => [
            'title' => 'In-app browser, embedding, tracking',
            'description' => 'For all funnels. What a single funnel uses of it (notice text, allowed domains, tracking code, pixel ID) is set in its settings in the editor. The Meta Conversions API access token belongs in .env (FUNNELS_META_CAPI_TOKEN).',
        ],

        'integrations' => [
            'title' => 'Sibling addons',
            'description' => 'What a completed funnel hands on to other addons. The entitlements switch stays in the config alone: nothing in this addon reads it, so it switches nothing.',
        ],

    ],

    'fields' => [

        'styles' => [
            'label' => 'Load the shipped styling',
            'description' => 'Off means funnel pages link neither the shipped stylesheet nor the script. The class names in the markup stay, so your own CSS still applies; a step with a countdown loses its running clock without the script.',
        ],

        'coupons' => [
            'label' => 'Show the coupon box',
            'description' => 'Off means the offer page shows no box for a code, and a code sent anyway is ignored on advance. For sites that never discount.',
        ],

        'template_prefix' => [
            'label' => 'Folder for custom templates',
            'description' => 'A folder name confines the templates a step may name to that folder. Empty means anywhere under the views directory. Worth doing once the people editing funnels are not the people writing templates.',
        ],

        'password_reset_url' => [
            'label' => 'Forgotten password page',
            'description' => 'Where a visitor whose account already exists is sent. Empty falls back to the Control Panel\'s own reset form.',
        ],

        'thumbnails_enabled' => [
            'label' => 'Render page pictures',
            'description' => 'Off means no picture is rendered after a save and the cards in the editor keep whatever they have. Pictures already rendered are not deleted.',
        ],

        'thumbnails_width' => [
            'label' => 'Width in pixels',
            'description' => 'The stored width of new pictures. The card draws at 16:10, so another ratio is cropped. Existing pictures keep their size until the step is saved again.',
        ],

        'thumbnails_height' => [
            'label' => 'Height in pixels',
            'description' => 'The stored height of new pictures. See width.',
        ],

        'integrations_leadhub' => [
            'label' => 'Hand addresses to LeadHub',
            'description' => 'On means an address captured in a funnel is handed to goldnead/statamic-leadhub as a contact. Nothing happens without LeadHub installed.',
        ],

        'in_app_browser_enabled' => [
            'label' => 'In-app browser notice',
            'description' => 'Visitors from Instagram, Facebook, Threads, TikTok, LinkedIn, Pinterest or Snapchat see a note to open the page in their usual browser. Off means in no funnel, whatever the funnel says.',
        ],

        'embed_link_minutes' => [
            'label' => 'Embedded links valid for (minutes)',
            'description' => 'Inside a frame on another site the walk travels signed in the links. This is how long such a link stays good, the way back from the payment included.',
        ],

        'tracking_consent_service' => [
            'label' => 'Service in statamic-consent',
            'description' => 'The handle of the service whose consent releases tracking code and the Meta pixel. It has to exist in the consent config, otherwise everything stays blocked.',
        ],

        'tracking_without_consent_addon' => [
            'label' => 'Without statamic-consent',
            'description' => 'What happens to tracking code when statamic-consent is not installed.',
        ],

    ],

    'options' => [
        'without_consent_addon_block' => 'Print nothing',
        'without_consent_addon_render' => 'Print it, the site’s banner controls it',
    ],

];
