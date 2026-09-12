<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GesoftLiveChat\Entities\AgentPresence;
use Modules\GesoftLiveChat\Entities\AppConversation;
use Modules\GesoftLiveChat\Entities\AppSession;
use Modules\GesoftLiveChat\Entities\ChatBlock;
use Modules\GesoftLiveChat\Entities\ChatReceipt;
use Modules\GesoftLiveChat\Entities\ChatSession;
use Modules\GesoftLiveChat\Support\Apps;
use Modules\GesoftLiveChat\Support\Message;
use Modules\GesoftLiveChat\Support\Origin;
use Modules\GesoftLiveChat\Support\Presence;
use Modules\GesoftLiveChat\Support\Typing;

/**
 * The visitor's half of live chat.
 *
 * Start, send, poll, end, leave, status and the message form for when nobody is
 * available, and a demo page. No session, no CSRF, no login: a customer on
 * their own website has none of those. The only credential is a token minted at
 * `start`, and it addresses exactly one conversation.
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
 *
 * Every refusal carries a `code` as well as a sentence, so the bubble can say it
 * in the visitor's own language.
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
     * Whether anybody is available to chat, so the bubble can offer a chat or
     * the message form. Live Helper Chat's widget asks the same before it
     * decides what to show.
     */
    public function status(Request $request)
    {
        return $this->ok($request, ['online' => AgentPresence::anyoneAvailable($this->mailbox())]);
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
        $lang = $this->lang($request);

        $body = $this->body($request);
        if ($body === null) {
            return $this->fail($request, __('Please write a message first.', [], $lang), 400, 'empty');
        }

        $session = $this->session($request);
        if ($session && $session->canWrite()) {
            return $this->send($request);
        }

        $mailbox = $this->mailbox();
        if (!$mailbox) {
            \Log::error(self::LOG_PREFIX.': no mailbox configured to receive chats');

            return $this->fail($request, __('Chat is not available right now.', [], $lang), 503, 'unavailable');
        }

        // Somebody an application signed in, whose browser is holding the
        // permission that application's server was given. Their identity was
        // settled on an authenticated, server-to-server call; nothing they
        // could type here is consulted.
        if ($app = $this->appSession($request)) {
            return $this->startAsApp($request, $app, $body, $lang, $mailbox);
        }

        if ($refused = $this->refuseBots($request, $lang)) {
            return $refused;
        }

        $email = $this->email($request);

        if (!$email && config('gesoftlivechat.require_email')) {
            return $this->fail($request, __('Please leave an email address so we can reach you.', [], $lang), 422, 'email_required');
        }

        if ($refused = $this->refuseBlocked($request, $email, $mailbox, $lang)) {
            return $refused;
        }

        if ($this->tooManyStarts($request)) {
            return $this->fail($request, __('Too many conversations were started from your connection. Please try again in a few minutes.', [], $lang), 429, 'too_many');
        }

        $customer = $this->customer($email, $request, $lang);

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

            return $this->fail($request, __('Chat is not available right now.', [], $lang), 503, 'unavailable');
        }

        $conversation = $result['conversation'];
        $this->makeActive($conversation);

        list(, $token) = ChatSession::open($conversation, $request->ip(), $lang);

        \Log::info(self::LOG_PREFIX.': chat opened', [
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
        ]);

        return $this->ok($request, [
            'token' => $token,
            'since' => $result['thread']->id ?? 0,
            // The message's own id, which its receipts are addressed by.
            'id'    => $result['thread']->id ?? 0,
        ]);
    }

    /**
     * Open a chat for somebody an application has signed in.
     *
     * Everything that makes the public `start` careful is either already
     * settled or does not apply:
     *
     *  - **who they are** was settled server to server. There is no name, no
     *    address and no bot trap here, because none of them would be evidence
     *    of anything: the customer is the one the mapping names.
     *  - **the start limit** is per identity rather than per address. A whole
     *    institution behind one address is one budget the wrong way round, and
     *    a signed-in person is somebody we can count individually.
     *  - **blocks still apply.** An agent who has blocked somebody has blocked
     *    them, and being signed in to an application is not an appeal.
     */
    protected function startAsApp(Request $request, AppSession $app, $body, $lang, $mailbox)
    {
        $identity = $app->identity();
        $customer = $identity ? $identity->customer : null;

        if (!$identity || !$customer) {
            return $this->fail($request, __('Chat is not available right now.', [], $lang), 401, 'unavailable');
        }

        // A chat opened in another tab between this panel asking to resume and
        // this first message being written. Write into that one rather than
        // opening a second: two live chats for one person are two places for an
        // agent to answer and one the person cannot see.
        if ($conversation = $identity->openConversation()) {
            $identity->takeConversation($conversation->id);

            list(, $token) = ChatSession::open($conversation, $request->ip(), $lang);
            $request->merge(['token' => $token]);

            $response = $this->send($request);
            $payload = json_decode($response->getContent(), true);

            if (!is_array($payload) || ($payload['status'] ?? '') !== 'success') {
                return $response;
            }

            // `since` from the beginning: the panel has nothing on screen, and
            // what it needs next is the conversation this message just joined.
            return $this->ok($request, ['token' => $token, 'since' => 0] + $payload);
        }

        if ($refused = $this->refuseBlocked($request, $customer->getMainEmail(), $mailbox, $lang)) {
            return $refused;
        }

        if ($this->tooManyAppStarts($identity)) {
            return $this->fail($request, __('Too many conversations were started from your connection. Please try again in a few minutes.', [], $lang), 429, 'too_many');
        }

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

            return $this->fail($request, __('Chat is not available right now.', [], $lang), 503, 'unavailable');
        }

        $conversation = $result['conversation'];
        $this->makeActive($conversation);

        // What "the chat this person is in" means from now on, and the row
        // that will let them find it again next month. Filed against the
        // identity and not against the customer, because two application
        // accounts sharing an email address share a customer.
        $identity->takeConversation($conversation->id);

        list(, $token) = ChatSession::open($conversation, $request->ip(), $lang);

        \Log::info(self::LOG_PREFIX.': chat opened from an application', [
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
            'provider'        => $identity->provider,
            // Where in the application they were when they opened the panel.
            'route'           => $app->route,
        ]);

        return $this->ok($request, [
            'token' => $token,
            'since' => $result['thread']->id ?? 0,
            'id'    => $result['thread']->id ?? 0,
        ]);
    }

    /**
     * A message left while nobody is available to chat.
     *
     * It becomes an ordinary email conversation, answered by email like any
     * other — which is what Live Helper Chat's offline form does too. An email
     * address is therefore required whatever `require_email` says: without one
     * the answer has nowhere to go.
     *
     * Same protections as `start`: the bot trap, blocks and the start limit.
     */
    public function offline(Request $request)
    {
        $lang = $this->lang($request);

        $body = $this->body($request);
        if ($body === null) {
            return $this->fail($request, __('Please write a message first.', [], $lang), 400, 'empty');
        }

        $mailbox = $this->mailbox();
        if (!$mailbox) {
            return $this->fail($request, __('Chat is not available right now.', [], $lang), 503, 'unavailable');
        }

        // Somebody an application signed in, writing to us outside our hours.
        // Their address is the one their application vouched for, so there is
        // nothing to ask for and nothing to check a typed address against.
        $app = $this->appSession($request);
        $identity = $app ? $app->identity() : null;

        if ($app && (!$identity || !$identity->customer)) {
            return $this->fail($request, __('Chat is not available right now.', [], $lang), 401, 'unavailable');
        }

        if (!$app && ($refused = $this->refuseBots($request, $lang))) {
            return $refused;
        }

        $email = $app ? $identity->customer->getMainEmail() : $this->email($request);

        // No address at all. For a visitor that means the form was not filled
        // in; for an application identity it means the application has never
        // told us one, and a message left here could only ever be read, never
        // answered. Both are the same refusal.
        if (!$email) {
            return $this->fail($request, __('Please leave an email address so we can reach you.', [], $lang), 422, 'email_required');
        }

        if ($refused = $this->refuseBlocked($request, $email, $mailbox, $lang)) {
            return $refused;
        }

        if ($app ? $this->tooManyAppStarts($identity) : $this->tooManyStarts($request)) {
            return $this->fail($request, __('Too many conversations were started from your connection. Please try again in a few minutes.', [], $lang), 429, 'too_many');
        }

        $customer = $app ? $identity->customer : $this->customer($email, $request, $lang);

        // Marks the conversation as the form's before core announces it, so no
        // auto-reply goes to an address a stranger typed. See Support/Origin.
        Origin::$offlineForm = true;
        $result = Conversation::create(
            [
                'type'        => Conversation::TYPE_EMAIL,
                'subject'     => Conversation::subjectFromText($body),
                'mailbox_id'  => $mailbox->id,
                'source_via'  => Conversation::PERSON_CUSTOMER,
                'source_type' => Conversation::SOURCE_TYPE_WEB,
                'state'       => Conversation::STATE_PUBLISHED,
            ],
            [[
                'type'  => Thread::TYPE_CUSTOMER,
                'body'  => $body,
                'state' => Thread::STATE_PUBLISHED,
            ]],
            $customer
        );
        Origin::$offlineForm = false;

        if (!$result) {
            return $this->fail($request, __('Chat is not available right now.', [], $lang), 503, 'unavailable');
        }

        \Log::info(self::LOG_PREFIX.': message left while nobody was available', [
            'conversation_id' => $result['conversation']->id,
        ]);

        return $this->ok($request, ['left' => true]);
    }

    /**
     * Append a message to the visitor's open conversation.
     */
    public function send(Request $request)
    {
        $lang = $this->lang($request);

        $body = $this->body($request);
        if ($body === null) {
            return $this->fail($request, __('Please write a message first.', [], $lang), 400, 'empty');
        }

        $session = $this->session($request);
        if (!$session || !$session->canWrite()) {
            // Deliberately the same answer for an unknown token, an ended
            // session and a closed conversation: a caller probing tokens learns
            // nothing from the difference.
            return $this->fail($request, __('This conversation is no longer open.', [], $lang), 404, 'closed', ['closed' => true]);
        }

        if ($wait = $this->sendWait($session)) {
            return $this->fail($request, __('You are sending messages too quickly. Please wait a few seconds.', [], $lang), 429, 'too_fast', ['retry_after' => $wait]);
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
        Typing::visitorStopped($conversation->id);

        // `since` stays for bubbles loaded before `id` existed. The bubble no
        // longer moves its poll pointer to it: an agent reply written between
        // its last poll and this message has a smaller id, and was skipped.
        return $this->ok($request, ['since' => $thread->id ?? 0, 'id' => $thread->id ?? 0]);
    }

    /**
     * What has been said since the visitor last looked.
     *
     * A closed conversation still answers with what is left to read — the
     * agent's last words usually arrive together with the close — and says it
     * is closed. After that the token is good for nothing.
     *
     * `notice` asks the bubble to tell a visitor who has waited `wait_after`
     * seconds without an answer that somebody will be with them, or that
     * nobody is available right now. It is worked out here on every poll and
     * never written into the conversation.
     *
     * The poll also carries "is typing" both ways: `typing=1` or `0` says
     * whether the visitor is, and `typing` in the answer names the agent who
     * is. Folded into the poll rather than sent separately because the route
     * throttle counts every request, and a three-second poll already uses
     * most of it.
     *
     * Receipts ride on it the same way. The agents' messages this answer
     * carries are delivered by carrying them; `seen` is the newest agent
     * message the bubble had on screen, and `receipts` in the answer says how
     * far the agents have got with the visitor's.
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
            $agent = $thread->type == Thread::TYPE_MESSAGE;
            $messages[] = [
                'id'     => $thread->id,
                'from'   => $agent ? 'agent' : 'visitor',
                // First name only: enough for the visitor to know who they are
                // talking to, nothing an agent would rather keep to themselves.
                'author' => $agent && $thread->created_by_user_cached ? $thread->created_by_user_cached->first_name : null,
                'body'   => $this->flatten($thread->body),
                'at'     => $thread->created_at ? $thread->created_at->toIso8601String() : null,
            ];
        }

        $open = $session->canWrite();
        $notice = null;
        $typing = null;

        if ($open) {
            $session->seen();
            $notice = $this->notice($conversation);

            if (Typing::enabled()) {
                $flag = (string) $request->input('typing', '');
                if ($flag === '1') {
                    Typing::visitorTyping($conversation);
                } elseif ($flag === '0') {
                    Typing::visitorStopped($conversation->id);
                }

                $name = Typing::agentTypingName($conversation);
                if ($name !== null) {
                    $typing = ['name' => $name !== '' ? $name : null];
                }
            }
        }

        // What the bubble says it has on screen also moves this person's own
        // unread pointer, if this conversation belongs to an application
        // identity. Deliberately outside the `receipts` switch below: that one
        // governs whether ticks are *shown*, and turning ticks off must not
        // leave somebody's unread count stuck at "everything" for good.
        if ($open) {
            AppConversation::sawUpToByConversation($conversation->id, $request->input('seen'));
        }

        $receipts = null;
        if (ChatReceipt::enabled()) {
            $receipt = ChatReceipt::of($conversation->id);

            $newest_agent_message = $threads->where('type', Thread::TYPE_MESSAGE)->max('id');
            if ($newest_agent_message) {
                $receipt->visitorReceived($newest_agent_message);
            }
            $receipt->visitorSaw($request->input('seen'));

            // No time: when an agent read the visitor's message is for agents.
            $show_seen = (bool) config('gesoftlivechat.receipts_seen_to_visitor');
            $receipts = [
                'delivered' => (int) $receipt->agent_delivered_id,
                'seen'      => $show_seen ? (int) $receipt->agent_seen_id : 0,
            ];
        }

        return $this->ok($request, [
            'messages' => $messages,
            'since'    => $threads->count() ? $threads->last()->id : $since,
            'closed'   => !$open,
            'notice'   => $notice,
            'typing'   => $typing,
            'receipts' => $receipts,
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
                $session->note(Presence::ACTION_ENDED);
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
     * The chat as a page of its own, for a link: the bubble, open and filling
     * the window, with the same endpoints behind it. `?lang=en` for English.
     */
    public function page(Request $request)
    {
        $lang = Presence::lang($request->query('lang'), (string) config('gesoftlivechat.visitor_lang', 'ro'));
        $script = __DIR__.'/../../Public/js/widget.js';

        return response()->view('gesoftlivechat::page', $this->look() + [
            'lang'    => $lang,
            'title'   => (string) config('gesoftlivechat.page_title'),
            'source'  => $this->sourceUrl(),
            // A new bubble reaches a visitor who has the page cached.
            'version' => is_file($script) ? filemtime($script) : 1,
        ])->withHeaders($this->pageHeaders());
    }

    /**
     * What the browser is allowed to do on the chat page.
     *
     * This page is public, unauthenticated and, unlike the rest of FreeScout,
     * served outside the `web` middleware group -- so core's own policy never
     * reaches it. Everything it legitimately needs belongs to this origin: one
     * script, the instance's stylesheet, and the endpoints the bubble polls.
     * Anything else -- a script from somewhere else, a form posting away, an
     * iframe of this page on another site -- is refused by the browser rather
     * than noticed by us afterwards.
     *
     * `'unsafe-inline'` for styles is not a concession to a library: the page's
     * ground colour is an inline `<style>` and the bubble writes its own
     * stylesheet into its shadow root. Neither of them is script.
     */
    protected function pageHeaders()
    {
        return [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                // The bubble's own endpoints, on this helpdesk.
                "connect-src 'self'",
                "base-uri 'none'",
                "form-action 'self'",
                // The rule X-Frame-Options states for older browsers.
                "frame-ancestors 'self'",
            ]),
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * The chat as a panel inside another application's page.
     *
     * The same bubble again, open and filling its frame, but this page exists
     * so that the application embedding it never has to run our script. A
     * script tag on their page would have their DOM, their cookies and their
     * session; a cross-origin frame has none of those, and everything that
     * crosses between them is one `postMessage` at a time, each one addressed
     * to an origin named in this helpdesk's own settings.
     *
     * `?o=` is the origin asking to embed it, and it is checked against the
     * register rather than believed: an origin nothing registered gets no page
     * at all, and the page that is served names that one origin — and only
     * that one — as its permitted framer and as the only address it will post
     * to. So a site that frames this page by passing somebody else's origin
     * has told the browser to refuse it.
     */
    public function embed(Request $request)
    {
        $register = Apps::register(config('gesoftlivechat.apps'));
        $origin = Apps::allowedOrigin($register, $request->query('o'));

        if ($origin === null) {
            // Not "forbidden for you": this page is not on offer to anything
            // that is not a registered application, and saying which of the two
            // it was would let a caller enumerate the register.
            abort(404);
        }

        $lang = Presence::lang($request->query('lang'), (string) config('gesoftlivechat.visitor_lang', 'ro'));
        $script = __DIR__.'/../../Public/js/widget.js';

        return response()->view('gesoftlivechat::embed', $this->look() + [
            'lang'    => $lang,
            'title'   => (string) config('gesoftlivechat.page_title'),
            'origin'  => $origin,
            'version' => is_file($script) ? filemtime($script) : 1,
        ])->withHeaders($this->embedHeaders($origin));
    }

    /**
     * What the browser is allowed to do on the embedded page.
     *
     * `pageHeaders()` with one difference, and it is the difference the whole
     * route exists for: `frame-ancestors` names the one application that asked
     * for this page instead of `'self'`.
     *
     * There is deliberately no `X-Frame-Options` here. It has no syntax for a
     * list of origins — `ALLOW-FROM` was never implemented by Chrome and has
     * been dropped from Firefox — so the only honest way to say "this one site
     * may frame this one page" is CSP, which every browser that matters honours
     * in preference to the older header. The route is therefore registered
     * without core's `FrameGuard`, rather than with a policy that contradicts
     * it: see `Http/routes.php`. Every other page of this helpdesk, the
     * operator interface included, keeps `SAMEORIGIN` exactly as before.
     */
    protected function embedHeaders($origin)
    {
        return [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "base-uri 'none'",
                "form-action 'none'",
                'frame-ancestors '.$origin,
            ]),
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            // The page is minted for one application and one visitor's sitting;
            // a shared cache holding it would be holding the wrong thing.
            'Cache-Control'          => 'no-store, private',
        ];
    }

    /**
     * Where this instance's corresponding source lives, for the link at the
     * foot of the chat window. Empty leaves the window without one.
     *
     * `source_ref` names what is running here and is appended as `/tree/<ref>`;
     * see the settings in `Config/config.php` for why the offer is made on this
     * page at all.
     *
     * Both values are checked here rather than trusted. They end up in an
     * attribute and then in an `href`, so a mistyped `.env` must not be able to
     * put `javascript:` or a quote there: only `http` or `https`, nothing that
     * would end the attribute, and for the ref only the characters a git ref
     * may contain.
     */
    protected function sourceUrl()
    {
        if (!config('gesoftlivechat.source_link')) {
            return '';
        }

        $url = trim((string) config('gesoftlivechat.source_url'));
        $ref = trim((string) config('gesoftlivechat.source_ref'));

        if (!preg_match('~^https?://[^\s"\'<>]+$~', $url)) {
            return '';
        }

        if ($ref !== '' && preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', $ref) && strpos($ref, '..') === false) {
            return rtrim($url, '/').'/tree/'.$ref;
        }

        return $url;
    }

    /**
     * The instance's colour, scheme and stylesheet for the bubble, each checked
     * before it is written into an attribute.
     */
    protected function look()
    {
        $color = (string) config('gesoftlivechat.color');
        $theme = config('gesoftlivechat.theme');
        $sheet = (string) config('gesoftlivechat.stylesheet');

        return [
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '',
            'theme' => in_array($theme, ['light', 'dark'], true) ? $theme : '',
            'sheet' => preg_match('~^/[^/]~', $sheet) ? $sheet : '',
        ];
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

        return view('gesoftlivechat::demo', $this->look());
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

    /** The language the bubble is speaking, from what it sent. */
    protected function lang(Request $request)
    {
        $raw = $request->input('lang');
        if ($raw === null) {
            $json = json_decode((string) $request->getContent(), true);
            $raw = is_array($json) ? ($json['lang'] ?? null) : null;
        }

        return Presence::lang($raw, (string) config('gesoftlivechat.visitor_lang', 'ro'));
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
     * The application permission a request is carrying, or null.
     *
     * A separate credential from the visitor's session token and never
     * interchangeable with it: this one says "act as this identity", the other
     * says "this conversation". It arrives as `app_token` so that a request
     * carrying both is unambiguous.
     */
    protected function appSession(Request $request)
    {
        $token = $request->input('app_token');

        if ($token === null) {
            $raw = json_decode((string) $request->getContent(), true);
            $token = is_array($raw) ? ($raw['app_token'] ?? null) : null;
        }

        return AppSession::findByToken($token, Apps::register(config('gesoftlivechat.apps')));
    }

    /**
     * How many chats one application identity may open, counted per person
     * rather than per address. See `tooManyStarts()` for why starting has a
     * budget of its own at all.
     */
    protected function tooManyAppStarts($identity)
    {
        $max = (int) config('gesoftlivechat.app_start_limit');
        if ($max <= 0) {
            return false;
        }

        $minutes = max(1, (int) config('gesoftlivechat.app_start_window'));
        $limiter = app(RateLimiter::class);
        $key = 'gesoftlivechat:appstart:'.sha1($identity->provider."\0".$identity->external_id);

        if ($limiter->tooManyAttempts($key, $max, $minutes)) {
            return true;
        }

        $limiter->hit($key, $minutes);

        return false;
    }

    /**
     * The bot trap. The bubble's forms carry a field no person can see or
     * reach with the keyboard; software that fills in every field fills in
     * that one too. The answer is the ordinary "not available" so the script
     * learns nothing about why.
     */
    protected function refuseBots(Request $request, $lang)
    {
        if (trim((string) $request->input('company', '')) === '') {
            return null;
        }

        \Log::info(self::LOG_PREFIX.': bot trap filled, request refused');

        return $this->fail($request, __('Chat is not available right now.', [], $lang), 422, 'unavailable');
    }

    /**
     * A blocked address or email starts nothing. Like Live Helper Chat's ban
     * message, the refusal points at another way to reach us rather than
     * explaining the block.
     */
    protected function refuseBlocked(Request $request, $email, $mailbox, $lang)
    {
        if (!ChatBlock::applies($request->ip(), $email)) {
            return null;
        }

        \Log::info(self::LOG_PREFIX.': blocked visitor refused');

        $contact = $mailbox ? (string) $mailbox->email : '';
        $message = $contact !== ''
            ? __('Chat is not available. You can write to us at :email.', ['email' => $contact], $lang)
            : __('Chat is not available. Please contact us another way.', [], $lang);

        return $this->fail($request, $message, 403, 'blocked', ['contact' => $contact]);
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
     * How long this chat must wait before sending another message, or zero —
     * in which case the message about to be stored is counted.
     *
     * Per session rather than per address. The route throttle is per address
     * and has to be loose enough for an office full of visitors; this is what
     * stops any one of them burying an agent. The rule is
     * `Presence::sendWait()`; this only keeps the timestamps, in the cache.
     * Agents are not limited.
     */
    protected function sendWait(ChatSession $session)
    {
        $windows = [
            [(int) config('gesoftlivechat.send_burst_seconds'), (int) config('gesoftlivechat.send_burst')],
            [60, (int) config('gesoftlivechat.send_limit')],
        ];

        $key = 'gesoftlivechat:sent:'.$session->id;
        $now = time();
        $sent = Presence::keepRecent((array) \Cache::get($key, []), $now, 60);

        $wait = Presence::sendWait($sent, $now, $windows);
        if ($wait > 0) {
            return $wait;
        }

        $sent[] = $now;
        \Cache::put($key, $sent, now()->addSeconds(70));

        return 0;
    }

    /**
     * "Somebody will be with you shortly", or "nobody is available right now",
     * once the visitor has waited without an answer.
     */
    protected function notice(Conversation $conversation)
    {
        $wait_after = (int) config('gesoftlivechat.wait_after');
        if ($wait_after <= 0) {
            return null;
        }

        $agent_replied = $conversation->threads()
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('state', Thread::STATE_PUBLISHED)
            ->exists();
        if ($agent_replied) {
            return null;
        }

        $first = $conversation->threads()
            ->where('type', Thread::TYPE_CUSTOMER)
            ->orderBy('id')
            ->first();
        $first_at = $first && $first->created_at ? $first->created_at->getTimestamp() : null;

        if (!Presence::shouldTellToWait($first_at, false, time(), $wait_after)) {
            return null;
        }

        return AgentPresence::anyoneAvailable($conversation->mailbox) ? 'waiting' : 'nobody_available';
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
        return Message::store($request->input('message', ''), self::MAX_BODY);
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
        return Message::text($html);
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
    protected function customer($email, Request $request, $lang)
    {
        $data = ['first_name' => $this->name($request, $lang)];

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

    protected function name(Request $request, $lang)
    {
        $name = trim((string) $request->input('name', ''));
        $name = mb_substr(strip_tags($name), 0, 40);

        return $name !== '' ? $name : __('Website visitor', [], $lang);
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

    protected function fail(Request $request, $message, $status = 400, $code = 'error', array $extra = [])
    {
        return $this->cors($request, response()->json([
            'status' => 'error',
            'code'   => $code,
            'msg'    => $message,
        ] + $extra, $status));
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
