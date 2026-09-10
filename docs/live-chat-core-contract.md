# The core contract for live chat

`Modules/GesoftLiveChat` does not implement a chat engine. It borrows
FreeScout's, which already contains the whole operator half — the Chats folder,
Chat Mode, the chat list, the realtime refresh, the audio cue, and the rule that
a chat conversation is never answered by email — inert behind a single filter.

That is the module's advantage and its only real risk. **We depend on symbols
and hooks upstream never promised to keep**, and if one of them moves, our own
tests keep passing: our filters go on returning the right values while nothing
listens to them. The failure is silent by construction.

So the contract is enforced by a test that reads core's source rather than
ours: `Modules/GesoftLiveChat/Tests/core-contract.php`, run in CI. It checks
names and hook strings, never line numbers — line numbers move on every
upstream release and a check that cries wolf is a check nobody reads.

**Run it after every upstream merge, before deciding the merge is done.**

## What we depend on

Baseline: FreeScout `1.8.239`.

| Depends on | Where | If it goes |
|---|---|---|
| `Helper::isChatModeAvailable()` = `count(CustomerChannel::getChannels())` | `app/Misc/Helper.php` | No Chats folder, no Chat Mode. Chat conversations exist and are invisible as chat. |
| `channels.list` filter | `app/CustomerChannel.php` | The switch itself. Everything above stays off whatever the module does. |
| `Conversation::TYPE_CHAT`, `isChat()`, `isInChatMode()`, `getChats()` | `app/Conversation.php` | No chat type and no chat list. The approach fails outright. |
| Chat branch in `SendReplyToCustomer`, and `chat_conversation.send_reply` | `app/Listeners/SendReplyToCustomer.php` | **The worst one.** Agent replies would be emailed — silently — to a customer who has no address, instead of reaching the chat. |
| `TriggerAction` calling `Eventy::action` | `app/Jobs/TriggerAction.php` | The reply hook never fires. Replies go nowhere and nothing reports it. |
| `Conversation::create($data, $threads, $customer)` | `app/Conversation.php` | No supported way to turn a visitor message into a conversation. |
| `Customer::createWithoutEmail()`, `addChannel()` | `app/Customer.php` | Anonymous visitors cannot be represented at all. |
| `Thread::createExtended()`, `TYPE_CUSTOMER` | `app/Thread.php` | No way to append the second and later messages. |
| `ThreadObserver` calling `Conversation::refreshConversations()` | `app/Observers/ThreadObserver.php` | The chat list stops updating by itself; agents must reload. |
| `RealtimeChat`, its `chats_html` payload and audio cue | `app/Events/RealtimeChat.php` | No live list, no sound. Chat becomes email with extra steps. |
| `channel.name` filter | `app/Conversation.php` | Cosmetic: the channel shows as an empty label. |
| The `open` middleware group | `app/Http/Kernel.php` | Not used yet. F2B needs it, or the widget's endpoints would demand a CSRF token a customer cannot have. |
| `showFloatingAlert()` appending a plain `.alert-floating` to the body, and `getGlobalAttr()` | `public/js/main.js` | The in-page alert for a new chat message disappears, or appears but no longer opens the chat on click. |
| `data-conversation_id` on the conversation page's body | `resources/views/conversations/view.blade.php` | An agent already reading a chat is alerted about the message in front of them. |
| The `conversations.chats` route | `routes/web.php` | An alert for several chats at once opens one chat instead of the list. The endpoint checks for the route, so the badge survives. |
| `NotificationSending` answered with `false` cancels a notification | `overrides/laravel/framework/src/Illuminate/Notifications/NotificationSender.php` | Every customer chat message is filed under the bell again as well as raising the alert. |
| `WebsiteNotification` and `BroadcastNotification` exposing `$conversation` and `$thread` | `app/Notifications/` | The guard cannot recognise a chat message, so it cancels nothing; core's chat sound plays on top of the module's. |

## Two behaviours that are contracts even though nothing enforces them

**The reply arrives late, through the queue.** Core schedules
`chat_conversation.send_reply` with `Conversation::UNDO_TIMOUT` — 15 seconds —
and puts it on the `default` queue. So a listener sees an agent's reply about
fifteen seconds after it is sent, **and only if a queue worker is running**. A
box with no worker looks exactly like a broken hook. F2B will want the delay
shorter, and core exposes `backgound_action.dispatch_delay` for that (the typo
is upstream's); nothing in F2A changes it.

**`Log::info` is discarded by default, on every FreeScout installation.**
`config/app.php` sets `'log_level' => env('APP_LOG_LEVEL', 'error')`, so
anything below `error` is dropped unless the environment lowers it. During F2A
this made a working listener look like a missing one: the hook fired, the
closure ran, and nothing reached the log. Set `APP_LOG_LEVEL=info` on any
instance where these lines are meant to be read.

This is not only ours. `Modules/GesoftRemoteSupport` records its successful
starts and closes with `\Log::info` too, so on a default installation that
audit trail has never been written — only its `\Log::error` paths appear.

**The channel code is ours to pick and nobody arbitrates it.**
`customers.channel` is an unsigned tinyint and FreeScout keeps no registry, so
installing a paid channel module later could collide with our `100`. Nothing
would warn: two channels would share a code and `channel.name` would answer for
whichever filter ran last.

## What this document is not

It is not a promise that the approach survives an upgrade. It is the means of
finding out quickly, and of knowing what specifically broke rather than that
"chat stopped working".
