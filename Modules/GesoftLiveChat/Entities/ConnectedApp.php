<?php

namespace Modules\GesoftLiveChat\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\Apps;
use Modules\GesoftLiveChat\Support\Assertion;

/**
 * An application an administrator connected from inside the application
 * itself: a name, the origin the browser reported, and a public key.
 *
 * `register()` is the one place the full register is put together — what
 * `.env` configures and what was connected here — and every caller that asks
 * "is this application registered" asks it, so a revoked application is
 * refused everywhere at once: its embedded page, its assertions, and every
 * permission already handed to its users' browsers (`AppSession::findByToken`).
 */
class ConnectedApp extends Model
{
    protected $table = 'gesoft_live_chat_connected_apps';

    protected $guarded = ['id'];

    protected $dates = ['last_used_at', 'revoked_at'];

    public function connector()
    {
        return $this->belongsTo(\App\User::class, 'connected_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public function fingerprint()
    {
        return Assertion::fingerprint($this->public_key);
    }

    /**
     * Every usable application. `.env` first; a connected application with the
     * same provider adds its key to that entry — which is how an application
     * configured by hand moves to a key without its people losing their
     * conversations — but never replaces the origin `.env` gave it.
     */
    public static function register()
    {
        $raw = (array) config('gesoftlivechat.apps');

        try {
            $connected = self::active()->get();
        } catch (\Throwable $e) {
            // Before the migration has run: what `.env` says still works.
            $connected = [];
        }

        foreach ($connected as $app) {
            if (isset($raw[$app->provider]) && is_array($raw[$app->provider])) {
                $raw[$app->provider]['key'] = $app->public_key;
                continue;
            }

            $raw[$app->provider] = [
                'key'    => $app->public_key,
                'origin' => $app->origin,
                'name'   => $app->name,
            ];
        }

        return Apps::register($raw);
    }

    /**
     * A provider name for a newly connected application: its name, as far as
     * it is letters and digits, and enough randomness that two installations
     * of the same application never collide.
     */
    public static function newProvider($name)
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', \Illuminate\Support\Str::ascii((string) $name)));
        $slug = trim(substr(trim($slug, '-'), 0, 24), '-') ?: 'app';

        do {
            $provider = $slug.'-'.bin2hex(random_bytes(3));
        } while (self::where('provider', $provider)->exists());

        return $provider;
    }

    /** Recorded at most once a minute: it is for the administrator's list, not an audit trail. */
    public static function touchUsed($provider)
    {
        self::where('provider', $provider)
            ->where(function ($q) {
                $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute());
            })
            ->update(['last_used_at' => now()]);
    }
}
