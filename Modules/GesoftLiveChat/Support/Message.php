<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * The two conversions every message goes through, in one place.
 *
 * They were methods on `ChatController` while the chat was the only thing that
 * read or wrote a message. The history reads the same messages and writes into
 * the same conversations, and two copies of these rules would be two chances
 * for "what the customer sees" to mean something different depending on which
 * screen they are looking at.
 *
 * Unlike its neighbours in this directory, `text()` calls a core helper. It is
 * still here rather than in a controller because the pairing is the point: what
 * goes in is escaped, what comes out is flattened, and neither half is safe to
 * change without the other in view.
 */
final class Message
{
    /** Longest single message accepted, in characters. */
    const MAX = 4000;

    /**
     * What the customer typed, ready to store.
     *
     * Escaped before it is stored, not on the way out, because an agent's
     * browser renders thread bodies as HTML and so does every export, digest
     * and notification core sends. Returns null for nothing worth storing.
     */
    public static function store($raw, $max = self::MAX)
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $raw = mb_substr($raw, 0, $max);

        return nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /**
     * An agent's reply, flattened to text.
     *
     * The agent writes in a rich editor and the result is HTML. Neither the
     * chat nor the history has any business rendering that — it would be
     * handing a customer's browser markup written by somebody else — so it
     * leaves as text and is drawn with `textContent`.
     */
    public static function text($html)
    {
        $text = \Helper::htmlToText((string) $html);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
