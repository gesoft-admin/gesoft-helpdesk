<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * Whether a conversation came in through the bubble: a chat, or the message
 * form left while nobody was available.
 *
 * It matters for what the helpdesk sends out. The email address on either was
 * typed by whoever filled in the form, so nothing may be emailed to it
 * automatically.
 *
 * A chat is recognised by its channel. A message form conversation is an
 * ordinary email conversation, so it is marked in its meta as it is created —
 * inside core's own thread creation, before the event that decides on an
 * auto-reply — while `$offlineForm` is set for the length of that call.
 */
final class Origin
{
    const META_KEY = 'gesoftlivechat';
    const OFFLINE_FORM = 'offline_form';

    /** True only while the message form's conversation is being created. */
    public static $offlineForm = false;

    public static function isFromWidget($conversation, $chat_channel)
    {
        if (!$conversation) {
            return false;
        }

        if ((int) ($conversation->channel ?? 0) === (int) $chat_channel) {
            return true;
        }

        return method_exists($conversation, 'getMeta')
            && $conversation->getMeta(self::META_KEY) === self::OFFLINE_FORM;
    }
}
