<?php

namespace Modules\GesoftLiveChat\Entities;

use App\Conversation;
use App\Thread;
use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\History;

/**
 * One conversation belonging to one application identity.
 *
 * This is the authorisation boundary for everything in the customer's history,
 * and it is a link this integration wrote rather than a resemblance it noticed.
 * Nothing here ever finds a conversation by customer or by email — see the
 * migration for why that distinction is not academic.
 */
class AppConversation extends Model
{
    protected $table = 'gesoft_live_chat_app_conversations';

    protected $guarded = ['id'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Record that this identity owns this conversation. Idempotent: the unique
     * index is the guard, and a second call is a no-op rather than an error.
     */
    public static function link(AppIdentity $identity, $conversation_id)
    {
        $existing = self::where('identity_id', $identity->id)
            ->where('conversation_id', $conversation_id)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return self::create([
                'identity_id'         => $identity->id,
                'conversation_id'     => (int) $conversation_id,
                'last_seen_thread_id' => 0,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Two page loads raced. The row the winner wrote is the answer.
            return self::where('identity_id', $identity->id)
                ->where('conversation_id', $conversation_id)
                ->first();
        }
    }

    /**
     * The conversation this identity means by `$conversation_id`, or null.
     *
     * Two things have to be true and neither is optional. There is a row
     * saying this identity owns it — that is the boundary — **and** the
     * conversation still belongs to the customer the identity is mapped to.
     * The second is not redundant: an agent can move a conversation to another
     * customer, and when they do it stops being this person's to read, whatever
     * a mapping written last month says.
     *
     * Null for "not yours" and for "does not exist" alike. The caller answers
     * both the same way, so that asking cannot be used to find out which ids
     * are real.
     */
    public static function owned(AppIdentity $identity, $conversation_id)
    {
        $conversation_id = (int) $conversation_id;
        if ($conversation_id <= 0) {
            return null;
        }

        $link = self::where('identity_id', $identity->id)
            ->where('conversation_id', $conversation_id)
            ->first();

        if (!$link) {
            return null;
        }

        $conversation = $link->conversation;

        if (!$conversation
            || $conversation->customer_id != $identity->customer_id
            || !History::isVisible($conversation->status, $conversation->state)
        ) {
            return null;
        }

        return $link;
    }

    /**
     * The threads a customer may be shown, oldest first.
     *
     * The same rule as the chat's own poll, in one place so the two cannot
     * drift apart: published messages from the customer and from agents, and
     * nothing else. A note, a draft reply and a line item are all excluded, and
     * `Support/History.php` says why each of them is.
     */
    public static function visibleThreads($conversation_id)
    {
        return Thread::where('conversation_id', $conversation_id)
            ->whereIn('type', History::TYPES)
            ->where('state', History::STATE)
            ->orderBy('id');
    }

    /**
     * How many agent messages this identity has not read, per conversation.
     *
     * One query for the whole list. Each conversation has a pointer of its own,
     * so the threshold differs per row and cannot be a single `WHERE id > ?`;
     * it becomes one `(conversation = ? AND id > ?)` per conversation instead,
     * which is a compact clause for the page of conversations the panel shows
     * and still one round trip. A query per row is how a history list becomes
     * slow the month somebody has fifty of them.
     */
    public static function unreadCounts($links)
    {
        $links = collect($links);
        if ($links->isEmpty()) {
            return [];
        }

        $counts = [];
        foreach ($links as $link) {
            $counts[(int) $link->conversation_id] = 0;
        }

        $rows = Thread::where('type', History::AGENT_TYPE)
            ->where('state', History::STATE)
            ->where(function ($query) use ($links) {
                foreach ($links as $link) {
                    $query->orWhere(function ($clause) use ($link) {
                        $clause->where('conversation_id', $link->conversation_id)
                            ->where('id', '>', (int) $link->last_seen_thread_id);
                    });
                }
            })
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, count(*) as total')
            ->pluck('total', 'conversation_id');

        foreach ($rows as $conversation_id => $total) {
            $counts[(int) $conversation_id] = (int) $total;
        }

        return $counts;
    }

    /**
     * This person has read up to `$reported`.
     *
     * Forward only, and never past a message that exists — `History::advance`
     * has the rule. A conditional update rather than a read and a write, so two
     * tabs reporting at once cannot put the pointer back.
     */
    public function sawUpTo($reported)
    {
        $newest = (int) Thread::where('conversation_id', $this->conversation_id)
            ->where('type', History::AGENT_TYPE)
            ->where('state', History::STATE)
            ->max('id');

        $moved = History::advance($this->last_seen_thread_id, $reported, $newest);

        if ($moved === null) {
            return false;
        }

        $applied = self::where('id', $this->id)
            ->where('last_seen_thread_id', '<', $moved)
            ->update(['last_seen_thread_id' => $moved, 'updated_at' => now()]);

        if ($applied) {
            $this->last_seen_thread_id = $moved;
        }

        return (bool) $applied;
    }

    /**
     * The same from the chat's own poll, which has a conversation id and runs
     * every second and a half.
     *
     * One conditional write and no reads: it matches nothing for an ordinary
     * visitor's conversation and nothing again once the pointer is already
     * there, which is almost every poll. The version above reads first because
     * it clamps; this one does not, and does not need to. The number comes from
     * messages the bubble has actually drawn, and a browser that over-reports
     * only leaves its *own* unread count too low. It can learn nothing by it,
     * and the pointer still cannot move backwards.
     */
    public static function sawUpToByConversation($conversation_id, $reported)
    {
        $reported = (int) $reported;

        if ($reported <= 0) {
            return;
        }

        self::where('conversation_id', (int) $conversation_id)
            ->where('last_seen_thread_id', '<', $reported)
            ->update(['last_seen_thread_id' => $reported, 'updated_at' => now()]);
    }
}
