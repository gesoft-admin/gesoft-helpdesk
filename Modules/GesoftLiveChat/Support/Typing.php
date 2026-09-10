<?php

namespace Modules\GesoftLiveChat\Support;

use App\Conversation;
use App\Thread;

/**
 * "Somebody is typing", in both directions.
 *
 * Only that somebody is, never what. Live Helper Chat shows the agent the
 * visitor's unsent text as it is typed, and has it on by default; that is
 * deliberately not copied. A visitor who types something and deletes it has
 * not said it.
 *
 * Kept in the cache rather than the database: a sign that is stale in six
 * seconds is not worth a row, and a lost cache loses nothing but dots. Unlike
 * `Presence`, this touches the framework; the decision itself is
 * `Presence::showTyping()`, which is tested on its own.
 */
final class Typing
{
    public static function enabled()
    {
        return (bool) config('gesoftlivechat.typing');
    }

    public static function visitorTyping(Conversation $conversation)
    {
        \Cache::put(self::key('visitor', $conversation->id), time(), self::expires());
    }

    public static function visitorStopped($conversation_id)
    {
        \Cache::forget(self::key('visitor', $conversation_id));
    }

    public static function isVisitorTyping(Conversation $conversation)
    {
        $at = \Cache::get(self::key('visitor', $conversation->id));
        if (!is_int($at)) {
            return false;
        }

        return Presence::showTyping($at, self::lastMessageAt($conversation, Thread::TYPE_CUSTOMER), time());
    }

    public static function agentTyping(Conversation $conversation, $user)
    {
        \Cache::put(self::key('agent', $conversation->id), [
            'at'      => time(),
            'user_id' => (int) $user->id,
            'name'    => (string) $user->first_name,
        ], self::expires());
    }

    /**
     * Only this agent's own sign. Two agents can have one chat open, and the
     * one who is not typing must not wipe out the one who is.
     */
    public static function agentStopped(Conversation $conversation, $user)
    {
        $key = self::key('agent', $conversation->id);
        $sign = \Cache::get($key);

        if (is_array($sign) && (int) ($sign['user_id'] ?? 0) === (int) $user->id) {
            \Cache::forget($key);
        }
    }

    /**
     * The first name of the agent typing in this conversation — an empty
     * string if they have none — or null if nobody is.
     */
    public static function agentTypingName(Conversation $conversation)
    {
        $sign = \Cache::get(self::key('agent', $conversation->id));
        if (!is_array($sign) || !is_int($sign['at'] ?? null)) {
            return null;
        }

        if (!Presence::showTyping($sign['at'], self::lastMessageAt($conversation, Thread::TYPE_MESSAGE), time())) {
            return null;
        }

        return (string) ($sign['name'] ?? '');
    }

    /** When that side last published a message, or null. Drafts do not count. */
    private static function lastMessageAt(Conversation $conversation, $type)
    {
        $thread = $conversation->threads()
            ->where('type', $type)
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->first();

        return $thread && $thread->created_at ? $thread->created_at->getTimestamp() : null;
    }

    /**
     * A little longer than the sign lasts; `showTyping()` decides, the cache
     * only has to keep it that long. A date rather than minutes, which
     * Laravel 5.5 and later versions read the same way.
     */
    private static function expires()
    {
        return now()->addSeconds(Presence::TYPING_FOR + 2);
    }

    private static function key($who, $conversation_id)
    {
        return 'gesoftlivechat:typing:'.$who.':'.(int) $conversation_id;
    }
}
