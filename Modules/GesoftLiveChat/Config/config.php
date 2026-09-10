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

    /**
     * How many conversations one address may start, and over how many minutes.
     * Zero for the limit switches it off.
     *
     * Separate from the route throttle, which counts every request including
     * the three-second poll and so has to be generous. Starting is the call
     * that creates something an agent must read, so it gets a budget of its
     * own. Live Helper Chat ships with no limit here at all.
     *
     * A closed chat is not reopened from the bubble — the visitor starts a new
     * one — so a real customer may use more than one start in an afternoon.
     * Three in ten minutes leaves room for that and not for a script.
     */
    'start_limit'  => env('GESOFT_LIVE_CHAT_START_LIMIT', 3),
    'start_window' => env('GESOFT_LIVE_CHAT_START_WINDOW', 10),

    /**
     * How often a visitor's poll is written down as "still here", in seconds.
     * Polls in between are answered without a write.
     */
    'seen_every' => env('GESOFT_LIVE_CHAT_SEEN_EVERY', 30),

    /**
     * How long without a sign of the visitor before they count as gone, in
     * seconds. Zero never marks anybody gone.
     *
     * Two minutes rather than Live Helper Chat's one: Chrome slows the timers
     * of a background tab to about once a minute, so at sixty seconds a
     * customer who only switched tabs would be reported as having left. The
     * goodbye a closing tab sends does not shorten this — a reload sends the
     * same goodbye a moment before coming back.
     */
    'gone_after' => env('GESOFT_LIVE_CHAT_GONE_AFTER', 120),

    /**
     * How long after **the agent's last reply** a chat with no customer answer
     * is marked idle, in seconds. Zero disables it.
     *
     * The clock deliberately reads the agent's message, never the customer's.
     * A conversation where the customer spoke last is one *we* have not
     * answered, and an operator who is not paying attention must never be able
     * to hang up on a customer who is waiting. That case has no timer.
     */
    'idle_after' => env('GESOFT_LIVE_CHAT_IDLE_AFTER', 300),

    /**
     * How much longer after being marked idle a chat is closed automatically,
     * in seconds. Zero leaves idle chats open for an agent to close by hand.
     *
     * Two stages rather than one because closing outright the first time a
     * customer pauses loses a conversation somebody may still be in the middle
     * of. The first stage only marks; this one ends it, with a line item in the
     * conversation saying so.
     */
    'close_after' => env('GESOFT_LIVE_CHAT_CLOSE_AFTER', 900),

    /**
     * What the "still there?" button sends. It is a real message to the
     * customer, so it is worded as one.
     */
    'idle_prompt' => env('GESOFT_LIVE_CHAT_IDLE_PROMPT', 'Are you still there?'),

    /**
     * Whether a visitor must give an email address before the first message.
     *
     * On worth having: an address is what connects this conversation to the
     * customer's existing record. `Customer::create()` deduplicates by email,
     * so a returning customer's chat lands on the same profile as their
     * tickets instead of creating a stranger every time.
     *
     * Turn it off where identity arrives another way — an application that
     * already knows who is signed in passes it to the widget, and asking again
     * would be asking somebody to introduce themselves twice.
     */
    'require_email' => env('GESOFT_LIVE_CHAT_REQUIRE_EMAIL', true),
];
