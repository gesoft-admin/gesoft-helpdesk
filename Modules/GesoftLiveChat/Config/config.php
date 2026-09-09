<?php

/**
 * Module configuration.
 *
 * As in `GesoftRemoteSupport`, this file is the only place that calls `env()`:
 * anywhere else it reads as `null` the moment somebody runs
 * `php artisan config:cache`, which is the supported production setup.
 */
return [
    'name' => 'GesoftLiveChat',

    /**
     * The channel code stored in `customers.channel`, `customer_channel.channel`
     * and `conversations.channel`.
     *
     * The column is an unsigned tinyint and **FreeScout keeps no registry of
     * these codes** -- each module picks its own, so a collision with a module
     * we might install later (WhatsApp, Telegram, the commercial Chat) is a
     * real possibility and not one core will warn about. 100 is chosen high and
     * round to stay clear of whatever upstream numbers from zero.
     *
     * Changing it after conversations exist orphans them: they would keep a
     * code nothing claims, and `channel.name` would return an empty string.
     */
    'channel' => 100,

    /**
     * Whether the module registers its development-only artisan command.
     *
     * Off by default and read from the environment rather than from
     * `app()->environment()`, because a FreeScout test instance runs with
     * `APP_ENV=production` like every other one -- gating on the environment
     * would mean the tool is unavailable exactly where it is needed and
     * available on any instance somebody sets to `local`.
     *
     * The command is CLI-only and no route reaches it. This flag exists so
     * that even the CLI surface is absent unless somebody asked for it.
     */
    'dev_tools' => env('GESOFT_LIVE_CHAT_DEV_TOOLS', false),

    /**
     * Origins allowed to talk to the visitor endpoints, comma separated and
     * with the scheme: `https://example.com,https://www.example.com`.
     *
     * Empty means same-origin only, which is the safe default and all the demo
     * page needs. There is deliberately no wildcard: `*` here would let any
     * site on the internet open conversations in this helpdesk out of a
     * visitor's browser.
     */
    'origins' => env('GESOFT_LIVE_CHAT_ORIGINS', ''),

    /**
     * Which mailbox receives chats. Empty takes the first one, which is right
     * for a single-mailbox installation; set it as soon as there are two, or
     * chats will quietly land wherever `id` happens to order first.
     */
    'mailbox_id' => env('GESOFT_LIVE_CHAT_MAILBOX_ID', null),
];
