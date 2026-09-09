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
    }
}

namespace {
    $CONFIG = ['gesoftlivechat.channel' => 100, 'gesoftlivechat.dev_tools' => false];
    $REGISTERED_COMMANDS = [];
    $LOGGED = [];

    function config($key, $default = null) { global $CONFIG; return array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default; }
    function __($s, $r = []) { return $s; }
    function env($k, $d = null) { return $d; }
    function config_path($p = '') { return '/tmp/config/'.$p; }
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

    class Log { public static function info($msg, $ctx = []) { global $LOGGED; $LOGGED[] = [$msg, $ctx]; } }

    class AppStub {
        public $console;
        public function __construct($console) { $this->console = $console; }
        public function runningInConsole() { return $this->console; }
    }

    require __DIR__.'/../Providers/GesoftLiveChatServiceProvider.php';

    $pass = 0; $fail = 0;
    function check($label, $got, $want) {
        global $pass, $fail;
        if ($got === $want) { $pass++; printf("  ok    %-58s %s\n", $label, var_export($got, true)); }
        else { $fail++; printf("  FAIL  %-58s got: %s  wanted: %s\n", $label, var_export($got, true), var_export($want, true)); }
    }

    function boot($console = false) {
        global $LOGGED, $REGISTERED_COMMANDS;
        $LOGGED = []; $REGISTERED_COMMANDS = [];
        Eventy::$stub = new EventyStub();
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

    // The development command must not exist unless somebody turned it on, and
    // must never be reachable outside the CLI.
    boot(true);
    check('dev command absent when the flag is off', $REGISTERED_COMMANDS, []);

    $CONFIG['gesoftlivechat.dev_tools'] = true;
    boot(true);
    check('dev command present when the flag is on', count($REGISTERED_COMMANDS), 1);
    boot(false);
    check('dev command absent outside the console', $REGISTERED_COMMANDS, []);
    $CONFIG['gesoftlivechat.dev_tools'] = false;

    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail ? 1 : 0);
}
