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

The agent-facing words are translated into Romanian through FreeScout's own
JSON translations, as are those of `GesoftRemoteSupport`. The bubble speaks
Romanian and English, and messages written into a chat automatically — the
remote support link, "are you still there?" — go out in the visitor's
language.

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

The repository ships neutral placeholder marks only. See [`TRADEMARKS.md`](TRADEMARKS.md).

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
