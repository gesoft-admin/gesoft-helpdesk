<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GesoftLiveChat\Entities\AgentPresence;
use Modules\GesoftLiveChat\Entities\ChatBlock;
use Modules\GesoftLiveChat\Entities\ChatSession;
use Modules\GesoftLiveChat\Support\Blocking;
use Modules\GesoftLiveChat\Support\Presence;
use Modules\GesoftLiveChat\Support\Typing;

/**
 * What the agent's interface needs from live chat, from anywhere in it.
 *
 * FreeScout answers none of this. Its Chats link carries no count, and the
 * realtime channel that would announce a new chat is only subscribed to when
 * the sidebar is *already* showing the chat list (`public/js/main.js`, guarded
 * on `#folders.chats`). So an agent reading a ticket has no way to learn that
 * somebody is waiting in a chat — which is the one thing about chat that is
 * different from email.
 *
 * Everything here is behind the session and the agent's own mailbox
 * permissions: the chat count and presence, "are you still there?", blocking a
 * visitor, and the list of blocks for administrators.
 */
class AgentController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Ask the customer whether they are still there, and mark the chat idle.
     *
     * The same two things the sweep does automatically, done deliberately and
     * at once — an agent who can see the customer has gone quiet should not
     * have to wait out a timer to say so.
     *
     * It is a real message to a real person, so it goes through the same path
     * any agent reply does, in the language the visitor's bubble speaks.
     */
    public function nudge(Request $request, $conversation_id)
    {
        $conversation = Conversation::findOrFail($conversation_id);
        $this->authorize('viewCached', $conversation);

        if (!$conversation->isChat()) {
            return response()->json(['status' => 'error', 'msg' => __('Not a chat conversation.')], 400);
        }

        $configured = (string) config('gesoftlivechat.idle_prompt');
        $text = $configured !== '' ? $configured : __('Are you still there?', [], ChatSession::langFor($conversation));

        \App\Thread::createExtended(
            [
                'type'               => \App\Thread::TYPE_MESSAGE,
                'body'               => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'state'              => \App\Thread::STATE_PUBLISHED,
                'created_by_user_id' => auth()->id(),
            ],
            $conversation,
            $conversation->customer
        );

        // Marked here rather than left to the sweep: the agent has just said
        // the ball is in the customer's court, and the list should show that
        // now, not in five minutes.
        $conversation->changeStatus(Conversation::STATUS_PENDING, auth()->user());

        return response()->json(['status' => 'success']);
    }

    /**
     * Block the visitor of a chat: their address, their email, or both.
     *
     * Live Helper Chat's ban, set from the conversation. The chat ends for the
     * visitor at once — their token stops working — and a line in the
     * conversation records who blocked them. The visitor cannot start another
     * chat, or leave a message, while the block lasts.
     */
    public function block(Request $request, $conversation_id)
    {
        $conversation = Conversation::findOrFail($conversation_id);
        $this->authorize('viewCached', $conversation);

        if (!$conversation->isChat()) {
            return response()->json(['status' => 'error', 'msg' => __('Not a chat conversation.')], 400);
        }

        $expires = Blocking::expiresAt($request->input('days', 1), time());
        $want_ip = filter_var($request->input('block_ip'), FILTER_VALIDATE_BOOLEAN);
        $want_email = filter_var($request->input('block_email'), FILTER_VALIDATE_BOOLEAN);

        if ($expires === false || (!$want_ip && !$want_email)) {
            return response()->json(['status' => 'error', 'msg' => __('Choose what to block.')], 422);
        }

        $session = ChatSession::where('conversation_id', $conversation->id)->orderBy('id', 'desc')->first();
        $ip = $want_ip && $session ? Blocking::normalizeIp($session->ip) : null;
        $email = $want_email ? Blocking::normalizeEmail($conversation->customer_email) : null;

        if (!$ip && !$email) {
            return response()->json(['status' => 'error', 'msg' => __('Nothing to block: this visitor left no address or email.')], 422);
        }

        $reason = mb_substr(trim(strip_tags((string) $request->input('reason', ''))), 0, 191);

        foreach ([Blocking::KIND_IP => $ip, Blocking::KIND_EMAIL => $email] as $kind => $value) {
            if (!$value) {
                continue;
            }
            ChatBlock::create([
                'kind'               => $kind,
                'value'              => $value,
                'conversation_id'    => $conversation->id,
                'created_by_user_id' => auth()->id(),
                'reason'             => $reason !== '' ? $reason : null,
                'expires_at'         => $expires ? \Carbon\Carbon::createFromTimestamp($expires) : null,
            ]);
        }

        ChatSession::where('conversation_id', $conversation->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        Thread::create($conversation, Thread::TYPE_LINEITEM, '', [
            'user_id'            => $conversation->user_id,
            'created_by_user_id' => auth()->id(),
            'action_type'        => Presence::ACTION_BLOCKED,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
            'customer_id'        => $conversation->customer_id,
        ]);

        \Log::info('GesoftLiveChat: visitor blocked', [
            'conversation_id' => $conversation->id,
            'ip'              => (bool) $ip,
            'email'           => (bool) $email,
            'days'            => (int) $request->input('days', 1),
        ]);

        return response()->json(['status' => 'success']);
    }

    /**
     * "Is typing", from the agent's side: whether this agent is writing a
     * reply in the conversation, and in the answer whether its visitor is.
     *
     * One request both ways, every three seconds, from a chat conversation's
     * page. The page decides what counts as typing, and a note never does.
     * Nothing of what either side types is sent.
     */
    public function typing(Request $request, $conversation_id)
    {
        $conversation = Conversation::findOrFail($conversation_id);
        $this->authorize('viewCached', $conversation);

        if (!$conversation->isChat() || !Typing::enabled()) {
            return response()->json(['status' => 'success', 'visitor_typing' => false]);
        }

        $user = auth()->user();
        if (filter_var($request->input('typing'), FILTER_VALIDATE_BOOLEAN)) {
            Typing::agentTyping($conversation, $user);
        } else {
            Typing::agentStopped($conversation, $user);
        }

        return response()->json([
            'status'         => 'success',
            'visitor_typing' => Typing::isVisitorTyping($conversation),
        ]);
    }

    /**
     * Blocks still in force, for administrators: Manage → Blocked chat
     * visitors.
     */
    public function blocks(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $blocks = ChatBlock::active()->with(['creator'])->orderBy('id', 'desc')->get();

        return view('gesoftlivechat::blocks', ['blocks' => $blocks]);
    }

    public function unblock(Request $request, $id)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        ChatBlock::findOrFail($id)->delete();

        return redirect()->route('gesoftlivechat.agent.blocks');
    }

    /**
     * Active chats the signed-in agent is allowed to see.
     *
     * Scoped by mailbox permission, and by assignment where the user may only
     * see their own — the same two rules `Conversation::getChats()` applies,
     * because a count that includes conversations the agent cannot open is a
     * badge that never clears.
     *
     * Also the agents' heartbeat: this is asked every ten seconds from every
     * page an agent has open, which is what tells the bubble somebody is there.
     */
    public function chats(Request $request)
    {
        $user = auth()->user();
        AgentPresence::seen($user->id);

        $mailbox_ids = Mailbox::whereHas('users', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        })->pluck('id');

        if ($user->isAdmin()) {
            $mailbox_ids = Mailbox::pluck('id');
        }

        $query = Conversation::where('type', Conversation::TYPE_CHAT)
            ->whereIn('mailbox_id', $mailbox_ids)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING]);

        if ($user->canSeeOnlyAssignedConversations()) {
            $query->where('user_id', $user->id);
        }

        $latest = (clone $query)->orderBy('last_reply_at', 'desc')->first();

        // The signal the browser watches has to change on every *message*, not
        // every conversation. Watching the conversation id meant a customer
        // replying in a chat that already existed changed nothing at all, so
        // nothing was ever announced for it — and a first message only
        // surfaced on the next slow poll, which reads as arriving one message
        // late.
        //
        // Customer messages only. An agent does not need telling about their
        // own reply.
        $conversation_ids = (clone $query)->pluck('id');
        $latest_message = \App\Thread::whereIn('conversation_id', $conversation_ids)
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->orderBy('id', 'desc')
            ->first();

        $from = null;
        if ($latest_message) {
            $from = Conversation::find($latest_message->conversation_id);
        }

        // How many different chats have spoken since the message the browser
        // last saw. One means the alert can open that chat; more means any
        // single chat would be a guess, so it opens the list instead.
        $since = (int) $request->input('since', 0);
        $new_conversations = 0;
        if ($since > 0) {
            $new_conversations = \App\Thread::whereIn('conversation_id', $conversation_ids)
                ->where('type', \App\Thread::TYPE_CUSTOMER)
                ->where('state', \App\Thread::STATE_PUBLISHED)
                ->where('id', '>', $since)
                ->distinct()
                ->count('conversation_id');
        }

        $mailbox_id = $latest->mailbox_id ?? ($mailbox_ids->first() ?? 0);
        $list_mailbox_id = $from->mailbox_id ?? $mailbox_id;

        // Whether each visible chat's visitor is still there, for the dot in
        // the chat list. Newest session per conversation wins.
        $presence = [];
        $sessions = ChatSession::whereIn('conversation_id', $conversation_ids)
            ->orderBy('id')
            ->get();
        foreach ($sessions as $session) {
            $presence[$session->conversation_id] = $session->state();
        }

        return response()->json([
            'status'      => 'success',
            'count'       => $query->count(),
            // Changes whenever a customer says anything, in any visible chat.
            'latest_id'   => $latest_message->id ?? 0,
            'latest_at'   => $latest && $latest->last_reply_at ? $latest->last_reply_at->timestamp : 0,
            'latest_name' => $from && $from->customer ? $from->customer->getFullName(true) : '',
            'latest_conversation_id' => $latest_message->conversation_id ?? 0,
            // Built by core, the same way its own chat list builds them, so the
            // browser never has to know what a conversation URL looks like.
            'latest_url'  => $from ? $from->url(null, null, ['chat_mode' => 1]) : '',
            'list_url'    => $list_mailbox_id && \Route::has('conversations.chats')
                ? route('conversations.chats', ['mailbox_id' => $list_mailbox_id])
                : '',
            'new_conversations' => $new_conversations,
            // conversation id => here | left | ended. An object even when
            // empty, so the browser always gets a map.
            'presence'    => (object) $presence,
            // Where the header indicator should send an agent who clicks it.
            // Pages outside a mailbox have no mailbox of their own, so the
            // answer travels with the count rather than being guessed in the
            // browser.
            'mailbox_id'  => $mailbox_id,
        ]);
    }
}
