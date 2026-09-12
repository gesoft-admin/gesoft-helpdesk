<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GesoftLiveChat\Entities\AppIdentity;
use Modules\GesoftLiveChat\Entities\AppSession;
use Modules\GesoftLiveChat\Entities\ChatSession;
use Modules\GesoftLiveChat\Support\Apps;

/**
 * Chat for somebody an application has already signed in.
 *
 * Two callers, and they are not the same kind of caller at all:
 *
 * `session` is the application's **server**, proving itself with a shared
 * secret over a call a browser never makes. It is the only place identity is
 * ever established. Everything it is told about who the person is arrives on
 * that authenticated call and nowhere else — which is the whole point of the
 * design, because the alternative is a browser saying who it is, and a browser
 * saying who it is is a browser saying who it would like to be.
 *
 * `resume` is that person's **browser**, inside the embedded chat page,
 * holding the short-lived token its own server was given. It answers one
 * question: the chat you are already in, if you are in one. It cannot name a
 * conversation, cannot ask about a customer, and cannot reach anything an
 * ordinary visitor's token cannot.
 *
 * No branch here looks anybody up by an email address. See `AppIdentity`.
 */
class AppController extends Controller
{
    const LOG_PREFIX = 'GesoftLiveChat';

    /**
     * Mint a browser's permission to chat as an application user.
     *
     * Server to server: `Authorization: Bearer <the application's token>`, and
     * a JSON body naming the provider and that application's own id for the
     * person. Nothing in the answer describes the customer — the caller
     * already knows who they asked about, and the browser downstream is not
     * entitled to a FreeScout id.
     */
    public function session(Request $request)
    {
        $register = $this->register();
        $provider = (string) $request->input('provider', '');

        $app = Apps::authenticate($register, $provider, $this->bearer($request));

        if (!$app) {
            // One answer for an unregistered application and for a wrong
            // secret. Which of the two it was is not the caller's business,
            // and saying would let a caller enumerate the register.
            return $this->fail('unauthorized', 401);
        }

        $provider = Apps::provider($provider);
        $external_id = Apps::externalId($request->input('external_user_id'));

        if ($external_id === null) {
            return $this->fail('invalid_identity', 422);
        }

        list($customer, $identity, $created) = AppIdentity::resolve(
            $provider,
            $external_id,
            $this->name($request),
            $this->email($request)
        );

        $customer->addChannel($this->channel(), 'customer-'.$customer->id);

        $ttl = (int) config('gesoftlivechat.app_session_ttl');
        list(, $token) = AppSession::open($identity, $ttl, $this->clientIp($request), $this->route($request));

        AppSession::sweepExpired();

        if ($created) {
            \Log::info(self::LOG_PREFIX.': application identity registered', [
                'provider'    => $provider,
                'customer_id' => $customer->id,
            ]);
        }

        return response()->json([
            'status'     => 'success',
            'token'      => $token,
            'expires_in' => max(60, $ttl),
        ]);
    }

    /**
     * The chat this identity is already in, for the embedded page.
     *
     * Answers with a per-conversation session token when there is an open
     * chat, and with nothing when there is not — in which case the first
     * message the person writes opens one, through `start`.
     *
     * A fresh session token each time rather than the old one: only a hash of
     * a token is stored, so the old one cannot be given back. Presence is
     * judged per conversation rather than per session precisely so that the
     * tabs this leaves behind cannot make a conversation look abandoned while
     * somebody is sitting in front of it (`Console/SweepChats.php`).
     */
    public function resume(Request $request)
    {
        $session = $this->appSession($request);

        if (!$session) {
            return $this->fail('unauthorized', 401);
        }

        $identity = $session->identity();
        $customer = $identity ? $identity->customer : null;

        if (!$customer) {
            return $this->fail('unauthorized', 401);
        }

        // Whether a message left outside our hours could be answered at all.
        // The panel asks because it has no form to collect an address in: the
        // application is the one that knows, and if it has never told us one
        // then "leave a message" would be an offer we cannot keep.
        $answerable = (bool) $customer->getMainEmail();

        $conversation = $identity->openConversation();

        if (!$conversation) {
            return response()->json(['status' => 'success', 'chat' => null, 'offline' => $answerable]);
        }

        list(, $token) = ChatSession::reopen($conversation, $session->ip, null);

        return response()->json([
            'status'  => 'success',
            'offline' => $answerable,
            'chat'    => [
                'token' => $token,
                // From the beginning: the panel is being opened on a page that
                // has just loaded and has nothing on it, so what the person
                // needs is the conversation, not the part of it that happened
                // since a pointer the browser no longer has.
                'since' => 0,
            ],
        ]);
    }

    // ------------------------------------------------------------- internals

    /** The registered applications, as `Support/Apps.php` will have them. */
    protected function register()
    {
        return Apps::register(config('gesoftlivechat.apps'));
    }

    /**
     * The application session a token names, or null. Shared with
     * `ChatController::start`, which accepts the same credential.
     */
    public function appSession(Request $request)
    {
        $token = $request->input('app_token', $request->input('token'));

        if ($token === null) {
            $raw = json_decode((string) $request->getContent(), true);
            $token = is_array($raw) ? ($raw['app_token'] ?? ($raw['token'] ?? null)) : null;
        }

        return AppSession::findByToken($token, $this->register());
    }

    /**
     * The shared secret, from the one header it may arrive in.
     *
     * Not a query parameter and not a form field: those end up in access logs
     * and in browser history, and this secret belongs to a server.
     */
    protected function bearer(Request $request)
    {
        $header = (string) $request->headers->get('Authorization');

        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : '';
    }

    protected function name(Request $request)
    {
        $name = trim(strip_tags((string) $request->input('name', '')));

        return mb_substr($name, 0, 40);
    }

    protected function email(Request $request)
    {
        $email = trim((string) $request->input('email', ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 191) : null;
    }

    /**
     * The address of the person at the screen, as their own application
     * reported it — not the address this request came from, which is the
     * application's server.
     *
     * Recorded for the same reason a visitor's is: it is what an agent can
     * block. It is as trustworthy as the application that sent it, which is
     * the application whose users these are.
     */
    protected function clientIp(Request $request)
    {
        return filter_var((string) $request->input('client_ip', ''), FILTER_VALIDATE_IP) ?: null;
    }

    /**
     * Which screen of the application the person was on, or null.
     *
     * The only context this phase carries, and it is checked rather than
     * stored as it arrives: it goes into the database and into a log line, so
     * it holds what a route is made of and nothing that would end a quoted
     * string or a line.
     */
    protected function route(Request $request)
    {
        $route = trim((string) $request->input('route', ''));

        return $route !== '' && preg_match('~^[A-Za-z0-9/_.-]{1,191}$~', $route) ? $route : null;
    }

    protected function channel()
    {
        return (int) config('gesoftlivechat.channel');
    }

    protected function fail($code, $status)
    {
        return response()->json(['status' => 'error', 'code' => $code], $status);
    }
}
