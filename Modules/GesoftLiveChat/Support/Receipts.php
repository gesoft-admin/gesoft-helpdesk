<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * The rules of delivery receipts, as plain functions of plain numbers.
 *
 * A message is *sent* once the server has it, *delivered* once the other
 * side's screen has fetched it, and *seen* once it was on that screen while
 * the screen could be looked at. Each side keeps one pointer per state — the
 * newest message id it has reached — because a chat is a line: whatever is
 * seen up to message N is seen for everything before it.
 */
class Receipts
{
    const SENT = 'sent';
    const DELIVERED = 'delivered';
    const SEEN = 'seen';

    /**
     * Where a report moves a pointer to, or null when it moves nothing.
     *
     * Reports come from browsers, so anything that is not a positive whole
     * number is ignored, and a pointer never moves back: an old tab reporting
     * late cannot un-see a message.
     */
    public static function advance($current, $reported)
    {
        if (is_int($reported)) {
            $reported = (string) $reported;
        }
        if (!is_string($reported) || !ctype_digit($reported) || strlen($reported) > 10) {
            return null;
        }

        $reported = (int) $reported;

        return $reported > (int) $current ? $reported : null;
    }

    /** What a message with this id shows, given the other side's pointers. */
    public static function state($id, $delivered, $seen)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return self::SENT;
        }
        if ($id <= (int) $seen) {
            return self::SEEN;
        }
        // Seen implies delivered, even where the two were written apart.
        if ($id <= max((int) $delivered, (int) $seen)) {
            return self::DELIVERED;
        }

        return self::SENT;
    }

    /**
     * Of these message ids, the newest one seen, or 0. That message carries
     * the time; the ones before it only say that they were seen.
     */
    public static function newestSeen(array $ids, $seen)
    {
        $newest = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= (int) $seen && $id > $newest) {
                $newest = $id;
            }
        }

        return $newest;
    }
}
