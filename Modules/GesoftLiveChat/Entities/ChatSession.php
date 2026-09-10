<?php

namespace Modules\GesoftLiveChat\Entities;

use App\Conversation;
use App\Thread;
use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\Presence;

/**
 * One visitor tab's hold on one conversation.
 *
 * This replaces a token that addressed a *customer*. The customer was found by
 * email, so anybody who typed another person's address into the form was
 * handed a token for that person — and once their own chat closed, the token
 * led straight into the other person's open conversation, to read and to write.
 * Reproduced on the test instance on 2026-09-10.
 *
 * A session belongs to exactly one conversation. Nothing about the customer
 * leads to it, and only a hash of its token is stored.
 */
class ChatSession extends Model
{
    protected $table = 'gesoft_live_chat_sessions';

    protected $guarded = ['id'];

    protected $dates = ['last_seen_at', 'left_at', 'left_noted_at', 'ended_at'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * A new session for a conversation that was just opened.
     *
     * Returns `[$session, $token]`. The token is handed to the visitor once and
     * not kept.
     */
    public static function open(Conversation $conversation, $ip = null, $lang = null)
    {
        $token = Presence::newToken();

        $session = self::create([
            'conversation_id' => $conversation->id,
            'token_hash'      => Presence::hash($token),
            'last_seen_at'    => now(),
            // What an agent can block if they have to, the way Live Helper
            // Chat keeps the visitor's address on the chat.
            'ip'              => \Modules\GesoftLiveChat\Support\Blocking::normalizeIp($ip),
            'lang'            => $lang,
        ]);

        return [$session, $token];
    }

    /**
     * The language a conversation's visitor reads, for messages written to
     * them automatically: the newest session's, or the configured default.
     */
    public static function langFor($conversation)
    {
        $lang = $conversation
            ? self::where('conversation_id', $conversation->id)->orderBy('id', 'desc')->value('lang')
            : null;

        return Presence::lang($lang, (string) config('gesoftlivechat.visitor_lang', 'ro'));
    }

    public static function findByToken($token)
    {
        if (!Presence::looksLikeToken($token)) {
            return null;
        }

        return self::where('token_hash', Presence::hash($token))->first();
    }

    public function isConversationOpen()
    {
        $conversation = $this->conversation;

        return $conversation
            && $conversation->state == Conversation::STATE_PUBLISHED
            && in_array($conversation->status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING]);
    }

    public function canWrite()
    {
        return Presence::canWrite($this->isConversationOpen(), $this->ts($this->ended_at));
    }

    public function state($gone_after = null)
    {
        $gone_after = $gone_after === null ? (int) config('gesoftlivechat.gone_after') : (int) $gone_after;

        return Presence::state(
            $this->ts($this->last_seen_at),
            $this->ts($this->left_at),
            $this->ts($this->ended_at),
            time(),
            // Zero switches "left" off rather than making everybody gone.
            $gone_after > 0 ? $gone_after : PHP_INT_MAX
        );
    }

    /**
     * The visitor is here: a poll or a message arrived.
     *
     * Writes only when something changed or the last record is old enough to
     * matter. If their leaving had already been written into the conversation,
     * their return is written too, so the operator is not left reading a
     * "left" line about somebody who is typing.
     */
    public function seen()
    {
        $dirty = false;

        if (Presence::shouldTouch($this->ts($this->last_seen_at), time(), (int) config('gesoftlivechat.seen_every'))) {
            $this->last_seen_at = now();
            $dirty = true;
        }

        if ($this->left_at) {
            $this->left_at = null;
            $dirty = true;
        }

        if ($this->left_noted_at) {
            $this->left_noted_at = null;
            $dirty = true;
            $this->note(Presence::ACTION_RETURNED);
        }

        if ($dirty) {
            $this->save();
        }
    }

    /**
     * A line in the conversation, in the customer's name.
     *
     * A line item rather than a note or a message: it is history, not
     * something anybody said, and core does not count line items as replies —
     * so it never moves the idle clock or changes who spoke last.
     */
    public function note($action)
    {
        $conversation = $this->conversation;
        if (!$conversation) {
            return;
        }

        Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'user_id'                => $conversation->user_id,
            'action_type'            => $action,
            'source_via'             => Thread::PERSON_CUSTOMER,
            'source_type'            => Thread::SOURCE_TYPE_WEB,
            'customer_id'            => $conversation->customer_id,
            'created_by_customer_id' => $conversation->customer_id,
        ]);
    }

    private function ts($date)
    {
        return $date ? $date->getTimestamp() : null;
    }
}
