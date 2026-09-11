<?php

/**
 * Branding configuration.
 *
 * Every value comes from the environment with a generic default, which is the
 * whole point: this repository is public and ships no brand of its own. An
 * operator sets these in the FreeScout `.env` and drops their own files next to
 * the defaults; nothing about their brand is committed here.
 *
 *   HELPDESK_BRAND_NAME=Gesoft Support
 *   HELPDESK_BRAND_LOGO=/brand/logo.svg
 *   HELPDESK_BRAND_FAVICON=/brand/favicon.svg
 *   HELPDESK_BRAND_URL=https://support.example.com
 *   HELPDESK_BRAND_BANNER=/brand/banner.svg
 *   HELPDESK_BRAND_STYLESHEET=/brand/brand.css
 *   HELPDESK_BRAND_COLOR=#1f6feb
 *
 * As with every module here, `env()` is called only in this file. Anywhere else
 * it reads as null the moment someone runs `php artisan config:cache`, which is
 * the supported production setup.
 */
return [
    'name' => 'GesoftBranding',

    /** Product name, used in the page title and the footer. */
    'brand_name' => env('HELPDESK_BRAND_NAME', 'Helpdesk'),

    /**
     * Logo and favicon. Either an absolute URL or a path served from `public/`.
     * The defaults are the placeholder marks committed to this repository —
     * deliberately anonymous, so a fork that changes nothing still looks like
     * nobody's product in particular rather than like someone else's.
     */
    'brand_logo' => env('HELPDESK_BRAND_LOGO', '/brand/default-logo.svg'),
    'brand_favicon' => env('HELPDESK_BRAND_FAVICON', '/brand/default-favicon.svg'),

    /** The login page's banner. Empty uses the logo. */
    'brand_banner' => env('HELPDESK_BRAND_BANNER', ''),

    /**
     * A stylesheet of the operator's, loaded after core's: a path under
     * `public/`, next to the logo. Empty, or a file that is not there, adds
     * nothing.
     */
    'brand_stylesheet' => env('HELPDESK_BRAND_STYLESHEET', ''),

    /** `#rrggbb` for the browser's theme colour. Empty keeps core's. */
    'brand_color' => env('HELPDESK_BRAND_COLOR', ''),

    /**
     * A frame around the mail a customer gets when an agent replies: a bar in
     * `brand_color`, a logo, a card, a footer naming the request. Off by
     * default. The logo must be a URL mail clients can fetch, and a PNG, since
     * several do not show SVG; empty prints `mail_name` instead.
     */
    'mail_layout' => env('HELPDESK_BRAND_MAIL', false),
    'mail_logo'   => env('HELPDESK_BRAND_MAIL_LOGO', ''),
    'mail_name'   => env('HELPDESK_BRAND_MAIL_NAME', ''),

    /** `[TAG #123]` in front of the reply's subject. Empty adds nothing. */
    'mail_subject_tag' => env('HELPDESK_BRAND_MAIL_SUBJECT_TAG', ''),

    /** Where the brand name links to. Empty leaves it unlinked. */
    'brand_url' => env('HELPDESK_BRAND_URL', ''),

    /**
     * Where this instance's corresponding source lives.
     *
     * The AGPL asks that people who use the software over a network be offered
     * its source, so this link is on by default and the operator is expected to
     * point it at the repository their instance was actually built from — a
     * fork with local changes should name that fork, not this one.
     */
    'source_url' => env('HELPDESK_SOURCE_URL', 'https://github.com/gesoft-admin/gesoft-helpdesk'),

    /** Set to false only if the source link is offered somewhere else instead. */
    'source_link' => env('HELPDESK_SOURCE_LINK', true),
];
