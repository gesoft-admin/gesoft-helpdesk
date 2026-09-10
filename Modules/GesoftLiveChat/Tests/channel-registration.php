<?php
/**
 * What the module contributes to FreeScout, checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/channel-registration.php
 *
 * Same reasoning as the branding test: what matters here is pure. Given a
 * configuration, does the provider register the one filter that switches chat
 * on, does it name the channel, and does it receive an agent reply in the shape
 * core hands it. Booting the framework to ask that would test the framework.
 *
 * The half that cannot be tested this way — that FreeScout's own chat UI does
 * what the source says it does — is not a unit test at all. It is the F2A
 * validation, and it is recorded in docs/live-chat-core-contract.md.
 */

namespace Illuminate\Support {
    class ServiceProvider {
        public $app;
        public function __construct($app = null) { $this->app = $app; }
        public function publishes($paths, $group = null) {}
        public function mergeConfigFrom($path, $key) {}
        public function commands($commands) { global $REGISTERED_COMMANDS; $REGISTERED_COMMANDS = $commands; }
        public function loadViewsFrom($paths, $namespace) {}
        public function loadMigrationsFrom($path) { global $MIGRATIONS; $MIGRATIONS = $path; }
    }
}

namespace App {
    class Thread { const TYPE_CUSTOMER = 1; const TYPE_MESSAGE = 2; const TYPE_LINEITEM = 4; }
}

namespace App\Notifications {
    class WebsiteNotification {
        public $conversation; public $thread;
        public function __construct($conversation, $thread) { $this->conversation = $conversation; $this->thread = $thread; }
    }
    class BroadcastNotification {
        public $conversation; public $thread;
        public function __construct($conversation, $thread) { $this->conversation = $conversation; $this->thread = $thread; }
    }
}

namespace {
    $CONFIG = ['gesoftlivechat.channel' => 100, 'gesoftlivechat.dev_tools' => false];
    $REGISTERED_COMMANDS = [];
    $LOGGED = [];
    $MIGRATIONS = null;

    function config($key, $default = null) { global $CONFIG; return array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default; }
    function __($s, $r = []) { return $s; }
    function env($k, $d = null) { return $d; }
    function config_path($p = '') { return '/tmp/config/'.$p; }
    function resource_path($p = '') { return '/tmp/resources/'.$p; }
    class Config { public static function get($key, $default = null) { return $key === 'view.paths' ? ['/tmp/views'] : $default; } }
    function now() { return new class { public function toRfc3339String() { return '2026-09-09T00:00:00+00:00'; } }; }

    class EventyStub {
        public $filters = [];
        public $actions = [];
        public function addFilter($name, $cb, $prio = 20, $args = 1) { $this->filters[$name] = $cb; }
        public function addAction($name, $cb, $prio = 20, $args = 1) { $this->actions[$name] = $cb; }
        public function filter($name, ...$args) {
            return isset($this->filters[$name]) ? call_user_func_array($this->filters[$name], $args) : $args[0];
        }
        public function action($name, ...$args) {
            if (isset($this->actions[$name])) { call_user_func_array($this->actions[$name], $args); }
        }
    }
    class Eventy { public static $stub; public static function __callStatic($m, $a) { return call_user_func_array([self::$stub, $m], $a); } }
    Eventy::$stub = new EventyStub();

    // Laravel's event dispatcher, reduced to what the provider uses.
    class Event { public static $listeners = []; public static function listen($event, $cb) { self::$listeners[$event][] = $cb; } }

    class Log { public static function info($msg, $ctx = []) { global $LOGGED; $LOGGED[] = [$msg, $ctx]; } }

    class ConversationStub {
        private $chat;
        public function __construct($chat) { $this->chat = $chat; }
        public function isChat() { return $this->chat; }
    }

    class CustomerStub {
        private $name;
        public function __construct($name) { $this->name = $name; }
        public function getFullName($email_if_empty = false) { return $this->name; }
    }

    class AppStub {
        public $console;
        public function __construct($console) { $this->console = $console; }
        public function runningInConsole() { return $this->console; }
    }

    require __DIR__.'/../Support/Presence.php';
    require __DIR__.'/../Providers/GesoftLiveChatServiceProvider.php';

    $pass = 0; $fail = 0;
    function check($label, $got, $want) {
        global $pass, $fail;
        if ($got === $want) { $pass++; printf("  ok    %-58s %s\n", $label, var_export($got, true)); }
        else { $fail++; printf("  FAIL  %-58s got: %s  wanted: %s\n", $label, var_export($got, true), var_export($want, true)); }
    }

    function boot($console = false) {
        global $LOGGED, $REGISTERED_COMMANDS, $MIGRATIONS;
        $LOGGED = []; $REGISTERED_COMMANDS = []; $MIGRATIONS = null;
        Eventy::$stub = new EventyStub();
        Event::$listeners = [];
        (new \Modules\GesoftLiveChat\Providers\GesoftLiveChatServiceProvider(new AppStub($console)))->boot();
    }

    echo "GesoftLiveChat — channel registration\n\n";

    // The one thing that switches FreeScout's chat machinery on. Core computes
    // isChatModeAvailable() as count(Eventy::filter('channels.list', [])), so an
    // empty result here means no Chats folder and no Chat Mode anywhere.
    boot();
    $channels = \Eventy::filter('channels.list', []);
    check('channels.list contains our channel', in_array(100, $channels, true), true);
    check('channels.list is non-empty, so chat mode is available', count($channels) > 0, true);

    // Core appends to whatever other modules already added; ours must not
    // replace theirs, or installing a second channel module would silently
    // switch one of them off.
    boot();
    $with_other = \Eventy::filter('channels.list', [7]);
    check('an existing channel survives ours', $with_other, [7, 100]);

    // How the channel reads wherever core prints a name: the customer profile
    // tag and the conversation's channel label.
    boot();
    check('channel.name for our code', \Eventy::filter('channel.name', '', 100), 'Web Chat');
    check('channel.name for our code as a string', \Eventy::filter('channel.name', '', '100'), 'Web Chat');
    check('channel.name leaves other codes alone', \Eventy::filter('channel.name', 'WhatsApp', 7), 'WhatsApp');

    // Where an agent's reply to a chat conversation arrives. Core refuses to
    // email a chat conversation and calls this instead; if it stops arriving,
    // replies go nowhere at all and nothing else reports it.
    boot();
    $conversation = (object) ['id' => 42];
    $customer     = (object) ['id' => 7];
    // Newest first, the way core hands them over: getThreads() orders by
    // created_at descending. Taking the last element would return 88 here —
    // the customer's opening message — and read as perfectly sensible.
    $replies      = [(object) ['id' => 101], (object) ['id' => 88]];
    \Eventy::action('chat_conversation.send_reply', $conversation, $replies, $customer);

    check('the reply hook was received', count($LOGGED), 1);
    $ctx = $LOGGED[0][1] ?? [];
    check('  conversation id', $ctx['conversation_id'] ?? null, 42);
    check('  the newest thread, not the oldest', $ctx['thread_id'] ?? null, 101);
    check('  customer id', $ctx['customer_id'] ?? null, 7);
    check('  reply count', $ctx['replies'] ?? null, 2);

    // The idle sweep is the module working and runs everywhere. The
    // conversation maker is a tool and must not exist unless somebody turned it
    // on. Neither is ever registered outside the console.
    boot(true);
    check('sweep registered with the dev flag off', count($REGISTERED_COMMANDS), 1);
    check('  and it is the sweep', strpos($REGISTERED_COMMANDS[0] ?? '', 'SweepChats') !== false, true);

    $CONFIG['gesoftlivechat.dev_tools'] = true;
    boot(true);
    check('dev tool added when the flag is on', count($REGISTERED_COMMANDS), 1);
    check('  and it is the maker', strpos($REGISTERED_COMMANDS[0] ?? '', 'MakeChatConversation') !== false, true);
    boot(false);
    check('nothing registered outside the console', $REGISTERED_COMMANDS, []);
    $CONFIG['gesoftlivechat.dev_tools'] = false;

    // The sweep has to reach core's own cron, or it never runs.
    boot();
    $sched = new class { public $added = []; public function command($c) { $this->added[] = $c; return $this; }
        public function everyMinute() { return $this; } public function withoutOverlapping() { return $this; }
        public function runInBackground() { return $this; } };
    \Eventy::filter('schedule', $sched);
    check('sweep added to the schedule', $sched->added, ['gesoftlivechat:sweep-chats']);

    // The sessions table has to be created on install, or every start fails.
    boot();
    check('migrations are loaded from the module', strpos((string) $MIGRATIONS, 'Database/Migrations') !== false, true);

    // A customer's chat message is announced by the in-page alert, so the bell
    // must not file it as well — and nothing else may be cancelled with it.
    // Laravel treats a listener answer of false as "do not send"; null lets the
    // notification through.
    boot();
    $guards = Event::$listeners['Illuminate\Notifications\Events\NotificationSending'] ?? [];
    check('a guard is registered before notifications are sent', count($guards), 1);
    $guard = $guards[0] ?? function () { return 'missing'; };
    $sending = function ($notification) use ($guard) { return $guard((object) ['notification' => $notification]); };

    $chat = new ConversationStub(true);
    $email = new ConversationStub(false);
    $from_customer = (object) ['type' => \App\Thread::TYPE_CUSTOMER];
    $from_agent = (object) ['type' => \App\Thread::TYPE_MESSAGE];

    check('bell entry for a customer chat message is cancelled',
        $sending(new \App\Notifications\WebsiteNotification($chat, $from_customer)), false);
    check('  and its realtime copy', $sending(new \App\Notifications\BroadcastNotification($chat, $from_customer)), false);
    check('an email conversation keeps its bell entry',
        $sending(new \App\Notifications\WebsiteNotification($email, $from_customer)), null);
    check('an agent reply in a chat keeps its bell entry',
        $sending(new \App\Notifications\WebsiteNotification($chat, $from_agent)), null);
    check('other notifications are not touched', $sending(new \stdClass()), null);

    // The lines written into a chat when a visitor ends it, leaves, or comes
    // back: what they say, and whose name they carry. Core only names a person
    // for line items an agent made and would otherwise print "System".
    boot();
    $line = function ($action) {
        return (object) ['type' => \App\Thread::TYPE_LINEITEM, 'action_type' => $action, 'customer_cached' => new CustomerStub('Ana Pop')];
    };
    check('ended line', \Eventy::filter('thread.action_text', '', $line(100)), ':person ended the chat');
    check('left line', \Eventy::filter('thread.action_text', '', $line(101)), ':person left the chat');
    check('came back line', \Eventy::filter('thread.action_text', '', $line(102)), ':person came back to the chat');
    check("core's own line items keep their text", \Eventy::filter('thread.action_text', 'core text', $line(1)), 'core text');
    check('a message with a stray action type is not reworded',
        \Eventy::filter('thread.action_text', 'x', (object) ['type' => 1, 'action_type' => 100]), 'x');
    check('the line is signed with the customer', \Eventy::filter('thread.action_person', '', $line(101)), 'Ana Pop');
    check("core's line items keep core's person", \Eventy::filter('thread.action_person', 'Dan', $line(1)), 'Dan');

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail ? 1 : 0);
}
