<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The visitor's half of live chat.
 *
 * Three calls — start, send, poll — and a demo page. No session, no CSRF, no
 * login: a customer on their own website has none of those. The only credential
 * is a token minted at `start`, which addresses exactly one customer.
 *
 * Two rules run through everything here, and both are about the fact that this
 * is the one part of the system a stranger can reach.
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
     * Returns the token the bubble keeps. Calling it again with a token that is
     * still good is not an error — a visitor who reloads the page should not
     * lose the thread, and should not silently open a second one either.
     */
    public function start(Request $request)
    {
        $body = $this->body($request);
        if ($body === null) {
            return $this->fail($request, __('Please write a message first.'));
        }

        $mailbox = $this->mailbox();
        if (!$mailbox) {
            \Log::error(self::LOG_PREFIX.': no mailbox configured to receive chats');

            return $this->fail($request, __('Chat is not available right now.'), 503);
        }

        // An existing visitor whose conversation is still open just writes into
        // it. This is what makes a page reload harmless.
        $token = (string) $request->input('token');
        if ($token !== '' && ($existing = $this->conversationFor($token))) {
            return $this->send($request);
        }

        $email = $this->email($request);

        if (!$email && config('gesoftlivechat.require_email')) {
            return $this->fail($request, __('Please leave an email address so we can reach you.'), 422);
        }

        $token = bin2hex(random_bytes(16));
        $customer = $this->customer($email, $request);

        // One live token per customer on this channel: `addChannel()` replaces
        // the stored id. A returning customer opening the widget on a second
        // device therefore takes the session with them, and the first device's
        // token stops resolving — it degrades to "this conversation is no
        // longer open", which is the truth from that tab's point of view.
        $customer->addChannel($this->channel(), $token);

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

        list($conversation, $customer) = $this->resolve($request);
        if (!$conversation) {
            // Deliberately the same answer as an unknown token: a caller
            // probing tokens learns nothing from the difference between "no
            // such visitor" and "that conversation is closed".
            return $this->fail($request, __('This conversation is no longer open.'), 404);
        }

        $thread = Thread::createExtended(
            [
                'type'  => Thread::TYPE_CUSTOMER,
                'body'  => $body,
                'state' => Thread::STATE_PUBLISHED,
            ],
            $conversation,
            $customer
        );

        $this->makeActive($conversation);

        return $this->ok($request, ['since' => $thread->id ?? 0]);
    }

    /**
     * What has been said since the visitor last looked.
     */
    public function poll(Request $request)
    {
        list($conversation, $customer) = $this->resolve($request);
        if (!$conversation) {
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

        return $this->ok($request, [
            'messages' => $messages,
            'since'    => $threads->count() ? $threads->last()->id : $since,
            'closed'   => false,
        ]);
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
     * The visitor's conversation, or null.
     *
     * Returns `[$conversation, $customer]`. A closed conversation resolves to
     * null: the visitor's next message opens a new one rather than reviving a
     * thread an agent considered finished.
     */
    protected function resolve(Request $request)
    {
        $conversation = $this->conversationFor((string) $request->input('token'));

        return [$conversation, $conversation ? $conversation->customer : null];
    }

    protected function conversationFor($token)
    {
        if ($token === '' || !preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        $customer = Customer::getCustomerByChannel($this->channel(), $token);
        if (!$customer) {
            return null;
        }

        $open = Conversation::where('customer_id', $customer->id)
            ->where('type', Conversation::TYPE_CHAT)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->orderBy('id', 'desc')
            ->first();

        if ($open) {
            return $open;
        }

        return $this->recentlyClosed($customer);
    }

    /**
     * A closed chat the visitor may still write into.
     *
     * **FreeScout already owns this decision**, and this module was answering
     * it on its own before anybody read the setting. Each mailbox has "Start a
     * new conversation when receiving a reply to the closed / deleted Chat
     * conversation", and `Conversation::chatShouldStartNew()` is what reads it.
     * Unticked — the default — a returning customer belongs in the same
     * conversation however long they were gone, which is also what FreeScout's
     * own chat module documents.
     *
     * So the setting decides, and our window only refines the case where an
     * operator has asked for new conversations: even then, "the agent closed it
     * while the visitor was typing" is not a new problem, and a message two
     * minutes later belongs where the rest of it is.
     */
    protected function recentlyClosed(Customer $customer)
    {
        $closed = Conversation::where('customer_id', $customer->id)
            ->where('type', Conversation::TYPE_CHAT)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->where('status', Conversation::STATUS_CLOSED)
            ->orderBy('id', 'desc')
            ->first();

        if (!$closed) {
            return null;
        }

        // The mailbox says a reply to a closed chat starts a fresh one. Honour
        // that, except for the moments right after closing.
        if ($closed->chatShouldStartNew()) {
            $window = (int) config('gesoftlivechat.reopen_window');

            if ($window <= 0 || !$closed->closed_at || $closed->closed_at->lt(now()->subSeconds($window))) {
                return null;
            }
        }

        return $closed;
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
     * history. Without one there is nothing to match on and a fresh record is
     * the only honest answer.
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
     * The chat list shows active and pending conversations only, so a chat
     * that somebody closed and the visitor reopened has to be put back.
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

    protected function fail(Request $request, $message, $code = 400)
    {
        return $this->cors($request, response()->json([
            'status' => 'error',
            'msg'    => $message,
        ], $code));
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
