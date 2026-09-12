<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Conversation;
use App\Thread;
use Modules\GesoftLiveChat\Entities\AppConversation;
use Modules\GesoftLiveChat\Entities\AppIdentity;
use Modules\GesoftLiveChat\Entities\AppSession;
use Modules\GesoftLiveChat\Entities\ChatSession;
use Modules\GesoftLiveChat\Support\Apps;
use Modules\GesoftLiveChat\Support\History;
use Modules\GesoftLiveChat\Support\Message;
use Modules\GesoftLiveChat\Support\Presence;

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
        $unread = $this->unread($identity);

        if (!$conversation) {
            return response()->json([
                'status'  => 'success',
                'chat'    => null,
                'offline' => $answerable,
            ] + $unread);
        }

        list(, $token) = ChatSession::reopen($conversation, $session->ip, null);

        return response()->json([
            'status'  => 'success',
            'offline' => $answerable,
        ] + $unread + [
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

    /**
     * How much this person has not read — and nothing else.
     *
     * Server to server, like `session`, and for a reason worth stating: the
     * application's own button has to be able to carry a badge on a page where
     * the panel was never opened, and minting a permission for that would be
     * handing out a credential to draw a number. This mints nothing, writes
     * nothing and returns two integers.
     *
     * An identity nothing has ever heard of is not an error either: it is a
     * person who has never written to us, and the honest answer is zero.
     */
    public function unreadCount(Request $request)
    {
        $register = $this->register();
        $provider = (string) $request->input('provider', '');

        $app = Apps::authenticate($register, $provider, $this->bearer($request));

        if (!$app) {
            return $this->fail('unauthorized', 401);
        }

        $external_id = Apps::externalId($request->input('external_user_id'));

        if ($external_id === null) {
            return $this->fail('invalid_identity', 422);
        }

        $identity = AppIdentity::where('provider', Apps::provider($provider))
            ->where('external_id', $external_id)
            ->first();

        if (!$identity || !$identity->customer) {
            return response()->json([
                'status'  => 'success',
                'unread'  => ['conversations' => 0, 'messages' => 0],
                'history' => false,
            ]);
        }

        return response()->json(['status' => 'success'] + $this->unread($identity));
    }

    /**
     * This identity's conversations, newest activity first.
     *
     * Only what a customer may be told: a subject, when it last moved, what to
     * call its state, and how many answers they have not read. Never the
     * assignee, never a note, never a custom field, never another customer's
     * address — none of which is in the answer at all rather than being
     * filtered out of it downstream.
     *
     * Reading this list marks nothing read. Somebody glancing at a row has not
     * read the answer inside it, and the badge would be lying by the time they
     * looked away.
     */
    public function history(Request $request)
    {
        if (!config('gesoftlivechat.history')) {
            abort(404);
        }

        $session = $this->appSession($request);
        $identity = $session ? $session->identity() : null;

        if (!$identity || !$identity->customer) {
            return $this->fail('unauthorized', 401);
        }

        $limit = (int) config('gesoftlivechat.history_limit') ?: 20;

        // Read once, use twice. The page shows the newest few; the badge counts
        // all of them, and asking the database a second time for rows already
        // in hand is how a list that is fast with three conversations stops
        // being fast with sixty.
        list($links, $conversations) = $this->owned($identity);
        $unread = AppConversation::unreadCounts($links);

        $page = $conversations->sortByDesc(function ($conversation) {
            return $conversation->last_reply_at ? $conversation->last_reply_at->getTimestamp() : 0;
        })->take($limit)->values();

        $current = (int) $identity->conversation_id;

        $items = [];
        foreach ($page as $conversation) {
            $items[] = [
                'id'      => $conversation->id,
                'subject' => mb_substr((string) $conversation->getSubject(), 0, 160),
                'status'  => History::statusKey($conversation->status),
                'at'      => $conversation->last_reply_at ? $conversation->last_reply_at->toIso8601String() : null,
                'unread'  => (int) ($unread[$conversation->id] ?? 0),
                // Whether this is the chat they are in now, so the panel can
                // say so rather than making them work it out from the date.
                'current' => $conversation->id === $current,
                // Whether writing into it is still possible. Deliberately not
                // called "open": a *closed* conversation can be replied to, and
                // that is the whole point of a history.
                'can_reply' => History::canReply($conversation->status, $conversation->state),
            ];
        }

        return response()->json([
            'status'        => 'success',
            'conversations' => $items,
            // True when there is more than the page shows, so the panel can say
            // so rather than quietly pretending this is everything.
            'more'          => $conversations->count() > $page->count(),
            'unread'        => History::totals($unread),
            'history'       => $links->isNotEmpty(),
        ]);
    }

    /**
     * One conversation of this identity's, with the messages a customer may
     * read.
     *
     * The same answer for a conversation that is somebody else's and for one
     * that does not exist. Telling the two apart would turn this into a way of
     * finding out which ids are real, and the panel has no use for the
     * difference.
     */
    public function conversation(Request $request)
    {
        if (!config('gesoftlivechat.history')) {
            abort(404);
        }

        $session = $this->appSession($request);
        $identity = $session ? $session->identity() : null;

        if (!$identity || !$identity->customer) {
            return $this->fail('unauthorized', 401);
        }

        $link = AppConversation::owned($identity, $request->input('conversation_id'));

        if (!$link) {
            return $this->fail('not_found', 404);
        }

        $conversation = $link->conversation;
        $threads = AppConversation::visibleThreads($conversation->id)->get();

        $messages = [];
        foreach ($threads as $thread) {
            $agent = (int) $thread->type === History::AGENT_TYPE;
            $messages[] = [
                'id'     => $thread->id,
                'from'   => $agent ? 'agent' : 'visitor',
                // First name only, exactly as the live chat does: enough to
                // know who is talking, nothing an agent would rather keep.
                'author' => $agent && $thread->created_by_user_cached ? $thread->created_by_user_cached->first_name : null,
                'body'   => Message::text($thread->body),
                'at'     => $thread->created_at ? $thread->created_at->toIso8601String() : null,
            ];
        }

        return response()->json([
            'status'  => 'success',
            'id'      => $conversation->id,
            'subject' => mb_substr((string) $conversation->getSubject(), 0, 160),
            'status_key' => History::statusKey($conversation->status),
            'can_reply' => History::canReply($conversation->status, $conversation->state),
            'current' => (int) $identity->conversation_id === (int) $conversation->id,
            'messages' => $messages,
        ]);
    }

    /**
     * Write into a conversation from the history, reopening it if it was
     * closed.
     *
     * The reopening is core's, not ours. `Thread::createExtended` has read
     * "reply from customer makes conversation active" since long before this
     * module existed: it moves the status, moves the conversation out of the
     * Closed folder, updates the counters and tells every listener. Doing any
     * of that here as well would be a second opinion about a rule that already
     * has one.
     *
     * What is ours is the refusal. Core's mail path will not let a reply
     * revive a conversation an agent marked spam, and neither will this; a
     * deleted one is the same. `History::canReply` is that rule.
     *
     * On the way out the conversation becomes this person's current chat and
     * gets a session token, so the panel carries straight on in live chat
     * rather than making them find it again.
     */
    public function reply(Request $request)
    {
        if (!config('gesoftlivechat.history')) {
            abort(404);
        }

        $lang = Presence::lang($request->input('lang'), (string) config('gesoftlivechat.visitor_lang', 'ro'));

        $session = $this->appSession($request);
        $identity = $session ? $session->identity() : null;

        if (!$identity || !$identity->customer) {
            return $this->fail('unauthorized', 401);
        }

        $link = AppConversation::owned($identity, $request->input('conversation_id'));

        if (!$link) {
            return $this->fail('not_found', 404);
        }

        $conversation = $link->conversation;

        if (!History::canReply($conversation->status, $conversation->state)) {
            return $this->fail('closed', 409);
        }

        $body = Message::store($request->input('message'));

        if ($body === null) {
            return $this->fail('empty', 400);
        }

        $thread = Thread::createExtended(
            [
                'type'  => Thread::TYPE_CUSTOMER,
                'body'  => $body,
                'state' => Thread::STATE_PUBLISHED,
            ],
            $conversation,
            $identity->customer
        );

        if (!$thread) {
            return $this->fail('unavailable', 503);
        }

        // Their own message is not something they have to read, so the pointer
        // moves past everything already written. Anything an agent says next
        // is unread again.
        $link->sawUpTo(PHP_INT_MAX);

        $identity->takeConversation($conversation->id);
        list(, $token) = ChatSession::reopen($conversation->fresh(), $session->ip, $lang);

        \Log::info(self::LOG_PREFIX.': conversation continued from the history', [
            'conversation_id' => $conversation->id,
            'provider'        => $identity->provider,
        ]);

        return response()->json([
            'status' => 'success',
            'chat'   => ['token' => $token, 'since' => 0],
            'id'     => $thread->id ?? 0,
        ]);
    }

    /**
     * This person has read a conversation up to a message.
     *
     * Called when the panel has actually drawn the messages, never when it has
     * merely listed the conversation. Forward only.
     */
    public function seen(Request $request)
    {
        if (!config('gesoftlivechat.history')) {
            abort(404);
        }

        $session = $this->appSession($request);
        $identity = $session ? $session->identity() : null;

        if (!$identity || !$identity->customer) {
            return $this->fail('unauthorized', 401);
        }

        $link = AppConversation::owned($identity, $request->input('conversation_id'));

        if (!$link) {
            return $this->fail('not_found', 404);
        }

        $link->sawUpTo($request->input('seen'));

        return response()->json(['status' => 'success'] + $this->unread($identity));
    }

    // ------------------------------------------------------------- internals

    /**
     * How much this identity has not read, across everything they own.
     *
     * Cheap enough for every bootstrap: the rows are this identity's alone and
     * the messages are counted in one query. It is what lets the application's
     * own button carry a badge before the panel has been opened at all.
     */
    protected function unread(AppIdentity $identity)
    {
        list($links, ) = $this->owned($identity);

        return [
            'unread'  => History::totals(AppConversation::unreadCounts($links)),
            'history' => $links->isNotEmpty(),
        ];
    }

    /**
     * This identity's conversations: the mapping rows, and the conversations
     * themselves, in two queries whatever the number of them.
     *
     * Returns `[$links, $conversations]`, both already filtered to what this
     * person may actually be shown — the mapping is the boundary, and the
     * customer still has to match, because an agent moving a conversation to
     * somebody else takes it out of this history.
     */
    protected function owned(AppIdentity $identity)
    {
        if (!config('gesoftlivechat.history')) {
            return [collect(), collect()];
        }

        $links = AppConversation::where('identity_id', $identity->id)->get();

        if ($links->isEmpty()) {
            return [$links, collect()];
        }

        $conversations = Conversation::whereIn('id', $links->pluck('conversation_id')->all())
            ->where('customer_id', $identity->customer_id)
            ->get()
            ->filter(function ($conversation) {
                return History::isVisible($conversation->status, $conversation->state);
            })
            ->keyBy('id');

        $links = $links->filter(function ($link) use ($conversations) {
            return $conversations->has($link->conversation_id);
        })->values();

        return [$links, $conversations->values()];
    }

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
