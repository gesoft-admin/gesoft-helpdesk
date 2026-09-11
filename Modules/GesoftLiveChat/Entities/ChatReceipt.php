<?php

namespace Modules\GesoftLiveChat\Entities;

use App\Thread;
use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\Receipts;

/**
 * How far each side of one chat has received and seen the other's messages.
 * The columns are described in the migration that creates the table.
 *
 * Every change is a conditional UPDATE that only moves a pointer forward, so
 * two agents on one chat, or a bubble racing an agent, cannot move it back.
 * A report is also clamped to messages that exist and were written by the
 * other side: a browser can say it saw message N, and what moves is the
 * pointer to the newest such message at or before N.
 */
class ChatReceipt extends Model
{
    protected $table = 'gesoft_live_chat_receipts';

    protected $primaryKey = 'conversation_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $dates = ['agent_seen_at', 'visitor_seen_at'];

    /** Per request, for the conversation page, which asks once per message. */
    private static $remembered = [];

    public static function enabled()
    {
        return (bool) config('gesoftlivechat.receipts');
    }

    /** The row for a conversation, or an unsaved one with every pointer at 0. */
    public static function of($conversation_id)
    {
        $receipt = self::find($conversation_id);
        if ($receipt) {
            return $receipt;
        }

        $receipt = new self();
        $receipt->conversation_id = (int) $conversation_id;
        foreach (['agent_delivered_id', 'agent_seen_id', 'visitor_delivered_id', 'visitor_seen_id'] as $pointer) {
            $receipt->$pointer = 0;
        }

        return $receipt;
    }

    public static function remembered($conversation_id)
    {
        if (!array_key_exists($conversation_id, self::$remembered)) {
            self::$remembered[$conversation_id] = self::of($conversation_id);
        }

        return self::$remembered[$conversation_id];
    }

    // ------------------------------------------------------ the visitor's side

    /** The bubble fetched the agents' messages up to this id. */
    public function visitorReceived($thread_id)
    {
        return $this->move('visitor_delivered_id', $thread_id);
    }

    /** The bubble had the agents' messages up to this id on screen. */
    public function visitorSaw($reported)
    {
        $id = $this->clamp('visitor_seen_id', $reported, Thread::TYPE_MESSAGE);
        if (!$id) {
            return false;
        }

        $this->move('visitor_delivered_id', $id);

        return $this->move('visitor_seen_id', $id, 'visitor_seen_at');
    }

    // ------------------------------------------------------- the agents' side

    /** An agent's FreeScout fetched the visitor's messages up to this id. */
    public function agentsReceived($thread_id)
    {
        return $this->move('agent_delivered_id', $thread_id);
    }

    /** An agent had the visitor's messages up to this id on screen. */
    public function agentSaw($reported)
    {
        $id = $this->clamp('agent_seen_id', $reported, Thread::TYPE_CUSTOMER);
        if (!$id) {
            return false;
        }

        $this->move('agent_delivered_id', $id);

        return $this->move('agent_seen_id', $id, 'agent_seen_at');
    }

    /**
     * Every chat an agent's chat list shows has reached that agent: the newest
     * visitor message of each, in two reads and a write only where something
     * moved.
     */
    public static function agentsReceivedAll($conversation_ids)
    {
        $ids = collect($conversation_ids)->map(function ($id) { return (int) $id; })->filter()->values();
        if (!$ids->count()) {
            return;
        }

        $latest = Thread::whereIn('conversation_id', $ids)
            ->where('type', Thread::TYPE_CUSTOMER)
            ->where('state', Thread::STATE_PUBLISHED)
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, max(id) as latest')
            ->pluck('latest', 'conversation_id');

        $current = self::whereIn('conversation_id', $ids)->pluck('agent_delivered_id', 'conversation_id');

        foreach ($latest as $conversation_id => $thread_id) {
            if ((int) $thread_id > (int) ($current[$conversation_id] ?? 0)) {
                self::of($conversation_id)->agentsReceived($thread_id);
            }
        }
    }

    // -------------------------------------------------------------- internals

    /**
     * The newest message of `$type`, published, at or before what the browser
     * reported — if that is further than the pointer already is.
     */
    private function clamp($pointer, $reported, $type)
    {
        $reported = Receipts::advance($this->$pointer, $reported);
        if ($reported === null) {
            return 0;
        }

        return (int) Thread::where('conversation_id', $this->conversation_id)
            ->where('type', $type)
            ->where('state', Thread::STATE_PUBLISHED)
            ->where('id', '<=', $reported)
            ->max('id');
    }

    private function move($pointer, $thread_id, $at = null)
    {
        $thread_id = Receipts::advance($this->$pointer, (int) $thread_id);
        if ($thread_id === null) {
            return false;
        }

        if (!$this->exists) {
            try {
                self::insert(['conversation_id' => $this->conversation_id]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Somebody else made the row first. The update below still
                // applies, and only if it moves the pointer forward.
            }
            $this->exists = true;
        }

        $values = [$pointer => $thread_id];
        if ($at) {
            $values[$at] = now();
        }

        $moved = self::where('conversation_id', $this->conversation_id)
            ->where($pointer, '<', $thread_id)
            ->update($values);

        if ($moved) {
            foreach ($values as $column => $value) {
                $this->setAttribute($column, $value);
            }
        }

        return (bool) $moved;
    }
}
