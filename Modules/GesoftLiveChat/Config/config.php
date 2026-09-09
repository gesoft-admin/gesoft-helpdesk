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
];
