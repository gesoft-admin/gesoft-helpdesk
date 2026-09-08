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
customer link, polls for the RustDesk ID, and closes the session on demand.

It talks to that service over HTTP with an operator token that never leaves the
server — no route, view or JSON response in the module emits it. See
[`docs/architecture/remote-support.md`](docs/architecture/remote-support.md).

Imported here byte-for-byte from the installation that ran the production pilot,
so the behaviour in this repository is the behaviour that was validated.

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

That is the only file outside `Modules/` and `public/brand/` that this fork
modifies. Nothing else in core is touched: no UI rewrite, no Laravel internals,
no ticket engine, no auth, no schema.

## Assets

`public/brand/default-logo.svg`, `public/brand/default-favicon.svg` — deliberately
anonymous placeholders, so a build that configures nothing looks like nobody's
product in particular rather than like someone else's.

## Not here

RustDesk, the `helpdesk-rust` service, and Live Helper Chat are separate
components with their own repositories and their own licences. See
[`SOURCE-COMPLIANCE.md`](SOURCE-COMPLIANCE.md).
