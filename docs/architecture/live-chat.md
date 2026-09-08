# Live chat

Planned, not built. This describes the boundary the future bridge has to
respect, so that the shape is decided before the code exists.

## The components

**Live Helper Chat** is a separate application: its own code, its own database,
its own operator interface, licensed **Apache-2.0**. It provides the bubble on
the customer's website and the chat engine behind it. It is not copied into this
repository and it is not a FreeScout module.

**`Modules/GesoftLiveChat`** — the bridge, to be built here. Its job is to turn a
finished or escalated chat into a FreeScout conversation:

- map a chat's visitor to a FreeScout customer;
- create or update the conversation;
- keep the link to the chat id, so the two can be reconciled later;
- import the transcript;
- carry the escalation.

## The flow

    customer's website
      └── chat bubble (Live Helper Chat widget)
            ▼
          Live Helper Chat            (separate app, separate DB, Apache-2.0)
            │  authenticated server-side HTTP
            ▼
          Modules/GesoftLiveChat      (this repository, AGPL-3.0)
            ▼
          FreeScout conversation
            ▼
          Modules/GesoftRemoteSupport (unchanged)

## Rules the bridge must keep

**Remote support stays in FreeScout.** A chat escalates to a conversation, and
the remote-support button is already there. Nothing about RustDesk is duplicated
into the chat application's interface — one integration, one place.

**The customer's browser never calls a FreeScout administrative API.** The
widget runs on the customer's site and is, by construction, hostile input. The
only path from chat to helpdesk is server-side, from Live Helper Chat's host to
this one, authenticated.

**No FreeScout credentials in the widget.** Nothing the widget ships may contain
a token, a session, or an internal URL of this system.

## Licence boundary

A bridge module living here is part of this work and is AGPL-3.0 like the rest.
An extension living inside Live Helper Chat is a separate work under that
project's Apache-2.0 terms, and belongs in its own repository.
