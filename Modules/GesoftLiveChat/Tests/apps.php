<?php
/**
 * The register of applications allowed to embed the chat, checked without
 * booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/apps.php
 *
 * These are the rules that decide who may frame the chat page and who may say
 * "this person is signed in to me". Everything else in the feature rests on
 * them, so they are checked here, one at a time, with no database and no
 * framework to go wrong instead.
 */

require __DIR__.'/../Support/Apps.php';

use Modules\GesoftLiveChat\Support\Apps;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-66s %s\n", $label, json_encode($got)); }
    else { $fail++; printf("  FAIL  %-66s got: %s  wanted: %s\n", $label, json_encode($got), json_encode($want)); }
}

echo "GesoftLiveChat — the application register\n\n";

$secret = str_repeat('a', 64);
$other  = str_repeat('b', 64);

// ------------------------------------------------------------ what registers

$full = Apps::register(['myapp' => ['token' => $secret, 'origin' => 'https://myapp.example.com', 'name' => 'My Application']]);
check('a complete application registers', array_keys($full), ['myapp']);
check('  with its origin as a browser writes one', $full['myapp']['origin'], 'https://myapp.example.com');
check('  and its own name', $full['myapp']['name'], 'My Application');

check('a trailing slash is not part of an origin',
    Apps::register(['a' => ['token' => $secret, 'origin' => 'https://a.example.ro/']])['a']['origin'], 'https://a.example.ro');
check('nor is a path',
    array_keys(Apps::register(['a' => ['token' => $secret, 'origin' => 'https://a.example.ro/backend']])), []);
check('a port is',
    Apps::register(['a' => ['token' => $secret, 'origin' => 'https://a.example.ro:8443']])['a']['origin'], 'https://a.example.ro:8443');
check('the name falls back to the provider', Apps::register(['a' => ['token' => $secret, 'origin' => 'https://a.example.ro']])['a']['name'], 'a');

// Half a configuration is not half a permission.
check('no token, not registered', array_keys(Apps::register(['a' => ['origin' => 'https://a.example.ro']])), []);
check('a short token, not registered', array_keys(Apps::register(['a' => ['token' => 'short', 'origin' => 'https://a.example.ro']])), []);
check('no origin, not registered', array_keys(Apps::register(['a' => ['token' => $secret]])), []);
check('nothing at all, nothing registered', Apps::register(null), []);

// Plain HTTP is how the test environment is reached and is never how a real
// application is, so it is allowed for a private address and nowhere else.
check('http on a public name is refused',
    array_keys(Apps::register(['a' => ['token' => $secret, 'origin' => 'http://a.example.ro']])), []);
check('http on a private address is allowed',
    Apps::register(['a' => ['token' => $secret, 'origin' => 'http://192.168.40.12']])['a']['origin'], 'http://192.168.40.12');
check('  and on localhost',
    Apps::register(['a' => ['token' => $secret, 'origin' => 'http://localhost:8080']])['a']['origin'], 'http://localhost:8080');

// A provider name is a database key and part of a cache key.
check('a provider name is narrow', Apps::provider('MYAPP'), 'myapp');
check('  and refuses anything else', Apps::provider('gev con/../x'), null);
check('  and refuses being empty', Apps::provider(''), null);

// ----------------------------------------------------------- who proves what

check('the right secret authenticates', Apps::authenticate($full, 'myapp', $secret)['name'], 'My Application');
check('a wrong secret does not', Apps::authenticate($full, 'myapp', $other), null);
check('an empty secret does not', Apps::authenticate($full, 'myapp', ''), null);
check('nor does the right secret under the wrong name', Apps::authenticate($full, 'other', $secret), null);
check('nor anything at all against an empty register', Apps::authenticate([], 'myapp', $secret), null);

// --------------------------------------------------------------- who may frame

check('frame-ancestors names the registered application', Apps::frameAncestors($full), 'https://myapp.example.com');
check('  and nobody at all when there are none', Apps::frameAncestors([]), "'none'");
check('  each origin once, however many applications share it',
    Apps::frameAncestors(Apps::register([
        'a' => ['token' => $secret, 'origin' => 'https://one.example.ro'],
        'b' => ['token' => $other, 'origin' => 'https://one.example.ro'],
    ])), 'https://one.example.ro');

check('an origin that is registered may ask for the page',
    Apps::allowedOrigin($full, 'https://myapp.example.com'), 'https://myapp.example.com');
check('  written with a trailing slash too', Apps::allowedOrigin($full, 'https://myapp.example.com/'), 'https://myapp.example.com');
check('one that is not may not', Apps::allowedOrigin($full, 'https://evil.example'), null);
check('  and neither may a lookalike', Apps::allowedOrigin($full, 'https://myapp.example.com.evil.example'), null);
check('  nor the same name over plain http', Apps::allowedOrigin($full, 'http://myapp.example.com'), null);
check('  nor nothing', Apps::allowedOrigin($full, ''), null);

// ------------------------------------------------------------- external ids

check('an application id is kept as it is', Apps::externalId(' 12345 '), '12345');
check('  including one that is not a number', Apps::externalId('a8f3-2c'), 'a8f3-2c');
check('an empty one is refused', Apps::externalId(''), null);
check('a control character is refused', Apps::externalId("12\n34"), null);
check('  and so is one too long for the column', Apps::externalId(str_repeat('x', 192)), null);
check('one exactly as long as the column is kept', Apps::externalId(str_repeat('x', 191)), str_repeat('x', 191));

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail ? 1 : 0);
