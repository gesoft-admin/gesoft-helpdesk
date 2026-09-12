# Source and component boundaries

What this repository is, what it is not, and where the rest lives. This is a
description of how the system is put together, not legal advice.

## This repository

The software here derives from **FreeScout**, which is licensed under
**AGPL-3.0**. The fork keeps that licence: `LICENSE` is upstream's, unchanged.

Everything Gesoft adds that runs inside FreeScout — the modules under
`Modules/`, the branding mechanism, the placeholder assets and the one narrowed
`.gitignore` rule — is part of the same work and is offered under the same
AGPL-3.0 terms.

    repository   https://github.com/gesoft-admin/gesoft-helpdesk
    upstream     https://github.com/freescout-help-desk/freescout
    baseline     tag upstream-1.8.239 (commit 3b471b17cfc9aa3f7047241cb34ab91eb1a790c9)

The AGPL asks that people who interact with the software over a network be
offered its corresponding source. The branding module puts a **Source code**
link in the footer of every page for exactly that reason, pointing at
`HELPDESK_SOURCE_URL`. An operator running a modified build is expected to point
that at their own repository rather than at this one.

### Identifying what a given instance runs

Every deployment should be traceable to a commit here. In practice: deploy from
a tag, and record the tag and commit alongside the deployment. FreeScout shows
its own version to administrators in the footer; the fork's commit is the part
that has to be recorded deliberately.

### Not part of this source

- **Branding assets** belonging to the operator of an instance. See
  [`TRADEMARKS.md`](TRADEMARKS.md).
- **Deployment configuration and secrets** — `.env`, tokens, credentials, keys.
  None of it is committed and all of it is ignored.
- **Customer data**, uploads, logs, database contents.

## Separate components

Three things this helpdesk talks to are separate programs with their own
repositories and their own licences. None of their source or binaries is copied
into this repository.

### RustDesk

The remote-desktop client and server. Gesoft's build lives in its own
repository, forked from upstream RustDesk, which is **AGPL-3.0**:

    https://github.com/gesoft-admin/rustdesk

No RustDesk source, client binary or wrapper executable is present here.

### `helpdesk-rust`

A separate service that mints support sessions, hands out the customer's
download, and opens the firewall for the duration of a session. FreeScout
reaches it **over HTTP only** — it is not imported, linked or vendored here, and
its licence is not decided by this repository. The API contract it exposes is
described in [`docs/architecture/remote-support.md`](docs/architecture/remote-support.md).

### Live Helper Chat

Not a component of this system, and worth saying so because an earlier design
made it one. The customer chat is `Modules/GesoftLiveChat` in this repository,
AGPL-3.0 like the rest of the fork; nothing here talks to Live Helper Chat and
no line of its code is copied. It is a separate project under **Apache-2.0**
whose source was read as a reference for behaviour — polling intervals, the
offline form, receipts, bans. Reading a project is not using it, and it imposes
no obligation here. See
[`docs/architecture/live-chat.md`](docs/architecture/live-chat.md).

## Summary

| component | where | licence |
|---|---|---|
| FreeScout core + Gesoft modules | this repository | AGPL-3.0 |
| Live chat (`Modules/GesoftLiveChat`) | this repository | AGPL-3.0 |
| RustDesk client/server | `gesoft-admin/rustdesk` | AGPL-3.0 |
| `helpdesk-rust` service | separate repository | not set by this repository |
| Branding assets | supplied by the operator | not licensed here |
