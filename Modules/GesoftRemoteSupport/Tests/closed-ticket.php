<?php
/**
 * A closed ticket closes the remote session its customer never started.
 *
 * Run it on a test instance, from the FreeScout root, as the web user:
 *
 *     sudo -u www-data php Modules/GesoftRemoteSupport/Tests/closed-ticket.php --test-instance
 *
 * The first checks need no backend. The rest talks to the helpdesk-rust
 * service the module is configured for: it opens a throwaway
 * conversation, starts real sessions, reports a customer ID the way the wrapper
 * does, changes the ticket the ways core does, and deletes the conversation at
 * the end. Never point it at production.
 */

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Conversation;
use Modules\GesoftRemoteSupport\Entities\RemoteSession;
use Modules\GesoftRemoteSupport\Services\ClosedTicket;
use Modules\GesoftRemoteSupport\Services\HelpdeskClient;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-66s %s\n", $label, var_export($got, true)); }
    else { $fail++; printf("  FAIL  %-66s got: %s  wanted: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

echo "GesoftRemoteSupport — a closed ticket\n\n";

check('closing from the status menu ends it', ClosedTicket::ends(Conversation::STATUS_CLOSED, false), true);
check('marking as spam ends it', ClosedTicket::ends(Conversation::STATUS_SPAM, false), true);
check('closing with a reply does not: the reply may carry the link', ClosedTicket::ends(Conversation::STATUS_CLOSED, true), false);
check('pending does not', ClosedTicket::ends(Conversation::STATUS_PENDING, false), false);
check('active does not', ClosedTicket::ends(Conversation::STATUS_ACTIVE, false), false);
check('a status given as a string is read as a number', ClosedTicket::ends((string) Conversation::STATUS_CLOSED, false), true);

// FreeScout runs as APP_ENV=production on the test instance too, so the
// environment cannot tell them apart. Whoever runs this has to say so.
if (!in_array('--test-instance', $argv, true) || parse_url((string) config('app.url'), PHP_URL_HOST) === 'helpdesk.gesoft.ro') {
    echo "\nstopping before anything touches a ticket: pass --test-instance, and never on production\n";
    exit($fail ? 1 : 0);
}

$client = new HelpdeskClient();
if (!$client->isConfigured()) {
    echo "\nthe module is not configured: the rest needs a helpdesk-rust service\n";
    exit($fail ? 1 : 0);
}

$template = Conversation::where('state', Conversation::STATE_PUBLISHED)->orderBy('id', 'desc')->first();
$user = App\User::where('role', App\User::ROLE_ADMIN)->first();
if (!$template || !$user) {
    echo "\nno conversation or admin to work with\n";
    exit(1);
}

$conversation = $template->replicate();
$conversation->subject = 'closed-ticket test '.date('c');
$conversation->status = Conversation::STATUS_ACTIVE;
$conversation->state = Conversation::STATE_PUBLISHED;
$conversation->save();

/** Start a session for the conversation the way Start does. */
function start($client, $conversation) {
    $reply = $client->createSession(RemoteSession::hostnameMarker($conversation).' closed-ticket test');
    $client->approve($reply['id']);
    $session = RemoteSession::forConversation($conversation->id);
    $session->fill([
        'status' => RemoteSession::STATUS_ACTIVE, 'helpdesk_session_id' => $reply['id'], 'code' => $reply['code'],
        'remote_status' => RemoteSession::REMOTE_APPROVED, 'remote_id' => null, 'registration' => null,
        'started_by_user_id' => null, 'started_at' => now(), 'closed_at' => null,
    ]);
    $session->save();

    return $session;
}

/** Report a customer ID the way the wrapper does. */
function reportId($session) {
    $base = rtrim((string) config('gesoftremotesupport.api_base'), '/');
    (new GuzzleHttp\Client())->post($base.'/api/ready', ['json' => ['code' => $session->code, 'rustdesk_id' => '123456789']]);
}

function reopen($conversation) {
    Conversation::where('id', $conversation->id)->update(['status' => Conversation::STATUS_ACTIVE, 'state' => Conversation::STATE_PUBLISHED]);
    $conversation->refresh();
}

function open($client, $session) {
    return $client->findById($session->helpdesk_session_id) !== null;
}

try {
    echo "\nconversation {$conversation->id}\n\n";

    $s = start($client, $conversation);
    $conversation->changeStatus(Conversation::STATUS_CLOSED, $user);
    check('closed from the menu, no ID: the backend session is closed', open($client, $s), false);
    check('  and the panel row says closed', RemoteSession::find($s->id)->status, RemoteSession::STATUS_CLOSED);

    reopen($conversation);
    $s = start($client, $conversation);
    reportId($s);
    $conversation->changeStatus(Conversation::STATUS_CLOSED, $user);
    check('closed from the menu after the customer reported: left open', open($client, $s), true);
    $row = RemoteSession::find($s->id);
    check('  the row stays active', $row->status, RemoteSession::STATUS_ACTIVE);
    check('  and carries the ID it was left open for', $row->remote_id, '123456789');
    $client->close($s->helpdesk_session_id);

    reopen($conversation);
    $s = start($client, $conversation);
    Conversation::where('id', $conversation->id)->update(['status' => Conversation::STATUS_CLOSED]);
    $conversation->refresh();
    \Eventy::action('conversation.status_changed', $conversation, $user, true, Conversation::STATUS_ACTIVE);
    check('closed by a reply, no ID: left open', open($client, $s), true);
    $client->close($s->helpdesk_session_id);

    reopen($conversation);
    $s = start($client, $conversation);
    $conversation->changeStatus(Conversation::STATUS_SPAM, $user);
    check('marked as spam, no ID: closed', open($client, $s), false);

    reopen($conversation);
    $s = start($client, $conversation);
    $conversation->deleteToFolder($user);
    check('deleted, no ID: closed', open($client, $s), false);

    reopen($conversation);
    $s = start($client, $conversation);
    $real = config('gesoftremotesupport.api_base');
    config(['gesoftremotesupport.api_base' => 'http://localhost:9']);
    $threw = false;
    try {
        $conversation->changeStatus(Conversation::STATUS_CLOSED, $user);
    } catch (\Throwable $e) {
        $threw = true;
    }
    config(['gesoftremotesupport.api_base' => $real]);
    check('backend unreachable: the ticket still closes', $threw, false);
    check('  the status change was saved', (int) Conversation::find($conversation->id)->status, Conversation::STATUS_CLOSED);
    check('  and the session is left to its deadline', RemoteSession::find($s->id)->status, RemoteSession::STATUS_ACTIVE);
    $client->close($s->helpdesk_session_id);
} finally {
    RemoteSession::where('conversation_id', $conversation->id)->delete();
    $conversation->deleteForever();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
