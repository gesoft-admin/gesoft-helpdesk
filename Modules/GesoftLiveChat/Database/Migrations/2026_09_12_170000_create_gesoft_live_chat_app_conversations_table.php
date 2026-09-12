<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which conversations belong to which application identity, and how far that
 * person has read each one.
 *
 * Until now an identity remembered one conversation — the one it is in. That is
 * enough for a chat and not enough for a history, and the difference is not
 * only a column: a history is an **authorisation** question, and the answer has
 * to be a link this integration wrote, never something inferred afterwards.
 *
 * Inferring it is the tempting shortcut and it is a data leak. Two application
 * accounts that share an email address share one FreeScout customer, by design
 * — core deduplicates by address, and that is worth keeping, because it joins a
 * chat to the tickets the same address has already raised. So
 * `WHERE customer_id = ?` would show each of them the other's conversations,
 * and `WHERE customer_email = ?` would show them to anybody who can arrange to
 * have that address. Neither is ever an authorisation boundary here; the pair
 * `(provider, external_id)` is, and this table is how it reaches a conversation.
 *
 * `last_seen_thread_id` is per identity and not per conversation, for the same
 * reason. It is also deliberately *not* `gesoft_live_chat_receipts`: that
 * pointer means "the visitor had this on screen" and an agent is shown it, so
 * moving it because somebody glanced at a list of subjects would tell an agent
 * a lie. This one means only "this person's unread count stops here".
 */
class CreateGesoftLiveChatAppConversationsTable extends Migration
{
    public function up()
    {
        Schema::create('gesoft_live_chat_app_conversations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('identity_id');
            $table->unsignedInteger('conversation_id');
            // The newest agent message this person has actually been shown.
            // Forward-only, like every other pointer in this module.
            $table->unsignedInteger('last_seen_thread_id')->default(0);
            $table->timestamps();

            // One row per identity per conversation, enforced by the database
            // rather than by whoever writes the next caller.
            $table->unique(['identity_id', 'conversation_id'], 'glc_app_conv');
            // The reverse lookup: the poll asks "whose is this conversation?",
            // and the consistency check asks whether two identities claim one.
            $table->index('conversation_id');
        });

        // Everything the integration already knows for certain. An identity's
        // `conversation_id` was written by `startAsApp` when that identity
        // opened that chat, so it is evidence rather than inference — which is
        // exactly the test for what may be claimed here.
        //
        // Conversations that only a shared email address or a shared customer
        // would connect to an identity are deliberately **not** claimed. An
        // incomplete history is a disappointment; an over-complete one is
        // somebody reading a conversation that is not theirs.
        // `Console/CheckIdentities.php` reports what was left unclaimed.
        $now = now();

        \DB::table('gesoft_live_chat_app_identities')
            ->whereNotNull('conversation_id')
            ->orderBy('id')
            ->chunk(200, function ($identities) use ($now) {
                // Read first, then insert what is missing. Laravel 5.5 has no
                // `insertOrIgnore`, and this has to be idempotent: the code
                // that writes these rows ships in the same release, so the
                // migration may well run after some of them already exist.
                $existing = \DB::table('gesoft_live_chat_app_conversations')
                    ->whereIn('identity_id', collect($identities)->pluck('id')->all())
                    ->get()
                    ->map(function ($row) {
                        return $row->identity_id.':'.$row->conversation_id;
                    })
                    ->flip();

                $rows = [];
                foreach ($identities as $identity) {
                    if ($existing->has($identity->id.':'.$identity->conversation_id)) {
                        continue;
                    }

                    $rows[] = [
                        'identity_id'         => $identity->id,
                        'conversation_id'     => $identity->conversation_id,
                        'last_seen_thread_id' => 0,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }

                if ($rows) {
                    \DB::table('gesoft_live_chat_app_conversations')->insert($rows);
                }
            });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_app_conversations');
    }
}
