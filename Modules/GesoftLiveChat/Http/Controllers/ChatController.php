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

        $token = bin2hex(random_bytes(16));

        $customer = Customer::createWithoutEmail([
            'first_name' => $this->name($request),
        ]);
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

        return Conversation::where('customer_id', $customer->id)
            ->where('type', Conversation::TYPE_CHAT)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->orderBy('id', 'desc')
            ->first();
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
