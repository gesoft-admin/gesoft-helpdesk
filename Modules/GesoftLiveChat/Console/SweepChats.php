<?php

namespace Modules\GesoftLiveChat\Console;

use App\Conversation;
use Illuminate\Console\Command;
use Modules\GesoftLiveChat\Entities\ChatSession;
use Modules\GesoftLiveChat\Support\Presence;

/**
 * Marks a chat idle when the customer has stopped answering, closes it when
 * they never come back, and writes down visitors who went away.
 *
 * The rule that matters is **which clock this reads**. Idle time is measured
 * from the agent's last reply, never from the customer's message — so it only
 * ever runs while the customer owes us an answer. A conversation where the
 * customer spoke last is a conversation *we* have not answered, and an
 * inattentive operator must not be able to hang up on a waiting customer. That
 * case has no timer at all here, deliberately, and never will.
 *
 * Core already records both facts, so this asks no extra questions of the
 * database: `last_reply_from` says who spoke last, `last_reply_at` says when.
 *
 * Two stages rather than one, because closing a chat outright the first time a
 * customer pauses is rude and loses a conversation somebody may still be in the
 * middle of:
 *
 *   1. `idle_after` since the agent's last reply -> status becomes Pending.
 *      The chat stays in the list, visibly waiting, and an agent can ask "are
 *      you still there?" — which is what the operator button does.
 *   2. `close_after` more with still no reply -> closed, with a line item
 *      saying so, and the transcript stays in the mailbox like any other.
 *
 * Either number set to zero disables that stage.
 *
 * Separately, a visitor whose tab has gone quiet for `gone_after` gets one line
 * in the conversation saying they left. That line is history only and closes
 * nothing: a customer who walked away after asking a question has still not
 * been answered.
 */
class SweepChats extends Command
{
    protected $signature = 'gesoftlivechat:sweep-chats {--dry-run : Report what would change and change nothing}';

    protected $description = 'Mark unanswered chats idle, close the ones nobody comes back to, note visitors who left';

    public function handle()
    {
        $idle_after  = (int) config('gesoftlivechat.idle_after');
        $close_after = (int) config('gesoftlivechat.close_after');
        $dry         = (bool) $this->option('dry-run');

        $marked = 0;
        $closed = 0;

        if ($idle_after > 0 || $close_after > 0) {
            // Only chats where the *agent* spoke last. Everything else is a
            // customer waiting on us, and none of this applies to that.
            $waiting = Conversation::where('type', Conversation::TYPE_CHAT)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
                ->where('last_reply_from', '!=', Conversation::PERSON_CUSTOMER)
                ->whereNotNull('last_reply_at')
                ->get();

            foreach ($waiting as $conversation) {
                $silent_for = $conversation->last_reply_at->diffInSeconds(now());

                if ($conversation->status == Conversation::STATUS_PENDING) {
                    if ($close_after > 0 && $silent_for >= $idle_after + $close_after) {
                        $this->line(sprintf('  close  #%d  silent for %ds', $conversation->id, $silent_for));
                        if (!$dry) {
                            $conversation->changeStatus(Conversation::STATUS_CLOSED);
                        }
                        $closed++;
                    }
                    continue;
                }

                if ($idle_after > 0 && $silent_for >= $idle_after) {
                    $this->line(sprintf('  idle   #%d  silent for %ds', $conversation->id, $silent_for));
                    if (!$dry) {
                        $conversation->changeStatus(Conversation::STATUS_PENDING);
                    }
                    $marked++;
                }
            }
        }

        $left = $this->sweepPresence($dry);

        if ($marked || $closed || $left) {
            \Log::info('GesoftLiveChat: swept chats', [
                'marked_idle' => $marked,
                'closed'      => $closed,
                'left'        => $left,
                'dry_run'     => $dry,
            ]);
        }

        $this->info(sprintf('%d marked idle, %d closed, %d left%s', $marked, $closed, $left, $dry ? ' (dry run)' : ''));

        return 0;
    }

    /**
     * Visitors who went away, written into their conversation once.
     *
     * Gone means a goodbye nobody came back from, or silence, for longer than
     * `gone_after` — see `Support/Presence.php` for why a goodbye alone is not
     * enough. A session whose conversation has closed is over whatever the
     * visitor is doing, so it is marked ended here and not looked at again.
     *
     * The question is asked **per conversation, not per session**. One
     * conversation can have several live sessions: a chat embedded in an
     * application mints a new one each time the panel is opened on a page that
     * has just loaded, and the tabs left behind are quiet by definition. Asked
     * per session, every one of those would report the visitor as having left
     * while they sat there typing, and the operator would read a conversation
     * full of "the visitor left" about somebody who never did. So a visitor has
     * left only when every session on that conversation has gone quiet, and the
     * line is written once, against the newest.
     */
    protected function sweepPresence($dry)
    {
        $gone_after = (int) config('gesoftlivechat.gone_after');
        if ($gone_after <= 0) {
            return 0;
        }

        $cutoff = now()->subSeconds($gone_after);
        $left = 0;

        // Conversations worth looking at: one quiet session is enough to ask
        // the question, and the answer then needs all of that conversation's.
        $conversation_ids = ChatSession::whereNull('ended_at')
            ->where(function ($query) use ($cutoff) {
                $query->where('left_at', '<=', $cutoff)
                    ->orWhere('last_seen_at', '<=', $cutoff);
            })
            ->pluck('conversation_id')
            ->unique()
            ->values();

        if ($conversation_ids->isEmpty()) {
            return 0;
        }

        $groups = ChatSession::whereIn('conversation_id', $conversation_ids)
            ->whereNull('ended_at')
            ->orderBy('id')
            ->get()
            ->groupBy('conversation_id');

        foreach ($groups as $sessions) {
            $newest = $sessions->last();

            if (!$newest->isConversationOpen()) {
                if (!$dry) {
                    foreach ($sessions as $session) {
                        $session->ended_at = now();
                        $session->save();
                    }
                }
                continue;
            }

            // Any tab still reporting in means the visitor is here, whichever
            // tab it is.
            $here = $sessions->first(function ($session) use ($gone_after) {
                return $session->state($gone_after) === Presence::HERE;
            });

            if ($here || $newest->left_noted_at) {
                continue;
            }

            $this->line(sprintf('  left   #%d', $newest->conversation_id));

            if (!$dry) {
                $newest->note(Presence::ACTION_LEFT);
                $newest->left_noted_at = now();
                $newest->save();
            }
            $left++;
        }

        return $left;
    }
}
