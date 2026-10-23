<?php
/**
 * The signed assertion a connected application vouches for its user with,
 * checked without booting Laravel.
 *
 * Run it directly:  php Modules/GesoftLiveChat/Tests/assertion.php
 *
 * Spending an assertion's id needs the database and is checked end to end
 * (`Tests/e2e/connect-browser.mjs`); everything else that makes a token
 * acceptable is here.
 */

require __DIR__.'/../Support/Apps.php';
require __DIR__.'/../Support/Assertion.php';

use Modules\GesoftLiveChat\Support\Apps;
use Modules\GesoftLiveChat\Support\Assertion;

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok    %-66s %s\n", $label, json_encode($got)); }
    else { $fail++; printf("  FAIL  %-66s got: %s  wanted: %s\n", $label, json_encode($got), json_encode($want)); }
}

echo "GesoftLiveChat — signed assertions\n\n";

$b64 = function ($bytes) { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); };

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = $b64(sodium_crypto_sign_publickey($pair));
$stranger = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

$register = Apps::register([
    'gevcon-a1b2c3' => ['key' => $public, 'origin' => 'http://192.168.1.20', 'name' => 'GEVCON'],
    'legacy'        => ['token' => str_repeat('a', 64), 'origin' => 'https://legacy.example.ro'],
]);

$aud = 'https://helpdesk.example.ro';
$now = 1800000000;

function sign($claims, $key, $header = ['alg' => 'EdDSA', 'typ' => 'JWT']) {
    global $b64;
    $input = $b64(json_encode($header)).'.'.$b64(json_encode($claims));

    return $input.'.'.$b64(sodium_crypto_sign_detached($input, $key));
}

$good = [
    'iss' => 'gevcon-a1b2c3', 'sub' => '42', 'aud' => $aud,
    'iat' => $now, 'exp' => $now + 60, 'jti' => 'Zx81mQpA0c3vR7tY',
    'name' => 'Ana Pop', 'email' => 'ana@example.ro',
];
$with = function (array $change) use ($good) { return array_merge($good, $change); };
$error = function ($token) use ($register, $aud, $now) {
    $r = Assertion::verify($token, $register, $aud, $now);

    return $r['error'] ?? 'accepted';
};

// ---------------------------------------------------------------- accepted

$r = Assertion::verify(sign($good, $secret), $register, $aud, $now);
check('a token signed by the connected application is accepted', $r['claims']['sub'] ?? null, '42');
check('  naming the provider it was signed for', $r['claims']['iss'] ?? null, 'gevcon-a1b2c3');
check('  and that application', $r['app']['name'] ?? null, 'GEVCON');
check('  with the claims it carried', [$r['claims']['name'], $r['claims']['email']], ['Ana Pop', 'ana@example.ro']);
check('an audience written with a trailing slash still matches', $error(sign($with(['aud' => $aud.'/']), $secret)), 'accepted');
check('  and an audience among several', $error(sign($with(['aud' => ['https://other.example', $aud]]), $secret)), 'accepted');
check('a clock up to a minute fast is forgiven', $error(sign($with(['iat' => $now + 50, 'exp' => $now + 110]), $secret)), 'accepted');
check('  and one up to a minute slow', $error(sign($with(['iat' => $now - 100, 'exp' => $now - 30]), $secret)), 'accepted');

// ---------------------------------------------------------------- refused

check('signed with another key', $error(sign($good, $stranger)), 'signature');
check('a claim changed after signing', $error((function () use ($good, $secret, $b64) {
    list($h, , $s) = explode('.', sign($good, $secret));
    $good['sub'] = '43';

    return $h.'.'.$b64(json_encode($good)).'.'.$s;
})()), 'signature');
check('the signature cut short', $error(substr(sign($good, $secret), 0, -4)), 'signature');
check('alg "none"', $error(sign($good, $secret, ['alg' => 'none'])), 'algorithm');
check('alg HS256', $error(sign($good, $secret, ['alg' => 'HS256'])), 'algorithm');
check('an unknown provider', $error(sign($with(['iss' => 'nobody']), $secret)), 'issuer');
check('a provider connected with a secret, not a key', $error(sign($with(['iss' => 'legacy']), $secret)), 'issuer');
check('made out to another helpdesk', $error(sign($with(['aud' => 'https://evil.example']), $secret)), 'audience');
check('made out to nobody', $error(sign($with(['aud' => null]), $secret)), 'audience');
check('expired', $error(sign($with(['iat' => $now - 300, 'exp' => $now - 120]), $secret)), 'expired');
check('issued in the future', $error(sign($with(['iat' => $now + 120, 'exp' => $now + 180]), $secret)), 'times');
check('valid for longer than five minutes', $error(sign($with(['exp' => $now + 301]), $secret)), 'times');
check('times as strings', $error(sign($with(['iat' => (string) $now]), $secret)), 'times');
check('no jti', $error(sign($with(['jti' => null]), $secret)), 'jti');
check('a jti too short to be random', $error(sign($with(['jti' => 'abc']), $secret)), 'jti');
check('no subject', $error(sign($with(['sub' => '']), $secret)), 'subject');
check('a numeric subject', $error(sign($with(['sub' => 42]), $secret)), 'subject');
check('a subject with a control character', $error(sign($with(['sub' => "4\n2"]), $secret)), 'subject');
check('not a token at all', $error('hello'), 'malformed');
check('padding where base64url has none', $error(sign($good, $secret).'='), 'malformed');
check('too long', $error(str_repeat('a', 5000).'.b.c'), 'malformed');
check('not a string', $error(['a', 'b']), 'malformed');

// ---------------------------------------------------------------- keys

check('a key is 32 bytes', Assertion::publicKey($public) !== null, true);
check('  not 31', Assertion::publicKey($b64(str_repeat('x', 31))), null);
check('a fingerprint is short and stable', Assertion::fingerprint($public), Assertion::fingerprint(base64_encode(sodium_crypto_sign_publickey($pair))));
check('  and differs between keys', Assertion::fingerprint($public) !== Assertion::fingerprint($b64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))), true);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail ? 1 : 0);
