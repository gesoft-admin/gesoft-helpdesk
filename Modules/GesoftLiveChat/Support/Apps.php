<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * The register of applications allowed to open chats for people they have
 * already signed in, with no framework in it.
 *
 * An application here is three things and all three are required: a `provider`
 * name that identities are filed under, a way to prove it is that application,
 * and the `origin` its pages are served from. Anything missing one of them is
 * not registered at all — a half-configured application must fail closed
 * rather than become an open door with a name.
 *
 * The proof is a shared `token` its server presents (configured in `.env`), an
 * Ed25519 public `key` it signs assertions with (connected from the
 * application, see `Support/Assertion.php`), or both while an application moves
 * from the first to the second.
 *
 * The origin does two separate jobs, and it is the same value on purpose: it is
 * what the embedded chat page will allow itself to be framed by, and it is what
 * the browser is told to send messages to. One setting, so an operator cannot
 * authorise an application in one direction and not the other.
 *
 * Nothing here trusts anything a browser said. The token arrives server to
 * server; an assertion may come through a browser, but it is signed by the
 * application and checked against its key. These rules only ever *narrow*
 * what the configuration already granted.
 */
final class Apps
{
    /** Shortest shared secret accepted, in characters. */
    const MIN_TOKEN = 32;

    /**
     * The applications that are actually usable, from whatever the settings
     * hold.
     *
     * Entries are dropped rather than repaired: a token that is too short, an
     * origin with a path on it or a provider name with room for surprises in it
     * are all configuration mistakes, and the safe reading of a mistake here is
     * "this application is not registered".
     */
    public static function register($raw)
    {
        $apps = [];

        foreach (is_array($raw) ? $raw : [] as $provider => $app) {
            $provider = self::provider($provider);
            $token    = is_array($app) ? (string) ($app['token'] ?? '') : '';
            $origin   = self::origin(is_array($app) ? ($app['origin'] ?? '') : '');
            $key      = Assertion::normalizeKey(is_array($app) ? ($app['key'] ?? '') : '');

            // A token too short to be a secret is no token, rather than a weak one.
            if (strlen($token) < self::MIN_TOKEN) {
                $token = '';
            }

            if ($provider === null || $origin === null || ($token === '' && $key === null)) {
                continue;
            }

            $apps[$provider] = [
                'token'  => $token,
                'key'    => $key,
                'origin' => $origin,
                'name'   => self::name(is_array($app) ? ($app['name'] ?? '') : '', $provider),
            ];
        }

        return $apps;
    }

    /**
     * A provider name, or null.
     *
     * Deliberately narrow: it is a database key, part of a cache key and is
     * echoed back to the caller, so it holds letters, digits, dash and
     * underscore and nothing else.
     */
    public static function provider($provider)
    {
        $provider = strtolower(trim((string) $provider));

        return preg_match('/^[a-z0-9_-]{1,40}$/', $provider) ? $provider : null;
    }

    /**
     * An origin, or null: scheme and host, optionally a port, never a path.
     *
     * `https://app.example.com/` and `https://app.example.com/backend` both
     * become `https://app.example.com`, because that is what a browser compares
     * against and what `frame-ancestors` understands. `http` is allowed only
     * for a host on a private network — see `isPrivateHost()` — which is how
     * an application installed on a customer's own network is often reached,
     * and never how a public one is.
     */
    public static function origin($origin)
    {
        $origin = rtrim(trim((string) $origin), '/');

        if (!preg_match('~^(https?)://([A-Za-z0-9.-]{1,253})(:\d{1,5})?$~', $origin, $m)) {
            return null;
        }

        if (strtolower($m[1]) === 'http' && !self::isPrivateHost($m[2])) {
            return null;
        }

        return strtolower($m[1]).'://'.strtolower($m[2]).($m[3] ?? '');
    }

    /**
     * Whether plain HTTP is excusable for this host: a machine on a private
     * network, or this one. Everything else has to be HTTPS, because the
     * page served from it is handed a credential.
     *
     * A private network is a private address, or a name that only resolves
     * inside one: a single label (`erp`, `server01`) or one of the suffixes
     * reserved or customary for internal names. An application installed at a
     * customer's office is as often reached as `http://erp.local` as by its
     * address, and a public name never ends in these.
     */
    public static function isPrivateHost($host)
    {
        $host = strtolower($host);

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) {
            return (bool) preg_match('/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
        }

        if (strpos($host, '.') === false) {
            return true;
        }

        return (bool) preg_match('/\.(local|lan|internal|intranet|corp|home\.arpa|localdomain)$/', $host);
    }

    public static function name($name, $provider)
    {
        $name = trim(strip_tags((string) $name));

        return $name !== '' ? mb_substr($name, 0, 60) : $provider;
    }

    /** The registered application, or null. */
    public static function find($register, $provider)
    {
        $provider = self::provider($provider);

        return $provider !== null && isset($register[$provider]) ? $register[$provider] : null;
    }

    /**
     * Whether a caller claiming to be `$provider` proved it.
     *
     * Compared in constant time, and the unregistered case is compared too —
     * against a decoy of the same length — so that a wrong provider name and a
     * wrong token cost the same and neither can be told from the other by how
     * long the answer took.
     */
    public static function authenticate($register, $provider, $presented)
    {
        $app = self::find($register, $provider);
        // An application connected with a key alone has no secret to match,
        // and is compared against the decoy like an unregistered one.
        $expected = $app && $app['token'] !== '' ? $app['token'] : str_repeat("\0", self::MIN_TOKEN);
        $presented = (string) $presented;

        $ok = hash_equals(hash('sha256', $expected), hash('sha256', $presented));

        return $app && $app['token'] !== '' && $ok ? $app : null;
    }

    /**
     * The `frame-ancestors` list for the embedded chat page: every registered
     * application, and `'none'` when there are none.
     *
     * Never a wildcard and never `'self'` — this helpdesk framing its own chat
     * page is not something anything needs, and leaving it out keeps the list
     * exactly the set of applications an operator named.
     */
    public static function frameAncestors($register)
    {
        $origins = [];
        foreach ($register as $app) {
            $origins[$app['origin']] = true;
        }

        return $origins ? implode(' ', array_keys($origins)) : "'none'";
    }

    /**
     * The origin the embedded page should talk to, from what the page was
     * asked to talk to: it must be one a registered application is served
     * from, or the page talks to nobody.
     */
    public static function allowedOrigin($register, $asked)
    {
        $asked = self::origin($asked);
        if ($asked === null) {
            return null;
        }

        foreach ($register as $app) {
            if ($app['origin'] === $asked) {
                return $asked;
            }
        }

        return null;
    }

    /**
     * An application's own id for a person, or null.
     *
     * Stored and compared, never parsed. Control characters are refused
     * because this ends up in logs, and the length is the column's.
     */
    public static function externalId($id)
    {
        $id = trim((string) $id);

        if ($id === '' || mb_strlen($id) > 191 || preg_match('/[\x00-\x1f\x7f]/', $id)) {
            return null;
        }

        return $id;
    }
}
