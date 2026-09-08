# Remote support

How an agent gets from a conversation to a remote-desktop session, and where the
boundary between this repository and the rest sits.

    FreeScout
      └── Modules/GesoftRemoteSupport
            │  server-side HTTP, operator token in a header
            ▼
          helpdesk-rust                    (separate service, separate repository)
            │  mints a session, serves the customer download,
            │  opens the firewall for that customer's address only
            ▼
          wrapper on the customer's machine
            │
            ▼
          RustDesk                          (separate repository)

## What lives here

Only the FreeScout module. It:

- creates a session for a conversation and stores the session id, code, customer
  link and expiry in its own table;
- polls for status until the customer's client reports its RustDesk ID;
- shows the agent the code, the link and the ID;
- closes the session.

## What does not live here

**No RustDesk client, no wrapper executable, no `helpdesk-rust` binary.** The
module never ships, embeds or proxies any of them. It makes HTTP requests and
renders what comes back.

## The boundary, concretely

The module holds one credential: an operator token for the `helpdesk-rust`
service, sent as an `X-Ops-Token` request header. Two properties matter and both
are enforced in the module rather than assumed:

- **The token is server-side only.** No route, view or JSON response emits it,
  and the client's own error path redacts it out of any body it logs.
- **The customer's browser never talks to the helpdesk API through FreeScout.**
  The customer gets a link to the service's own public address; the agent's
  browser talks only to FreeScout's own routes, which are CSRF-protected and
  behind the normal session.

## Configuration

Set in the FreeScout `.env`, never committed:

    GESOFT_REMOTE_SUPPORT_API_BASE=https://helpdesk.example.com
    GESOFT_REMOTE_SUPPORT_OPS_TOKEN=<the service's operator token>
    GESOFT_REMOTE_SUPPORT_CUSTOMER_BASE=https://helpdesk.example.com

`API_BASE` is the address of the service *as reachable from this server*;
`CUSTOMER_BASE` is the address the customer uses. They are separate because in a
split-horizon deployment they are not the same name, and only the first has to
resolve here. Left empty, the customer link falls back to `API_BASE`.

See `Modules/GesoftRemoteSupport/Config/config.php` for the rest, including the
connect timeout and the start lock.
