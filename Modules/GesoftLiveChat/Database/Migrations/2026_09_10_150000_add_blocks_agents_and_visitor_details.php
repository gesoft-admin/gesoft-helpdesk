<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the "before a public site" work needs to remember, modelled on Live
 * Helper Chat.
 *
 *  - Sessions keep the visitor's address and language. The address is what a
 *    block can be placed on; the language is what automatic messages to that
 *    visitor are written in.
 *  - Blocks: an address or an email that may not start a chat, until a date or
 *    for good. Live Helper Chat's bans work the same way, set by an agent from
 *    the conversation.
 *  - Agents: when each agent last had the interface open, which is how the
 *    bubble knows whether anybody is there to answer. It lives in the database
 *    rather than the cache so that clearing the cache does not make everybody
 *    look away at once.
 */
class AddBlocksAgentsAndVisitorDetails extends Migration
{
    public function up()
    {
        Schema::table('gesoft_live_chat_sessions', function (Blueprint $table) {
            $table->string('ip', 45)->nullable();
            $table->string('lang', 5)->nullable();
        });

        Schema::create('gesoft_live_chat_blocks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 10);
            $table->string('value', 191);
            $table->unsignedInteger('conversation_id')->nullable();
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->string('reason', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'value']);
        });

        Schema::create('gesoft_live_chat_agents', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_agents');
        Schema::dropIfExists('gesoft_live_chat_blocks');

        Schema::table('gesoft_live_chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['ip', 'lang']);
        });
    }
}
