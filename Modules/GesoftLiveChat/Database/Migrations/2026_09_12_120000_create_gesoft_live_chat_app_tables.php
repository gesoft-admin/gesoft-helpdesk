<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat from an application where the customer is already signed in.
 *
 * Two tables, and the split between them is the point: one says *who* somebody
 * is and lasts, the other says *that a browser may act as them right now* and
 * expires.
 *
 * `gesoft_live_chat_app_identities` maps an application's own user to a
 * FreeScout customer. The key is `(provider, external_id)` and never the email
 * address: an address can be changed, shared by a department, or missing
 * altogether, and a chat that resumed on a typed address is exactly the defect
 * the session token was built to close (see the sessions table's migration).
 * The mapping is written once, by a server that authenticated itself, and read
 * afterwards.
 *
 * `gesoft_live_chat_app_sessions` is a handle the application's server asks for
 * on behalf of the person at the screen, and hands to their browser. It is a
 * bearer credential, so only its hash is kept, it expires, and it can do
 * exactly two things: resume that identity's open chat, and open a new one as
 * that identity. It cannot read anything, cannot address a conversation of its
 * own choosing, and is useless to anybody who is not already able to make the
 * application answer for that user.
 */
class CreateGesoftLiveChatAppTables extends Migration
{
    public function up()
    {
        Schema::create('gesoft_live_chat_app_identities', function (Blueprint $table) {
            $table->increments('id');
            // Which application. Registered in the module's configuration; a
            // provider nothing registers is refused before this is read.
            $table->string('provider', 40);
            // That application's own id for the person. Opaque here on
            // purpose: this side never parses it and never looks anybody up
            // by anything else.
            $table->string('external_id', 191);
            $table->unsignedInteger('customer_id');
            // The chat this person is in now, or null. Conversation ownership
            // is filed against the identity and never against the customer:
            // two application accounts that share an email address share one
            // FreeScout customer -- core deduplicates by address, and that is
            // worth keeping, because it joins a chat to the tickets the same
            // address has already raised -- but they must never be able to see
            // each other's conversation.
            $table->unsignedInteger('conversation_id')->nullable();
            $table->timestamps();

            // One customer per application user, enforced by the database
            // rather than by whoever writes the next caller.
            $table->unique(['provider', 'external_id'], 'glc_app_identity');
            $table->index('customer_id');
        });

        Schema::create('gesoft_live_chat_app_sessions', function (Blueprint $table) {
            $table->increments('id');
            // sha256 of the token. A copy of this table -- a backup, a support
            // dump -- hands nobody the ability to open a chat as a customer.
            $table->char('token_hash', 64)->unique();
            $table->string('provider', 40);
            $table->string('external_id', 191);
            $table->unsignedInteger('customer_id');
            // The address the application's server reported for the person at
            // the screen, for the same reason a visitor's is kept: it is what
            // an agent can block.
            $table->string('ip', 45)->nullable();
            // Which screen of the application the person was on when the panel
            // opened. The one piece of context this phase carries, and it is
            // here because "where were they when they asked" is the first thing
            // an operator wants to know and the cheapest thing to record.
            $table->string('route', 191)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['provider', 'external_id']);
            $table->index('expires_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_app_sessions');
        Schema::dropIfExists('gesoft_live_chat_app_identities');
    }
}
