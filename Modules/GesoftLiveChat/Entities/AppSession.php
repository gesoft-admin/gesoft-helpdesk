<?php

namespace Modules\GesoftLiveChat\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\Presence;

/**
 * Permission for one browser to act as one application user, for a while.
 *
 * The application's server asks for this over an authenticated, server-to-
 * server call and hands the token to the page it is already serving that
 * person. It is therefore a bearer credential in a browser, and is treated as
 * one: only its hash is stored, it expires, and the two things it can do are
 * the two the chat needs.
 *
 *   resume  give me the session token for the chat this identity is already in
 *   start   open a chat as this identity
 *
 * It cannot read a conversation, cannot name one, and cannot be used to ask
 * anything about the customer. Once a chat is open it stops being the
 * credential at all: the per-conversation session token from `ChatSession` is,
 * exactly as it is for a visitor off the street.
 */
class AppSession extends Model
{
    protected $table = 'gesoft_live_chat_app_sessions';

    protected $guarded = ['id'];

    protected $dates = ['expires_at'];

    public function identity()
    {
        return AppIdentity::where('provider', $this->provider)
            ->where('external_id', $this->external_id)
            ->first();
    }

    /**
     * A new permission for an identity. Returns `[$session, $token]`; the token
     * is handed over once and not kept.
     */
    public static function open(AppIdentity $identity, $ttl, $ip = null, $route = null)
    {
        $token = Presence::newToken();

        $session = self::create([
            'token_hash'  => Presence::hash($token),
            'provider'    => $identity->provider,
            'external_id' => $identity->external_id,
            'customer_id' => $identity->customer_id,
            'ip'          => \Modules\GesoftLiveChat\Support\Blocking::normalizeIp($ip),
            'route'       => $route,
            'expires_at'  => now()->addSeconds(max(60, (int) $ttl)),
        ]);

        return [$session, $token];
    }

    /**
     * The permission a token names, or null — the same answer for a token that
     * was never minted, one that expired and one that belongs to an
     * application somebody has since unregistered.
     */
    public static function findByToken($token, $register)
    {
        if (!Presence::looksLikeToken($token)) {
            return null;
        }

        $session = self::where('token_hash', Presence::hash($token))->first();

        if (!$session || !$session->expires_at || $session->expires_at->isPast()) {
            return null;
        }

        return isset($register[$session->provider]) ? $session : null;
    }

    /**
     * Permissions that have run out, cleared on the way past.
     *
     * Housekeeping rather than security -- an expired row is already refused —
     * so it is done opportunistically, in small batches, wherever one is being
     * written anyway.
     */
    public static function sweepExpired($limit = 200)
    {
        $ids = self::where('expires_at', '<', now())->limit($limit)->pluck('id');

        return $ids->isEmpty() ? 0 : self::whereIn('id', $ids)->delete();
    }
}
