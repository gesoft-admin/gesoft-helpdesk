<?php
/**
 * The upgrade tripwire.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/core-contract.php
 *
 * `GesoftLiveChat` is a module that borrows FreeScout's own chat engine. That
 * is its whole advantage and its whole risk: it depends on symbols and hooks in
 * core that upstream never promised to keep. A unit test of our code cannot see
 * any of that — our filters would go on returning the right values while
 * nothing listens to them.
 *
 * So this asserts the core side directly, by reading the source tree. It is
 * deliberately blunt: names and hook strings, not line numbers, because line
 * numbers move on every upstream release and would cry wolf until somebody
 * stopped reading the output.
 *
 * What a failure here means: after an upstream merge, some part of live chat
 * has stopped working and it will not announce itself. See
 * docs/live-chat-core-contract.md for what each entry costs us.
 */

$root = dirname(__DIR__, 3);

$contract = [
    [
        'what'  => 'chat mode is unlocked by a registered channel',
        'file'  => 'app/Misc/Helper.php',
        'needs' => ['function isChatModeAvailable', 'CustomerChannel::getChannels()'],
        'cost'  => 'No Chats folder, no Chat Mode. Chat conversations still exist but are invisible as chat.',
    ],
    [
        'what'  => 'the channel list is a filter a module can answer',
        'file'  => 'app/CustomerChannel.php',
        'needs' => ["Eventy::filter('channels.list'"],
        'cost'  => 'The switch is gone. Everything above it stays off whatever the module does.',
    ],
    [
        'what'  => 'a conversation can be of type chat',
        'file'  => 'app/Conversation.php',
        'needs' => ['const TYPE_CHAT', 'function isChat', 'function isInChatMode', 'function getChats'],
        'cost'  => 'No chat conversation type, no chat list. The whole approach fails.',
    ],
    [
        'what'  => 'a chat conversation is never answered by email',
        'file'  => 'app/Listeners/SendReplyToCustomer.php',
        'needs' => ['isChat()', 'chat_conversation.send_reply'],
        'cost'  => 'Worst case of the set: agent replies would be emailed to a customer who has no address, silently, instead of reaching the chat.',
    ],
    [
        'what'  => 'a background action reaches module listeners',
        'file'  => 'app/Jobs/TriggerAction.php',
        'needs' => ['Eventy::action'],
        'cost'  => 'The reply hook never fires. Replies go nowhere and nothing reports it.',
    ],
    [
        'what'  => 'a conversation can be created programmatically',
        'file'  => 'app/Conversation.php',
        'needs' => ['public static function create($data, $threads, $customer)'],
        'cost'  => 'No way to turn an incoming visitor message into a conversation without writing rows by hand.',
    ],
    [
        'what'  => 'a customer can exist without an email address',
        'file'  => 'app/Customer.php',
        'needs' => ['function createWithoutEmail', 'function addChannel'],
        'cost'  => 'Anonymous visitors cannot be represented. Every chat would need an address up front.',
    ],
    [
        'what'  => 'threads can be appended to a conversation',
        'file'  => 'app/Thread.php',
        'needs' => ['public static function createExtended', 'const TYPE_CUSTOMER'],
        'cost'  => 'No way to add the second and later messages of a chat.',
    ],
    [
        'what'  => 'a customer thread refreshes the operator view by itself',
        'file'  => 'app/Observers/ThreadObserver.php',
        'needs' => ['Conversation::refreshConversations'],
        'cost'  => 'The chat list stops updating on its own. Agents would have to reload to see a new message.',
    ],
    [
        'what'  => 'realtime carries chat, with its audio cue',
        'file'  => 'app/Events/RealtimeChat.php',
        'needs' => ['isChatModeAvailable', 'chats_html'],
        'cost'  => 'No live chat list and no sound. Chat becomes email with extra steps.',
    ],
    [
        'what'  => 'the channel has a display name',
        'file'  => 'app/Conversation.php',
        'needs' => ["Eventy::filter('channel.name'"],
        'cost'  => 'Cosmetic only: the channel shows as an empty label.',
    ],
    [
        'what'  => 'public routes can skip session and CSRF',
        'file'  => 'app/Http/Kernel.php',
        'needs' => ["'open' =>"],
        'cost'  => 'Not used yet. F2B needs it for the widget endpoints; without it they would demand a CSRF token the customer cannot have.',
    ],
    [
        'what'  => 'a module can raise core\'s floating alert',
        'file'  => 'public/js/main.js',
        'needs' => ['function showFloatingAlert', 'alert-floating', "$('body:first').append(html)", 'function getGlobalAttr'],
        'cost'  => 'The in-page alert for a new chat message disappears, or appears but no longer opens the chat when clicked.',
    ],
    [
        'what'  => 'the conversation page says which conversation it shows',
        'file'  => 'resources/views/conversations/view.blade.php',
        'needs' => ['data-conversation_id'],
        'cost'  => 'An agent already reading a chat is alerted about the message in front of them.',
    ],
    [
        'what'  => 'a mailbox has a chats entry point',
        'file'  => 'routes/web.php',
        'needs' => ["'conversations.chats'"],
        'cost'  => 'An alert for several chats at once opens a single chat instead of the list.',
    ],
    [
        'what'  => 'a notification can be cancelled before it is sent',
        'file'  => 'overrides/laravel/framework/src/Illuminate/Notifications/NotificationSender.php',
        'needs' => ['Events\NotificationSending', ') !== false'],
        'cost'  => 'Every customer chat message goes back to filing an entry under the bell as well as raising the alert.',
    ],
    [
        'what'  => 'bell notifications carry their conversation and thread',
        'file'  => 'app/Notifications/WebsiteNotification.php',
        'needs' => ['class WebsiteNotification', 'public $conversation', 'public $thread'],
        'cost'  => 'The guard cannot tell a chat message from anything else, so it cancels nothing.',
    ],
    [
        'what'  => 'realtime bell notifications carry their conversation and thread',
        'file'  => 'app/Notifications/BroadcastNotification.php',
        'needs' => ['class BroadcastNotification', 'public $conversation', 'public $thread'],
        'cost'  => 'Chat messages reappear in the open bell menu and play core\'s sound on top of the module\'s.',
    ],
    [
        'what'  => 'a module can write and word its own line items',
        'file'  => 'app/Thread.php',
        'needs' => [
            'const TYPE_LINEITEM', 'const PERSON_CUSTOMER', 'const SOURCE_TYPE_WEB',
            'public static function create($conversation, $type, $body, $data = [], $save = true)',
            "\$data['action_type']", "\$data['created_by_customer_id']",
            "Eventy::filter('thread.action_text'", "Eventy::filter('thread.action_person'",
        ],
        'cost'  => '"Customer left / ended / came back" lines stop being written, or appear blank or signed "System".',
    ],
    [
        'what'  => 'line items do not count as replies',
        'file'  => 'app/Observers/ThreadObserver.php',
        'needs' => ['in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])'],
        'cost'  => 'Every "customer left" line would restart the idle clock and change who spoke last, so the sweep would close or spare the wrong chats.',
    ],
    [
        'what'  => 'line items are shown through getActionText',
        'file'  => 'resources/views/conversations/partials/thread.blade.php',
        'needs' => ['TYPE_LINEITEM', 'getActionText('],
        'cost'  => 'The lines are stored but the conversation shows nothing for them.',
    ],
    [
        'what'  => 'chat list items carry their conversation id and name element',
        'file'  => 'resources/views/mailboxes/partials/chat_list.blade.php',
        'needs' => ['data-chat_id="{{ $chat->id }}"', 'class="folder-name"'],
        'cost'  => 'The "visitor is here / left" mark disappears from the chat list.',
    ],
    [
        'what'  => 'the rate limiter still counts its window in minutes',
        'file'  => 'vendor/laravel/framework/src/Illuminate/Cache/RateLimiter.php',
        'needs' => ['public function tooManyAttempts($key, $maxAttempts, $decayMinutes = 1)', 'public function hit($key, $decayMinutes = 1)'],
        'cost'  => 'Laravel 5.8 changed this to seconds: the start limit would silently shrink from ten minutes to ten seconds.',
    ],
    [
        'what'  => 'modules can add to the Manage menu',
        'file'  => 'resources/views/layouts/app.blade.php',
        'needs' => ["@action('menu.manage.append')"],
        'cost'  => 'Manage → Blocked chat visitors disappears; blocks can no longer be lifted before they expire.',
    ],
    [
        'what'  => 'modules can add conversation actions',
        'file'  => 'resources/views/conversations/view.blade.php',
        'needs' => ['conversation.append_action_buttons'],
        'cost'  => '"Ask if the customer is still there" and "Block visitor…" disappear from More Actions.',
    ],
    [
        'what'  => 'a mailbox can list who has access to it',
        'file'  => 'app/Mailbox.php',
        'needs' => ['public function userIdsHavingAccess()'],
        'cost'  => 'The bubble cannot tell whether anybody is available and breaks instead of offering the message form.',
    ],
    [
        'what'  => 'a module can ship JSON translations',
        'file'  => 'vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php',
        'needs' => ['function loadJsonTranslationsFrom($path)'],
        'cost'  => 'The module fails to boot.',
    ],
    [
        'what'  => 'an agent message knows who wrote it',
        'file'  => 'app/Thread.php',
        'needs' => ['public function created_by_user_cached()'],
        'cost'  => 'The bubble stops showing the agent\'s first name above their messages.',
    ],
    [
        'what'  => 'modules can add to a conversation above its messages',
        'file'  => 'resources/views/conversations/view.blade.php',
        'needs' => ["@action('conversation.before_threads'"],
        'cost'  => '"The customer is typing…" disappears from the conversation.',
    ],
    [
        'what'  => 'the reply form says whether it holds a note',
        'file'  => 'resources/views/conversations/view.blade.php',
        'needs' => ['form-reply', 'name="is_note"'],
        'cost'  => 'The visitor no longer sees that an agent is typing. The script reports nothing when it cannot find the field, so a note is never taken for a reply.',
    ],
    [
        'what'  => 'the reply editor is Summernote, read the same way',
        'file'  => 'public/js/main.js',
        'needs' => ['note-editable', 'function isNote()', "name='is_note'"],
        'cost'  => 'The visitor no longer sees that an agent is typing.',
    ],
    [
        'what'  => 'a chat reply is sent through fsAjax and core reloads after it',
        'file'  => 'public/js/main.js',
        'needs' => [
            'function fsAjax(data, url, success_callback, no_loader, error_callback, custom_options)',
            "data += '&action=send_reply'",
            "fsAjax(data, laroute.route('conversations.ajax'), function(response) {",
            'var fs_processing_send_reply', 'var fs_reply_changed', 'function loaderHide()',
            '.attachments-upload:first :input, .attachments-upload:first li',
            "#conv-status .conv-status li.active a:first", "#conv-assignee .conv-user li.active a:first",
        ],
        'cost'  => 'An agent\'s chat reply reloads the whole conversation again, or leaves the editor disabled after sending.',
    ],
    [
        'what'  => 'the conversation page lists messages by id under one container',
        'file'  => 'resources/views/conversations/partials/thread.blade.php',
        'needs' => ['id="thread-{{ $thread->id }}"'],
        'cost'  => 'A sent chat reply does not appear until the page is reloaded.',
    ],
    [
        'what'  => 'the reply form carries its draft id, inside the chat layout',
        'file'  => 'resources/views/conversations/view.blade.php',
        'needs' => ['id="conv-layout-main"', 'name="thread_id"', 'conv-type-{{ strtolower($conversation->getTypeName()) }}'],
        'cost'  => 'A second chat reply is refused as "already sent", or the page reloads after every reply again.',
    ],
    [
        'what'  => 'a chat reply in chat mode leaves no undo message behind',
        'file'  => 'app/Http/Controllers/ConversationsController.php',
        'needs' => ['if ($conversation->isChat() && \Helper::isChatMode()) {', 'if ($can_undo) {'],
        'cost'  => 'Without a reload, an "Email sent — Undo" message waits in the session and appears on the next page the agent opens.',
    ],
    [
        'what'  => 'a module can veto an auto-reply',
        'file'  => 'app/Listeners/SendAutoReply.php',
        'needs' => ["Eventy::filter('autoreply.should_send', true, \$conversation)"],
        'cost'  => 'With auto-replies on, the message form becomes a way to make the helpdesk email any address a stranger types.',
    ],
    [
        'what'  => 'a new conversation can be marked before core announces it',
        'file'  => 'app/Thread.php',
        'needs' => ["Eventy::filter('conversation.created_by_customer', \$conversation, \$thread, \$customer)", 'event(new CustomerCreatedConversation($conversation, $thread));'],
        'cost'  => 'Message form conversations are no longer recognised as the form\'s, and get auto-replies again.',
    ],
    [
        'what'  => 'a module\'s new conversation goes through createExtended and carries meta',
        'file'  => 'app/Conversation.php',
        'needs' => ['Thread::createExtended($thread, $conversation, $customer, false)', 'public function setMeta($key, $value, $save = false)', 'public function getMeta($key, $default = null)'],
        'cost'  => 'The message form\'s mark is not stored, or core stops announcing conversations the module creates.',
    ],
    [
        'what'  => 'a module can drop a notification per subscriber',
        'file'  => 'app/Subscription.php',
        'needs' => ["Eventy::filter('subscription.filter_out', false, \$subscription, \$thread)"],
        'cost'  => 'Agents are emailed about every line of every chat again: thirty emails from eight chats on the test instance.',
    ],
    [
        'what'  => 'a web message can become an email conversation',
        'file'  => 'app/Conversation.php',
        'needs' => ['const TYPE_EMAIL', 'const SOURCE_TYPE_WEB'],
        'cost'  => 'A message left while nobody is available can no longer be turned into a conversation answered by email.',
    ],
    [
        'what'  => 'a module can add a line under a message, and a message says its type and id',
        'file'  => 'resources/views/conversations/partials/thread.blade.php',
        'needs' => [
            "@action('thread.meta', \$thread, \$loop, \$threads, \$conversation, \$mailbox)",
            '<div class="thread thread-type-{{ $thread->getTypeName() }}" id="thread-{{ $thread->id }}" data-thread_id="{{ $thread->id }}">',
        ],
        'cost'  => 'Receipts disappear from agents\' chat replies, or an agent reading the chat no longer marks the visitor\'s messages seen.',
    ],
    [
        'what'  => 'the conversation page: editor in the header, hidden until start-up, messages after',
        'file'  => 'resources/views/conversations/view.blade.php',
        'needs' => [
            '<div id="conv-layout-header">', '<div class="conv-action-wrapper">',
            '<div class="conv-block conv-reply-block conv-action-block hidden">',
            '<div id="conv-layout-customer">', '<div id="conv-layout-main">',
            "@action('conversation.before_threads', \$conversation)",
        ],
        'cost'  => 'In Chat Mode the editor stays above the messages while they read newest at the bottom, or is seen jumping there after the page shows.',
    ],
    [
        'what'  => 'core adds a new message first in the list, and starts chat mode after module scripts',
        'file'  => 'public/js/main.js',
        'needs' => ["\$('#conv-layout-main').prepend(", '$(".conv-reply").click();', "\$('#conv-subject .switch-to-note').click(function(e) {", 'function switchToNote()'],
        'cost'  => 'A colleague\'s reply shows at the top of a chat that reads newest at the bottom, or "switch to a note" stops working under the moved editor.',
    ],
    [
        'what'  => 'module scripts load before the page\'s own start-up, beside a sidebar column',
        'file'  => 'resources/views/layouts/app.blade.php',
        'needs' => ["\\Eventy::filter('javascripts'", "@yield('javascript')", '<div class="layout-2col">', '<div class="sidebar-2col">'],
        'cost'  => 'The editor is shown above the messages before it moves, or on a small screen the chat list no longer comes along as the page scrolls.',
    ],
];

$pass = 0; $fail = 0;
echo "GesoftLiveChat — FreeScout core contract\n\n";

foreach ($contract as $item) {
    $path = $root.'/'.$item['file'];
    $src = is_file($path) ? file_get_contents($path) : false;

    if ($src === false) {
        $fail++;
        printf("  FAIL  %-58s missing file: %s\n", $item['what'], $item['file']);
        continue;
    }

    $missing = [];
    foreach ($item['needs'] as $needle) {
        if (strpos($src, $needle) === false) {
            $missing[] = $needle;
        }
    }

    if ($missing) {
        $fail++;
        printf("  FAIL  %-58s %s\n", $item['what'], $item['file']);
        foreach ($missing as $m) {
            printf("        not found: %s\n", $m);
        }
        printf("        cost: %s\n", $item['cost']);
    } else {
        $pass++;
        printf("  ok    %-58s %s\n", $item['what'], $item['file']);
    }
}

printf("\n%d held, %d broken\n", $pass, $fail);

if ($fail) {
    echo "\nThe live chat module depends on core behaviour that has moved.\n";
    echo "Read docs/live-chat-core-contract.md before merging this upstream release.\n";
}

exit($fail ? 1 : 0);
