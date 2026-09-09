<?php

namespace Modules\GesoftLiveChat\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;

/**
 * What the agent's interface needs to know about chats, from anywhere in it.
 *
 * FreeScout answers this nowhere. Its Chats link carries no count, and the
 * realtime channel that would announce a new chat is only subscribed to when
 * the sidebar is *already* showing the chat list (`public/js/main.js`, guarded
 * on `#folders.chats`). So an agent reading a ticket has no way to learn that
 * somebody is waiting in a chat — which is the one thing about chat that is
 * different from email.
 *
 * One endpoint, behind the session and the agent's own mailbox permissions.
 * It returns a count and the newest chat's id, which is all a badge and a
 * notification need.
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
     * any agent reply does and appears in the transcript as what it is.
     */
    public function nudge(Request $request, $conversation_id)
    {
        $conversation = Conversation::findOrFail($conversation_id);
        $this->authorize('viewCached', $conversation);

        if (!$conversation->isChat()) {
            return response()->json(['status' => 'error', 'msg' => __('Not a chat conversation.')], 400);
        }

        $text = (string) config('gesoftlivechat.idle_prompt');

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
     * Active chats the signed-in agent is allowed to see.
     *
     * Scoped by mailbox permission, and by assignment where the user may only
     * see their own — the same two rules `Conversation::getChats()` applies,
     * because a count that includes conversations the agent cannot open is a
     * badge that never clears.
     */
    public function chats(Request $request)
    {
        $user = auth()->user();

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

        $latest = (clone $query)->orderBy('id', 'desc')->first();

        return response()->json([
            'status'      => 'success',
            'count'       => $query->count(),
            // The newest chat's id and the newest thread in it: the first tells
            // the browser a *conversation* is new, the second that somebody has
            // said something in one it already knew about.
            'latest_id'   => $latest->id ?? 0,
            'latest_at'   => $latest && $latest->last_reply_at ? $latest->last_reply_at->timestamp : 0,
            'latest_name' => $latest && $latest->customer ? $latest->customer->getFullName(true) : '',
        ]);
    }
}
