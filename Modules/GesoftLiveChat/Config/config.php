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
     * How long after an agent closes a chat the visitor may still write into
     * it, in seconds. Zero disables it.
     *
     * This exists because "the agent closed it" and "the visitor wandered off"
     * look identical from here and are not the same thing. A visitor who is
     * still typing when the agent presses Close should land in the
     * conversation they were having, not open a second one the agent then has
     * to read from the beginning.
     *
     * **Only applies when the mailbox asks for new conversations.** FreeScout
     * owns the larger decision: each mailbox has "Start a new conversation when
     * receiving a reply to the closed / deleted Chat conversation", and with it
     * unticked — the default — a returning customer always lands in the same
     * conversation and this number is never consulted.
     *
     * Live Helper Chat settled the same question with two settings — how many
     * seconds a customer has to reopen a closed chat, and whether it reopens
     * as new or as active.
     *
     * Two minutes is short on purpose. Long enough for a message already being
     * typed, short enough that a customer returning after lunch does not
     * silently revive a conversation the agent considered finished.
     */
    'reopen_window' => env('GESOFT_LIVE_CHAT_REOPEN_WINDOW', 120),

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
