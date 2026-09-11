# Changes made by Gesoft

Everything this fork adds on top of `upstream-1.8.239`. The list is meant to
stay short: the project's rule is to prefer modules and configuration hooks over
core modifications, and a core change here needs a reason that a module could
not have covered.

## Modules

### `Modules/GesoftRemoteSupport`

Starts and tracks a remote-support session from inside a conversation. The agent
presses **Start Remote Support** in the conversation sidebar; the module asks a
separate service (`helpdesk-rust`) for a session, shows the support code and
customer link, polls for the RustDesk ID and for whether that peer actually
registered with our own rendezvous server, and closes the session on demand.

The agent's own machine has to be let through that server's firewall too, which
opens per address. The panel says whether the agent's address is admitted and
until when, in red when it is not; Start admits it for the day, and "My RustDesk
client" gives one-time links for RustDesk on another machine or network.

It talks to that service over HTTP with an operator token that never leaves the
server — no route, view or JSON response in the module emits it. See
[`docs/architecture/remote-support.md`](docs/architecture/remote-support.md).

Imported from the installation that ran the production pilot, so the behaviour
in this repository is the behaviour that was validated. Two doc-comment lines
differ, and only those: they named a libvirt bridge address, which describes
somebody's network for no gain in a public repository, and now use names from
the ranges reserved for documentation. Comparing the two trees with comments and
whitespace stripped gives 12 PHP files and **zero differing code tokens** — the
executable content is provably the same.

### `Modules/GesoftLiveChat`

Registers a web chat channel, which is all it takes to switch on FreeScout's
own live chat: the Chats folder, Chat Mode, the chat list, the realtime refresh
and audio cue, and the rule that a chat conversation is never answered by
email. All of that is already in core and inert behind one filter,
`channels.list`.

On top of that it carries the visitor's half, which core does not have: a chat
bubble (`Public/js/widget.js`), seven public endpoints behind the `open`
middleware group (status, start, offline, send, poll, end, leave) and three
tables of its own: visitor sessions, blocks, and agent presence. The only
credential a visitor ever holds is a random token for one conversation. Only
its hash is stored, it lives in the tab rather than the browser, and nothing
the visitor types — a name, an email address — leads to a conversation.
Starting a conversation has its own rate limit per address, and the forms carry
a field only software fills in.

Modelled on Live Helper Chat: the bubble asks whether an agent is around and
offers a chat or a message form, and a message left while nobody is available
becomes an ordinary email conversation. A visitor who has waited a minute
without an answer is told somebody will be with them, or that nobody is
available. Agents can block a visitor's address or email from the
conversation, for a day, a week, a month or for good, and administrators lift
blocks from Manage → Blocked chat visitors.

For agents it adds a waiting-chat count, an in-page alert that opens the chat
it announces, a mark in the chat list saying whether the visitor is still
there, lines in the conversation when a visitor ends the chat, leaves, comes
back or is blocked, and an idle sweep counted from the agent's last reply.
Customer chat messages are kept out of the notification bell, which the alert
replaces.

Each side sees that the other is typing: three dots in the bubble while an
agent writes a reply, and "the customer is typing…" above the conversation
while the visitor writes. Only that somebody is typing travels, never the
text — Live Helper Chat shows agents the visitor's unsent words, and that is
not copied — and an agent writing a note shows the visitor nothing.

An agent's chat reply stays on the page. Core reloads the whole conversation
after every chat reply; the module lets core validate and send it as before,
and replaces only the reload. The message is added to the conversation, the
status and assignee are brought up to date, and the editor stays open and
empty. If that fails, core's reload runs.

The bubble polls at the pace of the conversation: every second and a half
while something happened in the last two minutes and the tab is visible,
every five seconds when it is quiet, every thirty while the tab is hidden,
and at once when the visitor starts typing or comes back to the tab. A poll
costs the server about ten milliseconds of processor time, so polling fast
only while it matters costs about what a fixed three-second poll did. Push
over a persistent connection was considered and left for when volume needs
it: long polling would hold one of the few PHP workers per waiting visitor.
On an agent's chat page, the three-second typing beat also names the
visitor's newest message, and the page shows it without waiting for core's
five-second realtime poll.

Messages carry delivery receipts: one tick once sent, two once the other
side's screen fetched it. Agents also see "Seen at 10:32" under the newest reply
that was on the visitor's screen while it could be looked at — the bubble open
in a visible tab. The visitor sees ticks only, never whether or when an agent
read their message: support chat vendors split on that, "seen but no answer"
reads as being ignored, and the typing dots already say somebody is replying.
`GESOFT_LIVE_CHAT_RECEIPTS_SEEN_TO_VISITOR` turns it on. Receipts ride on the
bubble's poll and the agent's beat, so no request is added. Live Helper Chat keeps a status per
message and can leave one stuck at delivered; this keeps one "up to message N"
pointer per state per conversation, which only moves forward, is clamped to
messages that exist, and leaves core's `threads` table alone.

Adding them found a lost-reply bug in the bubble: a visitor message sent
between two polls moved the poll pointer past an agent reply written in
between, and that reply never reached the visitor. The bubble now keeps its
pointer and puts every message in the order the server wrote it.

In Chat Mode a chat reads like a chat window for the agent: the editor fixed at
the bottom of the window, and the messages above it in a pane of their own,
oldest at the top, where a new message appears just above the editor and the
rest move up. A first version let the page scroll instead, which in a short
chat left the editor under the last message, moving down the screen with every
new one. Core's page is built for email, with the editor
above the messages and the newest first; Live Helper Chat, LiveChat, Intercom,
Front and Help Scout itself since 2024 put a conversation the other way, and
Zendesk lets a helpdesk choose per channel. Email conversations, and chats
outside Chat Mode, keep core's page. Nothing in core changes: the server marks
the page, CSS turns the list of messages around on screen from the first paint,
so core's own realtime handler, which puts a new message first, now shows it
last, and the editor is moved before core's start-up shows it. An agent reading
further up stays on what they are reading and is told "New messages" instead of
being pulled down. The customer panel, with Start Remote Support, scrolls in
its own box beside the messages.
Messages there are drawn as a chat console draws them rather than as email
cards: compact bubbles, the visitor's on the left and the agents' on the
right, notes still yellow, the name once for a run of messages from the same
side, and the time and receipt inside the bubble, beside the text when there is
room. Core's card — a 45 px photo, an 18 px name, a relative date, a status
line — took about 120 px for a one-line message; a bubble takes 32, 52 with
the name above it. Live Helper Chat's operator window is built the same way.
System lines such as "the customer left" are small and centred, and a
message's menu shows when the pointer is on it.
`GESOFT_LIVE_CHAT_NEWEST_AT_BOTTOM=false` brings back core's layout.

The first characters of an agent's reply are no longer drawn over the editor's
placeholder. Core's editor, Summernote 0.8.9, hides it on a change event it
debounces by 100 ms, which fires only once typing pauses; the module hides it
on the keystroke itself.

A chat reply sent with Enter no longer ends in an empty line. Core sends from
a keydown handler on the document, after Summernote's own handler on the
editor has already started a new paragraph, so every such reply was stored
ending in `<div><br></div>` (main.js marks its `preventDefault()` there "Does
not work"). The visitor never saw it, the bubble trims text; the agent saw a
blank line under each reply. The module cancels the key on its way down, under
the checks core sends on, and in Chat Mode hides the empty line in replies
stored before.

The chat also has a page of its own, `/chat`, for a link rather than an
embed: the same bubble, open from the start and filling the window, so a site
needs no bubble and the helpdesk no second hostname.

Nothing that comes in through the bubble gets an auto-reply. The email address
on a chat or a message form was typed by whoever filled it in, so an
auto-reply would let a stranger make the helpdesk email any address. A chat is
recognised by its channel; a message form conversation is marked in its meta
while core creates it, before the event that decides on an auto-reply.

Agents are not emailed about every line of a chat. FreeScout emails an agent
about each customer message in a conversation assigned to them, which on the
test instance was thirty emails from eight chats. An agent who has FreeScout
open gets none, because the in-page alert tells them. One who is away gets one
email when the visitor starts waiting.

Starting a conversation is limited per address, 20 in ten minutes, because
an address is often a whole office. What a single chat may send is limited
separately.

The agent-facing words are translated into Romanian through FreeScout's own
JSON translations, as are those of `GesoftRemoteSupport`. The bubble speaks
Romanian and English, and messages written into a chat automatically — the
remote support link, "are you still there?" — go out in the visitor's
language.

An instance's colours reach the chat too, without touching the module: the
operator side reads its accent colours from CSS properties a brand stylesheet
can set, and the bubble takes `data-color`, `data-theme` (`light` or `dark`
instead of following the visitor's system) and `data-stylesheet` (loaded inside
its shadow root) from its script tag, or `GESOFT_LIVE_CHAT_COLOR`, `_THEME` and
`_STYLESHEET` on the `/chat` page.

A development-only artisan command and demo page, both off unless
`GESOFT_LIVE_CHAT_DEV_TOOLS=true`, exist to exercise it on a test instance.

What it depends on in core, and the test that detects an upgrade breaking it,
are in [`docs/live-chat-core-contract.md`](docs/live-chat-core-contract.md). The
pure tests run in CI; the end-to-end suites in `Modules/GesoftLiveChat/Tests/e2e/`
need a running test instance and are run by hand — never against production.

### `Modules/GesoftBranding`

Makes the product name, logo, favicon and footer configurable from the
environment, and puts the AGPL source-code link in the footer. It patches no
core file: FreeScout already exposes `layout.title.name`, `layout.favicon`,
`layout.header_logo` and `footer.text` as filters, and there is a single layout
behind both the login screen and the application, so filtering those four covers
the whole interface.

It also replaces the login page's banner (the logo, or a banner of its own),
sets the browser's theme colour, and adds one stylesheet of the instance's after
every other, so an instance can take on its own colours without a core or module
file changing. The stylesheet must be a file under `public/`: core combines the
stylesheets itself, and one it cannot read drops them all.

With `HELPDESK_BRAND_MAIL=true` the mail a customer gets when an agent replies
is framed: a bar in the brand colour, a logo, a card, and a footer naming the
request, with an optional `[TAG #number]` in front of the subject. It goes
through core's `reply_email.header`, `.footer` and `.css` hooks only, so the
reply separator, the quoted history and the message marker that core reads a
customer's answer by stay as core writes them.

The repository ships neutral placeholder marks only. An instance's own files go
in `public/brand/`, which git ignores. See [`TRADEMARKS.md`](TRADEMARKS.md).

## Core changes

### `.gitignore`

Upstream ignores `/Modules` wholesale, because upstream distributes modules
separately. This fork *is* its modules, so the rule is narrowed: ours are
tracked by name and everything else in `Modules/` stays ignored. The same commit
adds ignores for operator branding assets and for deployment leftovers
(database dumps, backups) that have no business in a public repository.

A later commit widened the environment-file rule. Upstream ignores `.env` and
`.env.testing` by name; Laravel reads more than those, and a file called
`.env.local` or `.env.production` holds exactly the same credentials. Every
variant is ignored now, with the three committed templates named back in.

That is the only file outside `Modules/`, `public/brand/` and `scripts/` that
this fork modifies. Nothing else in core is touched: no UI rewrite, no Laravel
internals, no ticket engine, no auth, no schema.

## Tooling

`scripts/secret-scan.sh` — checks this fork's own changes for environment files,
private keys and assigned credentials before they can be pushed. It scans our
diff rather than the whole vendored tree, because a scan that prints thousands
of lines is a scan people learn to ignore. Exit status is usable as a hook or a
CI gate:

    ln -s ../../scripts/secret-scan.sh .git/hooks/pre-commit

## Assets

`public/brand/default-logo.svg`, `public/brand/default-favicon.svg` — deliberately
anonymous placeholders, so a build that configures nothing looks like nobody's
product in particular rather than like someone else's.

## Not here

RustDesk, the `helpdesk-rust` service, and Live Helper Chat are separate
components with their own repositories and their own licences. See
[`SOURCE-COMPLIANCE.md`](SOURCE-COMPLIANCE.md).
