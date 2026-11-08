# Attended browser viewer integration (lab)

The vendored `upstream/` tree is CortenDesk's standalone webclient at the
commit recorded in `upstream/PROVENANCE.json`, with its AGPL license and NOTICE.
It is not the CortenDesk server/console. Files under `upstream/` are kept
byte-identical to that commit. `gesoft-patches.mjs` holds Gesoft's
modifications (2026-11-08): resource and error-handling bounds against a
malicious connected peer (zstd output, cursor size, video/audio queues, chat
history, cursor conversions, invalid handshake key). `build.mjs` applies them
while bundling, fails if any no longer matches upstream exactly, and lists
them in the published `provenance.json`. They do not change the wire protocol.
The upstream logo from `public/rdclient/logo.png` is also included and recorded
in provenance. The build rewrites the two logo URLs to the module's own asset
path, so it does not require a root `/rdclient` route.

The module defaults to `GESOFT_REMOTE_SUPPORT_VIEWER=false`. Enable it only
with an exact HTTPS `GESOFT_REMOTE_SUPPORT_VIEWER_ORIGIN`, matching FreeScout's
canonical APP_URL and secure session cookies. Keep the desktop flow available.
Only active READY sessions can launch a viewer. READY is a reported ID, not
proof of online status or of user consent.

`gateway.mjs` runs as an unprivileged, read-only Node process on loopback,
behind a same-origin TLS reverse proxy. Configure fixed hbbs/hbbr WS upstreams;
never expose their unguarded WS listeners publicly. The internal authorization
endpoint must use the canonical FreeScout Host and HTTPS FastCGI handling.
It must be reachable only on loopback. Cookies and grant URLs must not be
logged. Gateway audit logs contain actor/session/peer and transport events,
not desktop content, passwords, or the fact that the remote user accepted.

Authorization is bound to the current FreeScout login session, conversation,
backend support session and peer. The gateway rechecks it every two seconds,
fails closed on errors, and limits signaling/relay pairing to that peer.
End-to-end encrypted desktop payloads are opaque to the gateway. Closing a
viewer or failing its transport does not close the underlying support session.
Separate file-transfer, terminal and camera connection modes are refused in
this first stage. The initial agent keeps its existing attended Accept policy.

Build and regression commands live in the private `helpdesk-rust` repository:

```
scripts/ops/build-web-viewer.sh
scripts/ops/test-web-viewer-deploy.sh   # helpdesk-fw only; backup first
scripts/ops/test-web-viewer.sh          # repeatable OWASP + panel regression
```

To reproduce without that repository: copy this directory into an isolated
build directory; use Node 24, run `npm ci --ignore-scripts` in `upstream/`,
then `node build.mjs` and `node --test gateway.test.cjs` from this directory.
Copy `build/assets/*` to the module's `Public/viewer/`. Bundle the module's
corresponding source (including this directory, excluding dependencies and
generated assets) as `Public/viewer/source.tar.gz`. Keep LICENSE, NOTICE and
provenance with the distributed assets. Run builds/tests without network after
dependencies are installed. Never run an upstream prebuilt image blindly.

The OWASP checks are scoped regression controls, not certification or a full
source/malware audit. A clean dependency advisory scan does not establish trust
in a dependency. Production needs review of upstream code/dependencies and the
remaining browser/Windows compatibility matrix, plus a dedicated security gate.
