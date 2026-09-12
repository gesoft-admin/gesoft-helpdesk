<?php

namespace Modules\GesoftLiveChat\Console;

use App\Conversation;
use Illuminate\Console\Command;
use Modules\GesoftLiveChat\Entities\AppConversation;
use Modules\GesoftLiveChat\Entities\AppIdentity;

/**
 * Whether the link between application users, customers and conversations still
 * holds. Read-only, and deliberately so.
 *
 * This is an authorisation graph: a row here is the only reason one person may
 * read one conversation. Something that repairs such a graph on its own is
 * something that can *grant* access on its own, quietly, at three in the
 * morning, on evidence nobody looked at. So this reports and stops.
 *
 * The one thing it does not report as a fault is the one everybody expects it
 * to: two identities sharing a FreeScout customer. That is not a fault, it is
 * the design. Core deduplicates customers by email address, so two application
 * accounts that share an address share a customer record — and they still
 * cannot see each other's conversations, because the boundary is the mapping
 * and not the customer. See `Entities/AppConversation.php`.
 */
class CheckIdentities extends Command
{
    protected $signature = 'gesoftlivechat:check-identities {--provider= : Only this application}';

    protected $description = 'Check application identities against their customers and conversations (read-only)';

    public function handle()
    {
        $identities = AppIdentity::query()
            ->when($this->option('provider'), function ($query, $provider) {
                return $query->where('provider', $provider);
            })
            ->orderBy('id')
            ->get();

        if ($identities->isEmpty()) {
            $this->info('No application identities.');

            return 0;
        }

        $links = AppConversation::whereIn('identity_id', $identities->pluck('id')->all())->get();
        $conversation_ids = $links->pluck('conversation_id')
            ->merge($identities->pluck('conversation_id')->filter())
            ->unique()
            ->values();

        $conversations = $conversation_ids->isEmpty()
            ? collect()
            : Conversation::whereIn('id', $conversation_ids->all())->get()->keyBy('id');

        $findings = [];
        $note = function ($kind, $line) use (&$findings) {
            $findings[$kind][] = $line;
        };

        // An identity whose customer has gone. Nothing can be shown to them and
        // their next bootstrap will refuse, so this is a real outage for one
        // person rather than an untidy row.
        foreach ($identities as $identity) {
            if (!$identity->customer) {
                $note('orphan identity', sprintf(
                    '  %s/%s  identity %d names customer %d, which does not exist',
                    $identity->provider, $identity->external_id, $identity->id, $identity->customer_id
                ));
            }
        }

        // A mapping to a conversation that is not there any more. Harmless to
        // read — it is filtered out — but it is also how a hard-deleted
        // conversation looks, which is worth knowing.
        foreach ($links as $link) {
            if (!$conversations->has($link->conversation_id)) {
                $note('orphan mapping', sprintf(
                    '  identity %d -> conversation %d, which does not exist',
                    $link->identity_id, $link->conversation_id
                ));
            }
        }

        // One conversation claimed by two identities. Not fatal on its own --
        // reading is refused anyway unless the customer still matches -- but it
        // is the shape a leak would have, so it is never left unsaid.
        $by_conversation = $links->groupBy('conversation_id');
        foreach ($by_conversation as $conversation_id => $group) {
            if ($group->count() < 2) {
                continue;
            }

            $owners = $group->pluck('identity_id')->implode(', ');
            $note('conversation claimed twice', sprintf(
                '  conversation %d is mapped to identities %s',
                $conversation_id, $owners
            ));
        }

        // The chat somebody is in, with no row saying it is theirs. It would
        // work as a chat and be invisible in the history — which is exactly the
        // inconsistency the mapping table was added to make impossible.
        $mapped = $links->groupBy('identity_id');
        foreach ($identities as $identity) {
            if (!$identity->conversation_id) {
                continue;
            }

            $theirs = $mapped->get($identity->id, collect())->pluck('conversation_id');

            if (!$theirs->contains($identity->conversation_id)) {
                $note('current conversation not in history', sprintf(
                    '  %s/%s  is in conversation %d, which is not mapped to them',
                    $identity->provider, $identity->external_id, $identity->conversation_id
                ));
            }
        }

        // A conversation that has moved to another customer. Reading it is
        // already refused -- `AppConversation::owned` checks the customer every
        // time -- so this says "somebody lost their history", not "somebody
        // gained one".
        foreach ($links as $link) {
            $conversation = $conversations->get($link->conversation_id);
            $identity = $identities->firstWhere('id', $link->identity_id);

            if (!$conversation || !$identity) {
                continue;
            }

            if ($conversation->customer_id != $identity->customer_id) {
                $note('customer moved', sprintf(
                    '  %s/%s  maps conversation %d, which now belongs to customer %d, not %d (refused, not shown)',
                    $identity->provider, $identity->external_id, $conversation->id,
                    $conversation->customer_id, $identity->customer_id
                ));
            }
        }

        // Conversations of the same customer that no identity claims. Usually
        // ordinary chats from the public bubble, and sometimes a chat an
        // identity opened before the mapping table existed. Either way it is
        // reported rather than claimed: an incomplete history is a
        // disappointment, an over-complete one is a leak.
        $customer_ids = $identities->pluck('customer_id')->filter()->unique();
        $unclaimed = $customer_ids->isEmpty() ? collect() : Conversation::whereIn('customer_id', $customer_ids->all())
            ->whereNotIn('id', $links->pluck('conversation_id')->all() ?: [0])
            ->get();

        foreach ($unclaimed as $conversation) {
            $note('unclaimed', sprintf(
                '  conversation %d (customer %d) is not in any identity\'s history',
                $conversation->id, $conversation->customer_id
            ));
        }

        // ------------------------------------------------------------- report

        $this->info(sprintf(
            '%d identities, %d mapped conversations, %d customers.',
            $identities->count(), $links->count(), $customer_ids->count()
        ));

        $shared = $identities->groupBy('customer_id')->filter(function ($group) {
            return $group->count() > 1;
        });

        if ($shared->isNotEmpty()) {
            $this->line(sprintf(
                '%d customer(s) are shared by more than one identity. This is by design, not a fault:',
                $shared->count()
            ));
            foreach ($shared as $customer_id => $group) {
                $this->line(sprintf('  customer %d <- %s', $customer_id, $group->map(function ($identity) {
                    return $identity->provider.'/'.$identity->external_id;
                })->implode(', ')));
            }
        }

        if (!$findings) {
            $this->info('Nothing to report.');

            return 0;
        }

        foreach ($findings as $kind => $lines) {
            $this->line('');
            $this->warn(sprintf('%s (%d)', $kind, count($lines)));
            foreach ($lines as $line) {
                $this->line($line);
            }
        }

        $this->line('');
        $this->warn('Nothing was changed. These are identities and they are repaired by hand.');

        // Findings are not a failure: "unclaimed" is the normal state of every
        // conversation the public bubble ever opened. A non-zero exit here
        // would make this unusable from anything that checks exit codes.
        return 0;
    }
}
