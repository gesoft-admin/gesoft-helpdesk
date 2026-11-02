<?php

namespace Modules\GesoftLiveChat\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\Presence;

/**
 * When each agent last had the interface open.
 *
 * FreeScout keeps no such thing. The operator script already asks the server
 * for the chat count every ten seconds from every page an agent has open, so
 * that request is the heartbeat, written at most every thirty seconds. Live
 * Helper Chat decides "an operator is online" the same way, from recent
 * activity.
 */
class AgentPresence extends Model
{
    protected $table = 'gesoft_live_chat_agents';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $dates = ['last_seen_at', 'site_bubble_until'];

    public static function seen($user_id)
    {
        $row = self::find($user_id);

        if ($row && $row->last_seen_at
            && !Presence::shouldTouch($row->last_seen_at->getTimestamp(), time(), 30)
        ) {
            return;
        }

        self::updateOrCreate(['user_id' => $user_id], ['last_seen_at' => now()]);
    }

    /** Whether this agent has had FreeScout open within `operator_timeout`. */
    public static function isPresent($user_id)
    {
        return self::where('user_id', $user_id)
            ->where('last_seen_at', '>=', now()->subSeconds(max(30, (int) config('gesoftlivechat.operator_timeout'))))
            ->exists();
    }

    /**
     * Whether anybody who can answer chats in this mailbox is around.
     *
     * `availability` in the configuration can pin the answer: `online` for a
     * site where chats are always taken, `offline` to show only the message
     * form. The default, `auto`, asks the agents' heartbeat.
     */
    public static function anyoneAvailable($mailbox)
    {
        $mode = (string) config('gesoftlivechat.availability');

        if ($mode === 'online') {
            return true;
        }
        if ($mode === 'offline' || !$mailbox) {
            return false;
        }

        $user_ids = $mailbox->userIdsHavingAccess();
        if (!$user_ids) {
            return false;
        }

        return self::whereIn('user_id', $user_ids)
            ->where('last_seen_at', '>=', now()->subSeconds(max(30, (int) config('gesoftlivechat.operator_timeout'))))
            ->exists();
    }

    /**
     * Whether the public site should show the bubble: an agent who can answer
     * in this mailbox has switched it on, the switch has not run out, and that
     * same agent is still here. Another agent being online does not keep it
     * open for somebody who has gone home.
     *
     * Ignores `availability` on purpose: that setting pins what an open bubble
     * offers, and this decides whether the site shows one at all.
     */
    public static function siteBubbleOpen($mailbox)
    {
        return (bool) self::siteBubbleHolder($mailbox);
    }

    /** The agent keeping the site's bubble open, if there is one. */
    public static function siteBubbleHolder($mailbox)
    {
        if (!$mailbox) {
            return null;
        }

        return self::whereIn('user_id', $mailbox->userIdsHavingAccess())
            ->where('site_bubble_until', '>', now())
            ->where('last_seen_at', '>=', now()->subSeconds(max(30, (int) config('gesoftlivechat.operator_timeout'))))
            ->first();
    }

    /** Offer the bubble on the site, for `site_bubble_hours` at the most. */
    public static function openSiteBubble($user_id)
    {
        $hours = max(1, (int) config('gesoftlivechat.site_bubble_hours'));

        self::updateOrCreate(['user_id' => $user_id], [
            'last_seen_at'      => now(),
            'site_bubble_until' => now()->addHours($hours),
        ]);
    }

    /**
     * Stop offering it, whoever switched it on: the button says the bubble is
     * on the site, and pressing it has to take it off the site.
     */
    public static function closeSiteBubble()
    {
        self::whereNotNull('site_bubble_until')->update(['site_bubble_until' => null]);
    }

    /** Signing out withdraws only this agent's own offer. */
    public static function withdrawSiteBubble($user_id)
    {
        self::where('user_id', $user_id)->update(['site_bubble_until' => null]);
    }
}
