<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * A connected application saying "this person is signed in to me", in a form
 * that needs no call from that application's server to this helpdesk.
 *
 * The application signs a short JSON Web Token with its own Ed25519 key. Its
 * page hands the token to the embedded chat, the chat hands it here, and the
 * signature is checked against the public key the application was connected
 * with. The private key never leaves the application and the helpdesk holds
 * nothing that could sign in its name, so a copy of this helpdesk's database
 * cannot be used to impersonate anybody.
 *
 * It is what makes an application on a private network usable: its server may
 * have no route to the internet at all, and only the browser of the person in
 * front of it has to reach both.
 *
 *   header   {"alg":"EdDSA","typ":"JWT"}
 *   payload  {"iss": provider, "sub": that application's id for the person,
 *             "aud": this helpdesk's address, "iat", "exp", "jti",
 *             "name"?, "email"?, "route"?}
 *
 * Standard JWT on purpose: every platform an application might be written on
 * has a library that signs one, so the definition an application implements
 * is "a JWT, EdDSA, these claims" and not a format of ours.
 *
 * A token passes through a browser, so it is made to be worth very little
 * there: it is for one helpdesk (`aud`), lives at most `MAX_LIFETIME` seconds,
 * and its `jti` is spent the first time it is used — keeping the spent ones
 * is the caller's job, since it needs a database.
 *
 * No framework in here, so `Tests/assertion.php` can check every rule alone.
 */
final class Assertion
{
    /** Longest a token may be valid for, `exp - iat`, in seconds. */
    const MAX_LIFETIME = 300;

    /** How far the two clocks may disagree, in seconds. */
    const SKEW = 60;

    /** Longest token looked at at all. Claims are short; a long token is not one. */
    const MAX_LENGTH = 4096;

    /**
     * The claims of a token that checks out, or a short reason it does not.
     *
     * Returns `['claims' => [...], 'app' => [...]]` or `['error' => code]`.
     * The codes are for logs and tests; the caller answers every one of them
     * the same way, so a client learns only "no".
     */
    public static function verify($token, $register, $audience, $now)
    {
        if (!is_string($token) || strlen($token) > self::MAX_LENGTH
            || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m)) {
            return ['error' => 'malformed'];
        }

        $header = json_decode((string) self::decode($m[1]), true);
        $claims = json_decode((string) self::decode($m[2]), true);
        $signature = self::decode($m[3]);

        if (!is_array($header) || !is_array($claims) || $signature === null) {
            return ['error' => 'malformed'];
        }

        // One algorithm, named exactly. "none", HS256 with the public key as
        // the secret and every other classic JWT confusion end here.
        if (($header['alg'] ?? null) !== 'EdDSA') {
            return ['error' => 'algorithm'];
        }

        $app = Apps::find($register, $claims['iss'] ?? null);
        $key = $app ? self::publicKey($app['key'] ?? '') : null;

        if ($key === null) {
            return ['error' => 'issuer'];
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $m[1].'.'.$m[2], $key)) {
            return ['error' => 'signature'];
        }

        // Signed by that application; now whether it is for us, and for now.
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array(self::audience($audience), array_map([self::class, 'audience'], $audiences), true)) {
            return ['error' => 'audience'];
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) {
            return ['error' => 'times'];
        }
        if ($exp <= $now - self::SKEW) {
            return ['error' => 'expired'];
        }
        if ($iat > $now + self::SKEW || $exp <= $iat || $exp - $iat > self::MAX_LIFETIME) {
            return ['error' => 'times'];
        }

        if (!is_string($claims['jti'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $claims['jti'])) {
            return ['error' => 'jti'];
        }

        if (Apps::externalId($claims['sub'] ?? null) === null || !is_string($claims['sub'])) {
            return ['error' => 'subject'];
        }

        $claims['iss'] = Apps::provider($claims['iss']);

        return ['claims' => $claims, 'app' => $app];
    }

    /**
     * A public key as raw bytes, from its base64 or base64url text, or null.
     * Exactly 32 bytes: anything else is not an Ed25519 public key.
     */
    public static function publicKey($text)
    {
        $text = trim((string) $text);
        if ($text === '' || strlen($text) > 64) {
            return null;
        }

        $raw = self::decode(rtrim(strtr($text, '+/', '-_'), '='));

        return $raw !== null && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    /** A public key written the one way it is stored: base64url, no padding. */
    public static function normalizeKey($text)
    {
        $raw = self::publicKey($text);

        return $raw === null ? null : self::encode($raw);
    }

    /** A short, readable fingerprint of a key, for an administrator to compare. */
    public static function fingerprint($text)
    {
        $raw = self::publicKey($text);
        if ($raw === null) {
            return '';
        }

        return implode(':', str_split(substr(hash('sha256', $raw), 0, 16), 4));
    }

    public static function encode($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** base64url without padding, strictly; null for anything else. */
    public static function decode($text)
    {
        if (!is_string($text) || !preg_match('/^[A-Za-z0-9_-]*$/', $text) || strlen($text) % 4 === 1) {
            return null;
        }

        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }

    /** An address compared the way it was meant: no trailing slash, any case of scheme and host. */
    public static function audience($url)
    {
        if (!is_string($url)) {
            return null;
        }

        $url = rtrim(trim($url), '/');

        return preg_replace_callback('~^([A-Za-z]+://[^/]+)~', function ($m) {
            return strtolower($m[1]);
        }, $url);
    }
}
