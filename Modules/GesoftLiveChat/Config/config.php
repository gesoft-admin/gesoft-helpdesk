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
     * own. Live Helper Chat ships with no limit here at all; it relies on a
     * token tied to the address and the browser's headers, and on bans.
     *
     * Per address, and an address is often an office: several people behind
     * one router, each of whom may start a chat, end it and start another. It
     * was three in ten minutes, which a single office used up. Twenty leaves
     * room for that and still stops a script filling the chat list; what one
     * chat may then send is limited separately (`send_limit`).
     */
    'start_limit'  => env('GESOFT_LIVE_CHAT_START_LIMIT', 20),
    'start_window' => env('GESOFT_LIVE_CHAT_START_WINDOW', 10),

    /**
     * Requests a minute to the visitor endpoints from one address, all of them
     * together: polls, messages, "End", the status check.
     *
     * A ceiling against floods, not a budget for one visitor. A chat in
     * active conversation polls every second and a half, forty times a
     * minute, and much less when it is quiet or its tab is hidden; 600 leaves
     * an office behind one address room for fifteen lively chats at once and
     * still caps one address at ten requests a second. The old value of 30
     * refused a second tab.
     */
    'rate_per_minute' => env('GESOFT_LIVE_CHAT_RATE_PER_MINUTE', 600),

    /**
     * How fast one chat's visitor may send: at most `send_burst` messages
     * within `send_burst_seconds`, and at most `send_limit` within a minute.
     * Zero switches either off. Agents are not limited.
     *
     * Two windows, because either alone fails: twenty a minute on its own let
     * twenty messages through in as many seconds, and a burst limit on its own
     * lets a steady stream through. Live Helper Chat limits only the length of
     * a message. A refused message is given back to the visitor with how long
     * to wait.
     *
     * Per chat rather than per address, so a visitor pasting a burst is slowed
     * down and the colleague behind the same address is not.
     */
    'send_limit'         => env('GESOFT_LIVE_CHAT_SEND_LIMIT', 20),
    'send_burst'         => env('GESOFT_LIVE_CHAT_SEND_BURST', 5),
    'send_burst_seconds' => env('GESOFT_LIVE_CHAT_SEND_BURST_SECONDS', 10),

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
     * customer, so it is worded as one. Empty uses the translated default,
     * "Are you still there?", in the visitor's language; a value here is sent
     * as written, whatever the language.
     */
    'idle_prompt' => env('GESOFT_LIVE_CHAT_IDLE_PROMPT', ''),

    /**
     * Whether the bubble offers a chat or a message form.
     *
     *   auto     a chat while an agent with access to the chat mailbox has had
     *            FreeScout open within `operator_timeout`, the form otherwise
     *   online   always a chat
     *   offline  always the form
     *
     * Live Helper Chat shows the same "leave a message" form when no operator
     * is online, and the message becomes an ordinary conversation answered by
     * email.
     */
    'availability' => env('GESOFT_LIVE_CHAT_AVAILABILITY', 'auto'),

    /**
     * How long after an agent's last sign of life they still count as around,
     * in seconds. The operator script checks in every ten seconds from any
     * FreeScout page; a background tab slows to about once a minute, so two
     * minutes does not drop an agent who switched tabs.
     */
    'operator_timeout' => env('GESOFT_LIVE_CHAT_OPERATOR_TIMEOUT', 120),

    /**
     * After how many seconds without an answer the bubble tells the visitor
     * somebody will be with them shortly. Zero never does. Shown in the bubble
     * only, never written into the conversation.
     */
    'wait_after' => env('GESOFT_LIVE_CHAT_WAIT_AFTER', 60),

    /**
     * Whether each side sees that the other is typing: three dots in the
     * bubble while an agent writes a reply, and "the customer is typing…"
     * above the conversation while the visitor writes. A note never shows.
     *
     * Only that somebody is typing, never what. Live Helper Chat also shows
     * the agent the visitor's unsent text; that is deliberately not copied.
     */
    'typing' => env('GESOFT_LIVE_CHAT_TYPING', true),

    /**
     * Delivery receipts, both ways: a message is sent (the server has it),
     * delivered (the other side's screen fetched it) and seen (it was on that
     * screen while the screen could be looked at). The visitor sees this on
     * their own messages in the bubble, the agent on their replies in the
     * conversation.
     *
     * Live Helper Chat does the same over its existing polls, and so does this:
     * no request is added.
     */
    'receipts' => env('GESOFT_LIVE_CHAT_RECEIPTS', true),

    /**
     * Whether the visitor is told that an agent has seen their message, or
     * only that it was delivered. Agents always see what the visitor has seen.
     */
    'receipts_seen_to_visitor' => env('GESOFT_LIVE_CHAT_RECEIPTS_SEEN_TO_VISITOR', true),

    /**
     * The language of automatic messages when the bubble did not say which:
     * the remote support link, "we can see your computer now", "are you still
     * there?". The bubble sends its own language with the first message.
     */
    'visitor_lang' => env('GESOFT_LIVE_CHAT_VISITOR_LANG', 'ro'),

    /**
     * The title of the chat page, `/chat`, and of the chat window on it.
     * Empty uses the bubble's own, "Asistență" or "Support".
     */
    'page_title' => env('GESOFT_LIVE_CHAT_PAGE_TITLE', ''),

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
