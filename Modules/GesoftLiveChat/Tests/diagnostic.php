<?php
/**
 * What this helpdesk will accept as a diagnostic report, checked without
 * booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/diagnostic.php
 *
 * The endpoint behind this is the one place where an application's server can
 * put content in front of an agent, so the schema is the security boundary and
 * not a convenience. Everything below is one of two questions: does a report
 * that is not a report get refused, and does a report that carries a credential
 * get it dropped.
 */

require __DIR__.'/../Support/Diagnostic.php';

use Modules\GesoftLiveChat\Support\Diagnostic;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-66s %s\n", $label, json_encode($got)); }
    else { $fail++; printf("  FAIL  %-66s got: %s  wanted: %s\n", $label, json_encode($got), json_encode($want)); }
}

echo "GesoftLiveChat — what a diagnostic report may be\n\n";

$minimum = [
    'incident_id' => 'GX-K7M4-D92Q-R5TB-9WXY',
    'reported_at' => '2026-09-12T10:00:00+00:00',
    'application' => 'app-backend',
    'status'      => 500,
];

// ------------------------------------------------------------- the minimum

check('a report with the four required fields is accepted',
    Diagnostic::accept($minimum) !== null, true);
check('  and comes back with exactly those four',
    array_keys((array) Diagnostic::accept($minimum)), ['incident_id', 'reported_at', 'application', 'status']);

foreach (['incident_id', 'reported_at', 'application', 'status'] as $missing) {
    $short = $minimum;
    unset($short[$missing]);
    check('without '.$missing.' it is not a report', Diagnostic::accept($short), null);
}

check('an empty body is not a report', Diagnostic::accept([]), null);
check('a string is not a report', Diagnostic::accept('incident'), null);
check('a list is not a report', Diagnostic::accept(['a', 'b']), null);

// ------------------------------------------------------------ the incident

check('an incident that is not ours is refused',
    Diagnostic::accept(['incident_id' => 'hello'] + $minimum), null);
check('  including one with a path in it',
    Diagnostic::accept(['incident_id' => '../../etc/passwd'] + $minimum), null);
check('  and one with a dot, which a filename would take',
    Diagnostic::accept(['incident_id' => 'GX-AAAA.sh'] + $minimum), null);
check('  and one too short to be random',
    Diagnostic::accept(['incident_id' => 'GX-AA'] + $minimum), null);

// ------------------------------------------------------- unknown fields

check('a field this helpdesk has not been taught is a refusal, not a shrug',
    Diagnostic::accept($minimum + ['cookies' => 'a=b']), null);
check('  and so is one that only looks harmless',
    Diagnostic::accept($minimum + ['notes' => 'hello']), null);
check('  and one inside the exception',
    Diagnostic::accept($minimum + ['exception' => ['class' => 'E', 'locals' => ['a' => 1]]]), null);
// A frame is not a named object but one of a list of them, so a bad one is
// dropped and the report survives: losing a line of a stack is not a reason to
// lose the report that says which fault it was.
$bad_frame = Diagnostic::accept($minimum + ['stack' => [
    ['file' => 'backend/a.php', 'args' => ['x']],
    ['file' => 'backend/b.php', 'line' => '12'],
]]);
check('an unknown field in a stack frame drops the frame', count($bad_frame['stack']), 1);
check('  and keeps the frame beside it', $bad_frame['stack'][0]['file'], 'backend/b.php');
check('  and the report itself', $bad_frame['status'], 500);

// A frame whose file was dropped is not a frame.
$no_file = Diagnostic::accept($minimum + ['stack' => [['file' => 'Bearer sk-live-0123456789abcdef', 'line' => '4']]]);
check('a frame with no file left is dropped whole', isset($no_file['stack']), false);

// ------------------------------------------------------------------- types

check('a status that is not a number is dropped, and the report with it',
    Diagnostic::accept(['status' => 'kaput'] + $minimum), null);
check('a numeric string status is read as a number',
    Diagnostic::accept(['status' => '404'] + $minimum)['status'], 404);
check('truncated must be a boolean',
    isset(Diagnostic::accept($minimum + ['truncated' => 'yes'])['truncated']), false);
check('  and is kept when it is one',
    Diagnostic::accept($minimum + ['truncated' => true])['truncated'], true);

// ------------------------------------------------------------------- caps

$long = Diagnostic::accept($minimum + ['path' => '/'.str_repeat('a', 900)]);
check('a path longer than the ceiling is cut to it', strlen($long['path']), 500);

$frames = [];
for ($i = 0; $i < 80; $i++) { $frames[] = ['file' => 'backend/a.php', 'line' => (string) $i]; }
check('a stack longer than the ceiling is cut to it',
    count(Diagnostic::accept($minimum + ['stack' => $frames])['stack']), 40);

$events = [];
for ($i = 0; $i < 80; $i++) { $events[] = ['level' => 'error', 'message' => 'line '.$i]; }
check('  and so is a list of events',
    count(Diagnostic::accept($minimum + ['events' => $events])['events']), 20);

// -------------------------------------------------------------- the secrets

$secrets = [
    'a bearer token'      => 'Authorization: Bearer sk-live-0123456789abcdef',
    'a bare bearer'       => 'Bearer abcdefghijklmnop',
    'a JWT'               => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abcdefghij',
    'a password'          => "SELECT * FROM users WHERE password='hunter2'",
    'an api key'          => 'api_key=AKIAIOSFODNN7EXAMPLE',
    'an AWS key'          => 'AKIAIOSFODNN7EXAMPLE',
    // Assembled rather than written out: a file that contains the header
    // verbatim is a file the repository's own secret scan stops on, and being
    // told about a test fixture every time teaches people to ignore it.
    'a private key'       => '-----BEGIN RSA PRIVATE'.' KEY----- MIIEpAIBAAKCAQEA',
    'a cookie'            => 'Cookie: PHPSESSID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'a session cookie'    => 'PHPSESSID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'a csrf token'        => 'csrf_token: 9f8a7b6c5d4e3f2a',
    'a database url'      => 'mysql://root:secretpassword@db.internal/app',
    'an identity cookie'  => '_identity-backend=abc123def456',
];

foreach ($secrets as $what => $value) {
    check($what.' is recognised', Diagnostic::looksSecret($value), true);
}

$innocent = [
    'a route'          => 'evcon/orders/save',
    'a class'          => 'yii\\db\\IntegrityException',
    'a file and line'  => 'backend/controllers/OrdersController.php',
    'a plain message'  => 'Undefined index: total in the summary view',
    'a token count'    => 'expected 3 tokens, found 4',
    'a url'            => 'https://example.ro/orders/412',
];

foreach ($innocent as $what => $value) {
    check($what.' is not', Diagnostic::looksSecret($value), false);
}

// A secret in an allowlisted field: the field goes, not the report -- unless
// the field was one the report cannot do without.
$poisoned = Diagnostic::accept($minimum + [
    'exception' => ['class' => 'RuntimeException', 'summary' => 'Bearer sk-live-0123456789abcdef leaked'],
    'path'      => '/orders/save',
]);
check('a credential in the summary drops the summary', isset($poisoned['exception']['summary']), false);
check('  and keeps the exception class, which is the useful part', $poisoned['exception']['class'], 'RuntimeException');
check('  and the rest of the report', $poisoned['path'], '/orders/save');

$all_poison = Diagnostic::accept(['application' => 'Bearer sk-live-0123456789abcdef'] + $minimum);
check('a credential in a required field refuses the whole report', $all_poison, null);

$events_poison = Diagnostic::accept($minimum + ['events' => [
    ['level' => 'error', 'message' => 'Cookie: PHPSESSID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ['level' => 'error', 'message' => 'order 412 could not be saved'],
]]);
check('a credential in one event drops that event', count($events_poison['events']), 1);
check('  and keeps the one beside it', $events_poison['events'][0]['message'], 'order 412 could not be saved');

// ------------------------------------------------------------- the artefact

check('control characters are stripped',
    Diagnostic::accept(['application' => "app\x07-backend"] + $minimum)['application'], 'app-backend');

$rendered = Diagnostic::render(Diagnostic::accept($minimum));
check('the file is JSON', json_decode($rendered, true) !== null, true);
check('  pretty enough to read', strpos($rendered, "\n    \"incident_id\"") !== false, true);
check('  and ends with a newline', substr($rendered, -1), "\n");

check('the filename is ours, and has the incident in it',
    Diagnostic::filename('GX-K7M4-D92Q-R5TB-9WXY'), 'gesoft-diagnostic-GX-K7M4-D92Q-R5TB-9WXY.json');
check('the type is fixed', Diagnostic::MIME, 'application/json');

// Field order does not follow the caller's.
$shuffled = Diagnostic::accept([
    'status' => 500, 'application' => 'app-backend',
    'reported_at' => '2026-09-12T10:00:00+00:00', 'incident_id' => 'GX-K7M4-D92Q-R5TB-9WXY',
]);
check('two reports of the same shape are the same file',
    array_keys($shuffled), ['incident_id', 'reported_at', 'application', 'status']);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail ? 1 : 0);
