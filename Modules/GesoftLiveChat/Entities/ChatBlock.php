<?php

namespace Modules\GesoftLiveChat\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\GesoftLiveChat\Support\Blocking;

/**
 * An address or an email that may not start a chat.
 *
 * Live Helper Chat's bans, reduced to the two kinds that mean something for a
 * support desk: the visitor's IP address and the email they gave. An agent
 * places one from the conversation, for a number of days or for good; an
 * administrator lifts it from Manage → Blocked chat visitors.
 */
class ChatBlock extends Model
{
    protected $table = 'gesoft_live_chat_blocks';

    protected $guarded = ['id'];

    protected $dates = ['expires_at'];

    public function creator()
    {
        return $this->belongsTo(\App\User::class, 'created_by_user_id');
    }

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Whether a visitor from this address, or giving this email, is blocked.
     */
    public static function applies($ip, $email)
    {
        $ip = Blocking::normalizeIp($ip);
        $email = Blocking::normalizeEmail($email);

        if (!$ip && !$email) {
            return false;
        }

        return self::active()->where(function ($query) use ($ip, $email) {
            if ($ip) {
                $query->orWhere(function ($query) use ($ip) {
                    $query->where('kind', Blocking::KIND_IP)->where('value', $ip);
                });
            }
            if ($email) {
                $query->orWhere(function ($query) use ($email) {
                    $query->where('kind', Blocking::KIND_EMAIL)->where('value', $email);
                });
            }
        })->exists();
    }
}
