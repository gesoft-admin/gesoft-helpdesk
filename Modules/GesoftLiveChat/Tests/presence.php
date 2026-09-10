<?php
/**
 * The rules of a visitor's session, checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/presence.php
 *
 * These are the decisions that keep a public endpoint from handing one
 * person's conversation to another, so they are tested as plain functions
 * with plain numbers.
 */

require __DIR__.'/../Support/Presence.php';

use Modules\GesoftLiveChat\Support\Presence;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-62s %s\n", $label, var_export($got, true)); }
    else { $fail++; printf("  FAIL  %-62s got: %s  wanted: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

echo "GesoftLiveChat — session rules\n\n";

// The credential.
$a = Presence::newToken();
$b = Presence::newToken();
check('a token is 64 hex characters', Presence::looksLikeToken($a), true);
check('two tokens differ', $a !== $b, true);
check('the stored hash is not the token', Presence::hash($a) !== $a, true);
check('the hash is stable for the same token', Presence::hash($a), Presence::hash($a));
check('an old 32-character token is refused', Presence::looksLikeToken(bin2hex(random_bytes(16))), false);
check('uppercase hex is refused', Presence::looksLikeToken(strtoupper($a)), false);
check('an array is refused', Presence::looksLikeToken([$a]), false);
check('null is refused', Presence::looksLikeToken(null), false);
check('SQL-shaped input is refused', Presence::looksLikeToken("' or 1=1 --"), false);

// Writing. A closed conversation or an ended session takes nothing more.
check('open conversation, live session: may write', Presence::canWrite(true, null), true);
check('closed conversation: may not write', Presence::canWrite(false, null), false);
check('visitor ended the session: may not write', Presence::canWrite(true, 1000), false);

// How often "still here" is written.
check('first poll records presence', Presence::shouldTouch(null, 1000, 30), true);
check('a poll 3 s later does not write', Presence::shouldTouch(1000, 1003, 30), false);
check('a poll 30 s later writes', Presence::shouldTouch(1000, 1030, 30), true);

// Here, left, ended.
$now = 10000;
check('polling normally: here', Presence::state($now - 20, null, null, $now, 120), Presence::HERE);
check('a reload sent a goodbye 5 s ago: still here', Presence::state($now - 20, $now - 5, null, $now, 120), Presence::HERE);
check('a goodbye nobody came back from: left', Presence::state($now - 200, $now - 130, null, $now, 120), Presence::LEFT);
check('no goodbye, but silent for longer than the limit: left', Presence::state($now - 121, null, null, $now, 120), Presence::LEFT);
// Chrome slows background tabs to about one timer a minute. A visitor who
// only switched tabs must not be reported gone.
check('background tab polling once a minute: here', Presence::state($now - 65, null, null, $now, 120), Presence::HERE);
check('ended beats everything', Presence::state($now, null, $now - 1, $now, 120), Presence::ENDED);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
