<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * The rules of a visitor's chat session, with no framework in them.
 *
 * Kept pure on purpose. These are the decisions that make a public endpoint
 * safe — what counts as a credential, when a visitor may still write, when
 * they count as gone — and each one is tested without booting Laravel.
 */
final class Presence
{
    const HERE  = 'here';
    const LEFT  = 'left';
    const ENDED = 'ended';

    // Line items this module writes into a chat. FreeScout keeps no registry of
    // action types, so these sit well clear of core's (1–11), for the same
    // reason the channel code does. The column is a tinyint.
    const ACTION_ENDED    = 100;
    const ACTION_LEFT     = 101;
    const ACTION_RETURNED = 102;
    const ACTION_BLOCKED  = 103;

    /** Languages the bubble and its automatic messages are written in. */
    const LANGS = ['ro', 'en'];

    /**
     * The visitor's language from what the bubble asked for, or the default.
     *
     * Only the two the bubble has words for; anything else would produce
     * automatic messages in a language nobody wrote.
     */
    public static function lang($requested, $default)
    {
        $lang = strtolower(substr(trim((string) $requested), 0, 2));

        return in_array($lang, self::LANGS, true) ? $lang : $default;
    }

    /**
     * Whether the bubble should tell a waiting visitor that somebody will be
     * with them shortly.
     *
     * Live Helper Chat's auto-responder does the same after a configurable
     * wait. Here it is computed on every poll rather than written into the
     * conversation: a line the agent did not write has no business in the
     * transcript, and nothing has to be undone once the agent answers.
     */
    public static function shouldTellToWait($first_customer_at, $agent_replied, $now, $wait_after)
    {
        return $wait_after > 0
            && !$agent_replied
            && $first_customer_at !== null
            && ($now - $first_customer_at) >= $wait_after;
    }

    /** 256 bits, hex. The raw value only ever exists in the visitor's tab. */
    public static function newToken()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * What the database keeps instead of the token.
     *
     * A copy of the table — a backup, a support dump — then hands nobody a way
     * into a conversation. Same reason passwords are not stored.
     */
    public static function hash($token)
    {
        return hash('sha256', (string) $token);
    }

    /**
     * Shape check before anything touches the database.
     *
     * The 32-character tokens of the earlier design fail it, which is intended:
     * they addressed a customer rather than a conversation and must not resolve
     * to anything now.
     */
    public static function looksLikeToken($token)
    {
        return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    /**
     * Whether a poll should record that the visitor is still here.
     *
     * Not on every poll: one poll every three seconds would be one write every
     * three seconds per open chat, to learn something thirty-second resolution
     * answers just as well. Live Helper Chat settled on the same number.
     */
    public static function shouldTouch($last_seen, $now, $every)
    {
        return $last_seen === null || ($now - $last_seen) >= $every;
    }

    /**
     * Here, left or ended, from timestamps.
     *
     * A closing tab sends a goodbye, but so does a reload, a moment before the
     * same visitor polls again. So a goodbye alone does not make somebody gone:
     * only a goodbye nobody came back from within `$gone_after`, or silence for
     * that long.
     */
    public static function state($last_seen, $left_at, $ended_at, $now, $gone_after)
    {
        if ($ended_at !== null) {
            return self::ENDED;
        }
        if ($left_at !== null && ($now - $left_at) >= $gone_after) {
            return self::LEFT;
        }
        if ($last_seen !== null && ($now - $last_seen) >= $gone_after) {
            return self::LEFT;
        }

        return self::HERE;
    }

    /**
     * Whether the visitor may still add to the conversation.
     *
     * A closed conversation stays closed for the token that was in it. The
     * visitor starts a new one and says who they are again; an old token never
     * reopens anything. That is the rule the product owner set, and it is also
     * what stops an abandoned tab on a shared computer from carrying on
     * somebody else's conversation.
     */
    public static function canWrite($conversation_open, $ended_at)
    {
        return (bool) $conversation_open && $ended_at === null;
    }
}
