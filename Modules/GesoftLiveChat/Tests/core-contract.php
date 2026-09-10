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
