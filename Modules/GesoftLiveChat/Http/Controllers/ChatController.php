<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GesoftLiveChat\Entities\ChatSession;

/**
 * The visitor's half of live chat.
 *
 * Start, send, poll, end and leave, and a demo page. No session, no CSRF, no
 * login: a customer on their own website has none of those. The only
 * credential is a token minted at `start`, and it addresses exactly one
 * conversation.
 *
 * Three rules run through everything here, and all of them are about the fact
 * that this is the one part of the system a stranger can reach.
 *
 * **The token is the only way in, and it opens one conversation.** Nothing the
 * visitor types — a name, an email address — ever selects a conversation. An
 * earlier version found the customer by email and gave the token to the
 * customer, which let anybody who typed another person's address read and write
 * that person's open chat. See `Entities/ChatSession.php`.
 *
 * **Nothing internal is ever returned.** The poll hands back published customer
 * and agent messages and nothing else — never a note, never a draft, never a
 * line item. A note is what an agent writes believing the customer cannot read
 * it, so leaking one is worse than leaking a reply.
 *
 * **Nothing arrives as markup.** What the visitor types is escaped before it is
 * stored, because the agent's browser renders thread bodies as HTML. What the
 * agent writes is flattened to text before it is sent out, because the bubble
 * has no business rendering markup either.
 */
class ChatController extends Controller
{
    const LOG_PREFIX = 'GesoftLiveChat';

    /** Longest single message accepted, in characters. */
    const MAX_BODY = 4000;

    // ------------------------------------------------------------- endpoints

    /**
     * Preflight for a bubble hosted on somebody else's domain.
     */
    public function preflight(Request $request)
    {
        return $this->cors($request, response('', 204));
    }

    /**
     * Open a conversation with the visitor's first message.
     *
     * Returns the token the bubble keeps for this tab. Calling it again with a
     * token whose conversation is still open writes into that conversation, so
     * a double submit cannot open a second one.
     */
    public function start(Request $request)
    {
        $body = $this->body($request);
        if ($body === null) {
            return $this->fail($request, __('Please write a message first.'));
        }

        $session = $this->session($request);
        if ($session && $session->canWrite()) {
            return $this->send($request);
        }

        $mailbox = $this->mailbox();
        if (!$mailbox) {
            \Log::error(self::LOG_PREFIX.': no mailbox configured to receive chats');

            return $this->fail($request, __('Chat is not available right now.'), 503);
        }

        $email = $this->email($request);

        if (!$email && config('gesoftlivechat.require_email')) {
            return $this->fail($request, __('Please leave an email address so we can reach you.'), 422);
        }

        if ($this->tooManyStarts($request)) {
            return $this->fail($request, __('Too many conversations were started from your connection. Please try again in a few minutes.'), 429);
        }

        $customer = $this->customer($email, $request);

        // Marks the customer as reachable on this channel, which is what core
        // reads to show the channel on their profile. The id says which
        // customer it is and nothing more: it is not a credential, and nothing
        // here looks a visitor up by it.
        $customer->addChannel($this->channel(), 'customer-'.$customer->id);

        $result = Conversation::create(
            [
                'type'        => Conversation::TYPE_CHAT,
                'subject'     => Conversation::subjectFromText($body),
                'mailbox_id'  => $mailbox->id,
                'source_via'  => Conversation::PERSON_CUSTOMER,
                'source_type' => Conversation::SOURCE_TYPE_WEB,
                'state'       => Conversation::STATE_PUBLISHED,
                'channel'     => $this->channel(),
            ],
            [[
                'type'  => Thread::TYPE_CUSTOMER,
                'body'  => $body,
                'state' => Thread::STATE_PUBLISHED,
            ]],
            $customer
        );

        if (!$result) {
            \Log::error(self::LOG_PREFIX.': Conversation::create() created no thread');

            return $this->fail($request, __('Chat is not available right now.'), 503);
        }

        $conversation = $result['conversation'];
        $this->makeActive($conversation);

        list(, $token) = ChatSession::open($conversation);

        \Log::info(self::LOG_PREFIX.': chat opened', [
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
        ]);

        return $this->ok($request, [
            'token' => $token,
            'since' => $result['thread']->id ?? 0,
        ]);
    }

    /**
     * Append a message to the visitor's open conversation.
     */
    public function send(Request $request)
    {
        $body = $this->body($request);
        if ($body === null) {
            return $this->fail($request, __('Please write a message first.'));
        }

        $session = $this->session($request);
        if (!$session || !$session->canWrite()) {
            // Deliberately the same answer for an unknown token, an ended
            // session and a closed conversation: a caller probing tokens learns
            // nothing from the difference.
            return $this->fail($request, __('This conversation is no longer open.'), 404, ['closed' => true]);
        }

        $conversation = $session->conversation;

        $thread = Thread::createExtended(
            [
                'type'  => Thread::TYPE_CUSTOMER,
                'body'  => $body,
                'state' => Thread::STATE_PUBLISHED,
            ],
            $conversation,
            $conversation->customer
        );

        $this->makeActive($conversation);
        $session->seen();

        return $this->ok($request, ['since' => $thread->id ?? 0]);
    }

    /**
     * What has been said since the visitor last looked.
     *
     * A closed conversation still answers with what is left to read — the
     * agent's last words usually arrive together with the close — and says it
     * is closed. After that the token is good for nothing.
     */
    public function poll(Request $request)
    {
        $session = $this->session($request);
        $conversation = $session ? $session->conversation : null;

        if (!$conversation || $conversation->state != Conversation::STATE_PUBLISHED) {
            return $this->ok($request, ['messages' => [], 'closed' => true]);
        }

        $since = (int) $request->input('since', 0);

        // Published customer and agent messages only. Notes, drafts and line
        // items are not in this list and must never be added to it.
        $threads = $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->get();

        $messages = [];
        foreach ($threads as $thread) {
            $messages[] = [
                'id'   => $thread->id,
                'from' => $thread->type == Thread::TYPE_CUSTOMER ? 'visitor' : 'agent',
                'body' => $this->flatten($thread->body),
                'at'   => $thread->created_at ? $thread->created_at->toIso8601String() : null,
            ];
        }

        $open = $session->canWrite();
        if ($open) {
            $session->seen();
        }

        return $this->ok($request, [
            'messages' => $messages,
            'since'    => $threads->count() ? $threads->last()->id : $since,
            'closed'   => !$open,
        ]);
    }

    /**
     * The visitor ended the chat on purpose.
     *
     * The token stops working at once, and a line in the conversation tells the
     * operator now rather than leaving them to infer it from silence. The
     * conversation itself is left for the operator to close: the customer may
     * have spoken last, and a customer walking away has not had their question
     * answered.
     */
    public function end(Request $request)
    {
        $session = $this->session($request);

        if ($session && $session->ended_at === null) {
            $session->ended_at = now();
            $session->save();

            if ($session->isConversationOpen()) {
                $session->note(\Modules\GesoftLiveChat\Support\Presence::ACTION_ENDED);
            }
        }

        // The same answer whether or not the token meant anything.
        return $this->ok($request, []);
    }

    /**
     * The tab is closing — or reloading, which looks the same from here.
     *
     * Only recorded. Whether the visitor really left is decided later by the
     * sweep, once they fail to come back; a reload polls again within seconds
     * and clears this.
     */
    public function leave(Request $request)
    {
        $session = $this->session($request);

        if ($session && $session->ended_at === null && $session->left_at === null) {
            $session->left_at = now();
            $session->save();
        }

        return $this->cors($request, response('', 204));
    }

    /**
     * A page that hosts the bubble, for testing the transport before anything
     * is embedded on a real site. Not a product surface — it exists only while
     * `dev_tools` is on.
     */
    public function demo(Request $request)
    {
        if (!config('gesoftlivechat.dev_tools')) {
            abort(404);
        }

        return view('gesoftlivechat::demo');
    }

    // ------------------------------------------------------------- internals

    protected function channel()
    {
        return (int) config('gesoftlivechat.channel');
    }

    /**
     * Which mailbox receives chats. The first one unless configured, which is
     * right for a single-mailbox installation and stated rather than assumed.
     */
    protected function mailbox()
    {
        $id = config('gesoftlivechat.mailbox_id');

        return $id ? Mailbox::find($id) : Mailbox::orderBy('id')->first();
    }

    /**
     * The visitor's session, or null.
     *
     * The token arrives as a query parameter, a JSON field, or — from the
     * goodbye beacon — inside a text/plain body, which Laravel does not parse.
     */
    protected function session(Request $request)
    {
        $token = $request->input('token');

        if ($token === null) {
            $raw = json_decode((string) $request->getContent(), true);
            $token = is_array($raw) ? ($raw['token'] ?? null) : null;
        }

        return ChatSession::findByToken($token);
    }

    /**
     * A separate, tighter limit on opening conversations.
     *
     * The route throttle counts every request, polls included, so it has to be
     * generous enough for a bubble polling every three seconds — and at that
     * rate a script could open a conversation every two seconds, each one
     * something an agent has to read. Starting is the expensive, visible call,
     * so it gets its own budget per address. Only calls that would actually
     * create a conversation are counted.
     */
    protected function tooManyStarts(Request $request)
    {
        $max = (int) config('gesoftlivechat.start_limit');
        if ($max <= 0) {
            return false;
        }

        $minutes = max(1, (int) config('gesoftlivechat.start_window'));
        $limiter = app(RateLimiter::class);
        $key = 'gesoftlivechat:start:'.sha1((string) $request->ip());

        if ($limiter->tooManyAttempts($key, $max, $minutes)) {
            return true;
        }

        $limiter->hit($key, $minutes);

        return false;
    }

    /**
     * The message, escaped, or null if there is nothing worth storing.
     *
     * Escaped here rather than at render time because the agent's browser
     * renders thread bodies as HTML. A visitor typing markup gets to see their
     * own angle brackets; they do not get to run anything in an agent's
     * session.
     */
    protected function body(Request $request)
    {
        $raw = trim((string) $request->input('message', ''));
        if ($raw === '') {
            return null;
        }

        $raw = mb_substr($raw, 0, self::MAX_BODY);

        return nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /**
     * An agent's reply, flattened to text.
     *
     * The agent writes in a rich editor and the result is HTML. The bubble has
     * no business rendering that — it would be handing a customer's browser
     * markup written by somebody else — so it goes out as text.
     */
    protected function flatten($html)
    {
        $text = \Helper::htmlToText((string) $html);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * The visitor as a FreeScout customer.
     *
     * With an address, `Customer::create()` finds the existing record for it,
     * so a customer who has emailed us before keeps one profile and one
     * history on the agent's side. That is all the address does: it groups
     * conversations for the agent, and it never gives the visitor access to
     * any of them.
     */
    protected function customer($email, Request $request)
    {
        $data = ['first_name' => $this->name($request)];

        $phone = trim(strip_tags((string) $request->input('phone', '')));
        if ($phone !== '') {
            $data['phones'] = [mb_substr($phone, 0, 40)];
        }

        if ($email) {
            $customer = Customer::create($email, $data);
            if ($customer) {
                return $customer;
            }
        }

        return Customer::createWithoutEmail($data);
    }

    /** The address, or null if it is missing or not one. */
    protected function email(Request $request)
    {
        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 191) : null;
    }

    protected function name(Request $request)
    {
        $name = trim((string) $request->input('name', ''));
        $name = mb_substr(strip_tags($name), 0, 40);

        return $name !== '' ? $name : __('Website visitor');
    }

    /**
     * The chat list shows active and pending conversations only, so a
     * conversation created in any other status has to be put where an agent
     * will see it.
     */
    protected function makeActive(Conversation $conversation)
    {
        if (!in_array($conversation->status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])) {
            $conversation->status = Conversation::STATUS_ACTIVE;
            $conversation->save();
        }
    }

    // ------------------------------------------------------------- responses

    protected function ok(Request $request, array $data)
    {
        return $this->cors($request, response()->json(['status' => 'success'] + $data));
    }

    protected function fail(Request $request, $message, $code = 400, array $extra = [])
    {
        return $this->cors($request, response()->json([
            'status' => 'error',
            'msg'    => $message,
        ] + $extra, $code));
    }

    /**
     * Allow the configured origins, and only those.
     *
     * Empty configuration means same-origin only, which is what the demo page
     * needs and is the safe default: a wildcard here would let any site on the
     * internet open conversations in our helpdesk from a visitor's browser.
     */
    protected function cors(Request $request, $response)
    {
        $origin = (string) $request->headers->get('Origin');
        if ($origin === '') {
            return $response;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('gesoftlivechat.origins'))));
        if (!in_array($origin, $allowed, true)) {
            return $response;
        }

        return $response
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Vary', 'Origin')
            ->header('Access-Control-Allow-Headers', 'Content-Type')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }
}
