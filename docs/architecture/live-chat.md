# Live chat

Built, and running. This describes what `Modules/GesoftLiveChat` is, because an
earlier version of this file described something else: a bridge to Live Helper
Chat, a separate application that would own the chat and hand finished
conversations to FreeScout. That is not what was built, and a document
describing a design the code does not have is worse than no document.

## What changed the decision

FreeScout core already contains the operator's half of a chat — a Chats folder,
Chat Mode, the realtime channel, and the rule that a chat conversation is not
answered by email. All of it is locked behind one filter, `channels.list`:
register a channel and it appears. That was found on 2026-09-09 and it turned a
two-application design into a module.

What a separate chat application would have added — a second database, a second
operator interface, a second set of accounts, and a synchronisation problem
between them — it would have added for a customer base that is already in
FreeScout.

Live Helper Chat remains the reference the behaviour is measured against
(polling intervals, the offline form, delivery receipts, bans, what an operator
is shown while a visitor types). Its source was read; none of it is copied, and
this system does not talk to it.

## The components

**`Modules/GesoftLiveChat`** — the whole feature, in this repository, AGPL-3.0
like the rest of the fork:

- `Public/js/widget.js`, the bubble a site embeds, or `/chat`, the same bubble
  as a page of its own;
- `Http/Controllers/ChatController`, the visitor's endpoints — start, send,
  poll, end, leave, status, and the message form for when nobody is available;
- `Http/Controllers/AgentController`, what the agent's interface needs that
  core does not provide: the waiting count anywhere in FreeScout, "are you
  still there?", blocking a visitor;
- a conversation of core's own chat type, on a channel of this module's.

## The flow

    customer's website (or /chat)
      └── bubble (Public/js/widget.js, shadow DOM)
            │  public HTTP, one opaque token per conversation
            ▼
          Modules/GesoftLiveChat      (this repository, AGPL-3.0)
            ▼
          FreeScout conversation      (core's chat type and Chat Mode)
            ▼
          Modules/GesoftRemoteSupport (unchanged)

## Rules the visitor's half keeps

These are properties of the code, and `Http/routes.php` says the same at the
top of the file.

**The token is the only credential, and it opens one conversation.** It is 32
random bytes, handed out once, stored only as a SHA-256 hash, and it addresses
a conversation — never a customer, and never anything found by what the visitor
typed. An earlier design looked the customer up by the email in the form, which
let anybody who typed somebody else's address into that form read and write
that person's open chat.

**Nothing internal is ever returned.** The poll hands back published customer
and agent messages. Notes, drafts, hidden threads and line items are excluded
by the query, not by the caller remembering to ask.

**Nothing arrives as markup.** What a visitor types is escaped before it is
stored, because the agent's browser renders thread bodies as HTML. What an
agent writes is flattened to text before it is sent out, because the bubble has
no business rendering markup either.

**Everything is limited.** A ceiling per address on every visitor route, a
tighter budget per address on starting conversations, and two windows per
conversation on sending. Agents are not limited.

**Cross-origin is a list, never a wildcard.** Empty configuration means
same-origin only. A site that embeds the bubble is named in
`GESOFT_LIVE_CHAT_ORIGINS`, and nothing else is answered with permission.

**Remote support stays in FreeScout.** A chat is a conversation, and the
remote-support panel is already there — one integration, one place.

## Licence boundary

The module is part of this work and is AGPL-3.0 like the rest. Live Helper Chat
is a separate project under Apache-2.0; reading it is not using it, and nothing
in this repository derives from its code.
