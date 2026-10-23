<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GesoftLiveChat\Entities\ConnectedApp;
use Modules\GesoftLiveChat\Support\Apps;
use Modules\GesoftLiveChat\Support\Assertion;

/**
 * Connecting an application from inside the application, and the list of the
 * ones that are.
 *
 * The flow, all of it in the administrator's browser:
 *
 *  1. the application's settings page generates a key pair on its server and
 *     opens `/gesoft-live-chat/apps/connect` in a popup;
 *  2. the popup — this helpdesk, signed in as an administrator — says it is
 *     ready, and the application posts it its name and public key;
 *  3. the popup shows the origin **the browser** says the message came from,
 *     which the application cannot choose, and asks the administrator;
 *  4. on approval the key is stored and the popup posts back the provider
 *     name and the address assertions must be made out to — to that origin
 *     and no other — and closes.
 *
 * Nothing secret crosses: the private key stays on the application's server,
 * and what comes back is a name. Nothing is copied by hand, and no server of
 * the application ever has to reach this one.
 *
 * Every action here is an administrator's, on the `web` group's session and
 * CSRF token, and the page refuses to be framed like every other operator page.
 */
class ConnectController extends Controller
{
    const LOG_PREFIX = 'GesoftLiveChat';

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $this->admin();

        $connected = ConnectedApp::with('connector')->orderBy('revoked_at')->orderBy('id', 'desc')->get();
        $configured = Apps::register(config('gesoftlivechat.apps'));

        return view('gesoftlivechat::apps', [
            'connected'  => $connected,
            'configured' => $configured,
        ]);
    }

    /** The popup. Everything it shows arrives later, by message, from its opener. */
    public function connect(Request $request)
    {
        $this->admin();

        return view('gesoftlivechat::connect', [
            'audience' => AppController::audience(),
        ]);
    }

    /**
     * Approve a connection.
     *
     * `origin` is what the popup read from the message event, not anything the
     * application said about itself; it still goes through the same rules as
     * `.env` (no path, `http` only on a private network). `provider` is there
     * when the application is reconnecting: the key is replaced, the name kept,
     * and its people keep their conversations — but only from the origin it
     * was connected from, or anybody could take an application over by naming it.
     */
    public function approve(Request $request)
    {
        $user = $this->admin();

        $origin = Apps::origin($request->input('origin'));
        $key = Assertion::normalizeKey($request->input('public_key'));
        $name = Apps::name($request->input('name'), '');
        $asked = $request->input('provider');

        if ($origin === null) {
            return $this->fail('origin');
        }
        if ($key === null) {
            return $this->fail('key');
        }
        if ($name === '') {
            return $this->fail('name');
        }

        if ($asked !== null && $asked !== '') {
            $provider = Apps::provider($asked);
            $existing = $provider !== null ? ConnectedApp::where('provider', $provider)->first() : null;
            $configured = $provider !== null ? Apps::find(Apps::register(config('gesoftlivechat.apps')), $provider) : null;

            if ($existing) {
                if ($existing->origin !== $origin) {
                    return $this->fail('origin_mismatch');
                }
                $existing->update([
                    'name'         => $name,
                    'public_key'   => $key,
                    'connected_by' => $user->id,
                    'revoked_at'   => null,
                ]);
                $app = $existing;
            } elseif ($configured) {
                if ($configured['origin'] !== $origin) {
                    return $this->fail('origin_mismatch');
                }
                $app = ConnectedApp::create([
                    'provider'     => $provider,
                    'name'         => $name,
                    'origin'       => $origin,
                    'public_key'   => $key,
                    'connected_by' => $user->id,
                ]);
            } else {
                return $this->fail('unknown_provider');
            }
        } else {
            // The same key from the same place is the same application asking
            // twice — a double click, a popup reopened — not a second one.
            $app = ConnectedApp::active()->where('origin', $origin)->where('public_key', $key)->first()
                ?: ConnectedApp::create([
                    'provider'     => ConnectedApp::newProvider($name),
                    'name'         => $name,
                    'origin'       => $origin,
                    'public_key'   => $key,
                    'connected_by' => $user->id,
                ]);
        }

        \Log::info(self::LOG_PREFIX.': application connected', [
            'provider' => $app->provider,
            'origin'   => $app->origin,
            'user_id'  => $user->id,
        ]);

        return response()->json([
            'status'      => 'success',
            'provider'    => $app->provider,
            'audience'    => AppController::audience(),
            'fingerprint' => $app->fingerprint(),
        ]);
    }

    /**
     * Revoke an application. Immediately: its embedded page is refused, its
     * assertions fail, and the permissions its users' browsers already hold
     * stop working on their next request. The row stays, so connecting it
     * again under the same provider gives its people back their history.
     */
    public function revoke(Request $request, $id)
    {
        $user = $this->admin();

        $app = ConnectedApp::findOrFail($id);
        $app->update(['revoked_at' => now()]);

        \Log::info(self::LOG_PREFIX.': application revoked', [
            'provider' => $app->provider,
            'user_id'  => $user->id,
        ]);

        return redirect()->route('gesoftlivechat.apps');
    }

    protected function admin()
    {
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        return $user;
    }

    protected function fail($code)
    {
        return response()->json(['status' => 'error', 'code' => $code], 422);
    }
}
