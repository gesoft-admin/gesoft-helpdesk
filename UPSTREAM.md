# Upstream

This repository is a fork of FreeScout.

| | |
|---|---|
| upstream | https://github.com/freescout-help-desk/freescout |
| forked from | tag `1.8.239`, commit `3b471b17cfc9aa3f7047241cb34ab91eb1a790c9` |
| upstream branch | `dist` — the release line, with `vendor/` committed |
| upstream release date | 2026-09-04 |
| baseline date | 2026-09-08 |
| baseline tag here | `upstream-1.8.239` |

`upstream-1.8.239` points at the unmodified upstream commit. Every change of
ours is a descendant of it, so `git diff upstream-1.8.239..main` is the complete
answer to "what did Gesoft change".

## Why `dist` and not `master`

FreeScout ships two lines. `master` is the source; `dist` is the release branch
with `vendor/` committed, and it is what an installation is actually checked out
from — including the one this fork was verified against. Following `dist` means
our tree is directly deployable and our merges land against the same commits an
upstream user would get.

## Branches

| branch | what it is |
|---|---|
| `main` | this fork's product line. Branched from `upstream-1.8.239`. |
| `dist` | mirror of upstream's release branch. Not modified here. |
| `master` | mirror of upstream's source branch. Not modified here. |

Remotes, as configured in a working clone:

    origin    https://github.com/gesoft-admin/gesoft-helpdesk
    upstream  https://github.com/freescout-help-desk/freescout

## Verification of the baseline

The fork point was not assumed from the version string. The running production
installation was compared against the upstream tag directly: it is a git
checkout of upstream at `3b471b17`, and its working tree differs
from that commit only in 296 file-mode bits (`100644` → `100755`) left by the
installer. With `core.fileMode` ignored, `git diff` against the tag is empty —
no file content differs. The single addition is `Modules/GesoftRemoteSupport`.

Keeping upstream current is described in [`docs/upstream-update.md`](docs/upstream-update.md).
