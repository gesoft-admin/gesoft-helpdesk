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
     * Whether the visitor is told that an agent has seen their message. Off:
     * the visitor sees sent and delivered, and agents alone see "seen".
     *
     * Support chat vendors split on this. Tidio never shows it to visitors,
     * Live Helper Chat drops the tick once a message is read, Crisp has a
     * switch to hide it, and Intercom shows "Seen" only once a teammate starts
     * replying, so opening a conversation promises nothing. "Seen but no
     * answer" reads as being ignored (CHI 2014, 2017, 2022), and an agent here
     * may be in several chats at once. That somebody is replying is already
     * shown by the typing dots.
     */
    'receipts_seen_to_visitor' => env('GESOFT_LIVE_CHAT_RECEIPTS_SEEN_TO_VISITOR', false),

    /**
     * Whether a chat reads like a chat window for the agent in Chat Mode: the
     * reply editor fixed at the bottom of the window, and the messages above it
     * in a pane of their own, oldest at the top and newest just above the
     * editor. Off: FreeScout's own layout, the editor above the messages and
     * the newest first.
     *
     * Core's layout suits email, where a thread is long and the newest message
     * is the one to read. A chat is read in order, and support chat consoles
     * build it that way: Live Helper Chat, LiveChat, Intercom, Front, and Help
     * Scout itself since 2024. Zendesk lets a helpdesk pick per channel and
     * suggests exactly this split. Email conversations, and chats outside Chat
     * Mode, keep core's layout either way.
     */
    'newest_at_bottom' => env('GESOFT_LIVE_CHAT_NEWEST_AT_BOTTOM', true),

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
     * The bubble's colour on the chat page, `#rrggbb`. Empty keeps the
     * bubble's own. A site embedding the bubble sets `data-color` on the
     * script tag itself.
     */
    'color' => env('GESOFT_LIVE_CHAT_COLOR', ''),

    /**
     * On the chat page: `light` or `dark` to fix the bubble's scheme (empty
     * follows the visitor's system), and a stylesheet of the instance's loaded
     * inside the bubble, a path on this helpdesk such as `/brand/bubble.css`.
     */
    'theme'      => env('GESOFT_LIVE_CHAT_THEME', ''),
    'stylesheet' => env('GESOFT_LIVE_CHAT_STYLESHEET', ''),

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

    /**
     * Applications allowed to open chats for people they have already signed
     * in, and to frame the embedded chat page.
     *
     * `GESOFT_LIVE_CHAT_APPS` names them, comma separated; each one then needs
     * a shared secret and the origin its pages are served from:
     *
     *   GESOFT_LIVE_CHAT_APPS=myapp
     *   GESOFT_LIVE_CHAT_APP_MYAPP_TOKEN=<64 random hex characters>
     *   GESOFT_LIVE_CHAT_APP_MYAPP_ORIGIN=https://myapp.example.com
     *   GESOFT_LIVE_CHAT_APP_MYAPP_NAME=My Application
     *
     * The suffix is the provider name upper-cased, with anything that is not a
     * letter or a digit turned into an underscore. One origin per application
     * and no wildcards: it is both what `frame-ancestors` allows on the
     * embedded page and what that page will post messages to, deliberately the
     * same value so an application cannot be authorised in one direction only.
     *
     * `Support/Apps.php` drops any entry missing a piece rather than repairing
     * it, so a half-written application here is simply not registered.
     */
    'apps' => call_user_func(function () {
        $apps = [];

        foreach (explode(',', (string) env('GESOFT_LIVE_CHAT_APPS', '')) as $provider) {
            $provider = trim($provider);
            if ($provider === '') {
                continue;
            }

            $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $provider));

            $apps[$provider] = [
                'token'  => (string) env('GESOFT_LIVE_CHAT_APP_'.$key.'_TOKEN', ''),
                'origin' => (string) env('GESOFT_LIVE_CHAT_APP_'.$key.'_ORIGIN', ''),
                'name'   => (string) env('GESOFT_LIVE_CHAT_APP_'.$key.'_NAME', ''),
            ];
        }

        return $apps;
    }),

    /**
     * How long a browser's permission to act as an application user lasts, in
     * seconds.
     *
     * It is asked for again on every page that opens the panel, so this only
     * has to outlast one sitting in front of one page. Twelve hours covers a
     * working day with a page left open, and still means a token copied out of
     * a browser is worthless by the next morning.
     */
    'app_session_ttl' => env('GESOFT_LIVE_CHAT_APP_SESSION_TTL', 43200),

    /**
     * Requests a minute to the server-to-server bootstrap, from one address.
     *
     * Its own ceiling rather than the visitors' one, because every call
     * arrives from the same address -- the application's server -- and would
     * otherwise share a budget sized for polling bubbles. One call per person
     * per sitting is the shape of the traffic; 120 leaves plenty of room and
     * still caps a loop that has got hold of a secret.
     */
    'app_rate_per_minute' => env('GESOFT_LIVE_CHAT_APP_RATE_PER_MINUTE', 120),

    /**
     * How many conversations one application identity may open, and over how
     * many minutes. Zero switches it off.
     *
     * A signed-in customer is known, not anonymous, so the per-address start
     * limit is the wrong instrument -- a whole institution behind one address
     * would share one budget. This one is per person instead, and generous:
     * it is there so a script that has got hold of a bootstrap token cannot
     * fill the chat list, not to ration support.
     */
    'app_start_limit'  => env('GESOFT_LIVE_CHAT_APP_START_LIMIT', 10),
    'app_start_window' => env('GESOFT_LIVE_CHAT_APP_START_WINDOW', 10),

    /**
     * The source link at the foot of the chat window on `/chat`, and where it
     * points.
     *
     * The AGPL asks that people who use the software over a network be offered
     * its source. Everywhere else in this helpdesk that offer is in FreeScout's
     * footer, which `/chat` does not have: it is served outside the `web`
     * middleware group and is the one page a stranger uses without ever seeing
     * a FreeScout page. So it makes the offer itself.
     *
     * `HELPDESK_SOURCE_URL` and `HELPDESK_SOURCE_LINK` are the same two
     * settings `GesoftBranding` reads for the footer link -- one value, two
     * readers, so an operator who points one at their fork has pointed both.
     *
     * `source_ref` is the tag or commit this instance was built from, appended
     * as `/tree/<ref>`, which is how GitHub, GitLab, Gitea and Forgejo all
     * address a ref. Empty points at the repository instead, which is a weaker
     * offer: it says where the source lives, not which of it is running here.
     */
    'source_url'  => env('HELPDESK_SOURCE_URL', 'https://github.com/gesoft-admin/gesoft-helpdesk'),
    'source_ref'  => env('HELPDESK_SOURCE_REF', ''),
    'source_link' => env('HELPDESK_SOURCE_LINK', true),
];
