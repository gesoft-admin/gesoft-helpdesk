<?php

namespace Modules\GesoftLiveChat\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * An assertion id that has been used. See `Support/Assertion.php`.
 */
class AssertionId extends Model
{
    protected $table = 'gesoft_live_chat_assertion_ids';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $dates = ['expires_at'];

    /**
     * Spend an id: true the first time, false every time after. The unique key
     * decides, not a read before the write, so two requests racing with the
     * same assertion cannot both win.
     */
    public static function spend($provider, $jti, $exp)
    {
        self::where('expires_at', '<', now())->limit(200)->delete();

        try {
            self::create([
                'provider'   => $provider,
                'jti'        => $jti,
                // Kept past `exp` by the allowed clock skew, so an assertion is
                // never both still acceptable and already forgotten.
                'expires_at' => date('Y-m-d H:i:s', $exp + \Modules\GesoftLiveChat\Support\Assertion::SKEW + 60),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return false;
        }

        return true;
    }
}
