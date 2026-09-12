<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * What a customer is allowed to see of their own conversations, and what to
 * call it, with no framework in it.
 *
 * Two decisions live here and both are worth stating rather than scattering:
 * which threads a customer may be shown, and what FreeScout's internal
 * statuses are called when a customer reads them.
 */
final class History
{
    /**
     * Thread types a customer may be shown: what they wrote, and what an agent
     * wrote to them.
     *
     * Everything else is deliberately absent, and the absences are the point:
     *
     *   NOTE (3)      an agent wrote it believing the customer cannot read it.
     *                 Leaking one is worse than leaking a reply.
     *   LINEITEM (4)  the conversation's own history — assigned, status
     *                 changed, "the visitor left". Operator bookkeeping.
     *
     * `type` alone is not enough, which is why `STATE` below exists too: a
     * *draft* reply is `TYPE_MESSAGE` and is an agent's unsent words.
     */
    const TYPES = [1, 2];   // Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE

    /** The only state a customer may be shown: Thread::STATE_PUBLISHED. */
    const STATE = 2;

    /** Which of those were written by an agent, for unread counting. */
    const AGENT_TYPE = 2;   // Thread::TYPE_MESSAGE

    // FreeScout's main statuses.
    const ACTIVE  = 1;
    const PENDING = 2;
    const CLOSED  = 3;
    const SPAM    = 4;

    /**
     * The main status behind a status id.
     *
     * FreeScout allows custom statuses numbered above the standard ones, and
     * folds them onto a standard one by their first digit — 21 is a flavour of
     * 2. Reading `status` directly would therefore show a customer a state
     * nothing has a name for, so every reader here goes through this.
     */
    public static function mainStatus($status)
    {
        return (int) substr((string) (int) $status, 0, 1);
    }

    /**
     * Whether a conversation may appear in a customer's history at all.
     *
     * Spam never does: an agent marked it spam, and showing somebody their own
     * message filed as spam invites an argument the helpdesk cannot win.
     * Deleted never does either — `state` is the conversation's own, and a
     * deleted conversation is one an agent has taken out of the mailbox.
     */
    public static function isVisible($status, $state)
    {
        return (int) $state === 2                        // Conversation::STATE_PUBLISHED
            && self::mainStatus($status) !== self::SPAM;
    }

    /**
     * Whether a customer may still write into it.
     *
     * A closed conversation may be written into, and that is the whole point of
     * a history: the answer arrived, the agent closed it, and a week later
     * there is one more question about the same thing. Core reopens it —
     * "reply from customer makes conversation active", `Thread::createExtended`
     * — so this does not have to.
     *
     * Spam and deleted may not. Core's own mail path makes the same exception
     * for spam, and for the same reason: a reply must not lift a judgement an
     * agent made.
     */
    public static function canReply($status, $state)
    {
        return self::isVisible($status, $state);
    }

    /**
     * What to call a status to the person who raised it.
     *
     * The keys are what the panel translates. The mapping is not symmetric with
     * the operator's vocabulary and should not be:
     *
     *   active   we have it and it is ours to answer — the customer wrote last,
     *            or nobody has replied yet.
     *   pending  an agent has replied and the ball is in the customer's court.
     *            Core sets this on every agent reply (`Thread::createExtended`)
     *            and the sweep sets it on a chat that has gone quiet after one.
     *            "Pending" to an operator means "waiting on them"; said to the
     *            customer it has to mean the same thing from their side.
     *   closed   done, as far as the helpdesk is concerned. A reply reopens it.
     */
    public static function statusKey($status)
    {
        switch (self::mainStatus($status)) {
            case self::ACTIVE:  return 'active';
            case self::PENDING: return 'pending';
            case self::CLOSED:  return 'closed';
            case self::SPAM:    return 'spam';
        }

        return 'active';
    }

    /**
     * How many conversations and how many messages are unread, from rows of
     * `[conversation_id => unread count]`.
     *
     * Pure so that the arithmetic the badge shows is checked without a
     * database — it is the number a person looks at, and an off-by-one in it
     * is a support call.
     */
    public static function totals($per_conversation)
    {
        $conversations = 0;
        $messages = 0;

        foreach ((array) $per_conversation as $count) {
            $count = (int) $count;
            if ($count > 0) {
                $conversations++;
                $messages += $count;
            }
        }

        return ['conversations' => $conversations, 'messages' => $messages];
    }

    /**
     * How far a "seen" report may move a pointer: forward only, never past
     * what exists.
     *
     * Returns the new value, or null when nothing should move. A browser under-
     * reporting only leaves its own unread count too high, which is why this
     * needs no proof of anything — but it must never move backwards, or an
     * agent's answer would become unread again every time the panel reloaded.
     */
    public static function advance($current, $reported, $newest)
    {
        $current  = (int) $current;
        $reported = (int) $reported;
        $newest   = (int) $newest;

        if ($reported > $newest) {
            $reported = $newest;
        }

        return $reported > $current ? $reported : null;
    }
}
