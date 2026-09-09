<?php

namespace Modules\GesoftLiveChat\Console;

use App\Conversation;
use Illuminate\Console\Command;

/**
 * Marks a chat idle when the customer has stopped answering, and closes it when
 * they never come back.
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
 */
class SweepChats extends Command
{
    protected $signature = 'gesoftlivechat:sweep-chats {--dry-run : Report what would change and change nothing}';

    protected $description = 'Mark unanswered chats idle and close the ones nobody comes back to';

    public function handle()
    {
        $idle_after  = (int) config('gesoftlivechat.idle_after');
        $close_after = (int) config('gesoftlivechat.close_after');
        $dry         = (bool) $this->option('dry-run');

        if ($idle_after <= 0 && $close_after <= 0) {
            return 0;
        }

        $marked = 0;
        $closed = 0;

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

        if ($marked || $closed) {
            \Log::info('GesoftLiveChat: swept chats', [
                'marked_idle' => $marked,
                'closed'      => $closed,
                'dry_run'     => $dry,
            ]);
        }

        $this->info(sprintf('%d marked idle, %d closed%s', $marked, $closed, $dry ? ' (dry run)' : ''));

        return 0;
    }
}
