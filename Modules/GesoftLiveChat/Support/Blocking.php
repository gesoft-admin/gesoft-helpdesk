<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * The rules for blocking a visitor, with no framework in them.
 *
 * Modelled on Live Helper Chat's bans: an agent blocks an address, an email or
 * both, for a number of days or for good, and a blocked visitor cannot start a
 * chat. What is compared has to be normalised first, or `Ana@Example.com` walks
 * straight past a block on `ana@example.com`.
 */
final class Blocking
{
    const KIND_IP    = 'ip';
    const KIND_EMAIL = 'email';

    /** The durations an agent can choose, in days. Zero means permanently. */
    const DURATIONS = [1, 7, 30, 0];

    public static function normalizeEmail($email)
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * The canonical text of an address, so that the same IPv6 address written
     * two ways is one address.
     */
    public static function normalizeIp($ip)
    {
        $ip = trim((string) $ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        return inet_ntop(inet_pton($ip));
    }

    /**
     * When a block chosen now for `$days` runs out: a timestamp, null for
     * never, or false for a duration that is not on offer.
     */
    public static function expiresAt($days, $now)
    {
        $days = (int) $days;
        if (!in_array($days, self::DURATIONS, true)) {
            return false;
        }

        return $days === 0 ? null : $now + $days * 86400;
    }

    public static function isActive($expires_at, $now)
    {
        return $expires_at === null || $expires_at > $now;
    }
}
