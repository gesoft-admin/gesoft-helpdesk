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

    protected $dates = ['last_seen_at'];

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
}
