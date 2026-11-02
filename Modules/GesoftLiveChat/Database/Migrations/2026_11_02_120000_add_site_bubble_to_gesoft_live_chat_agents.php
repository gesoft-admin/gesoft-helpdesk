<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until when an agent has offered the bubble on the public site. Empty means
 * they have not; see `AgentPresence::siteBubbleOpen()`.
 */
class AddSiteBubbleToGesoftLiveChatAgents extends Migration
{
    public function up()
    {
        Schema::table('gesoft_live_chat_agents', function (Blueprint $table) {
            $table->timestamp('site_bubble_until')->nullable();
        });
    }

    public function down()
    {
        Schema::table('gesoft_live_chat_agents', function (Blueprint $table) {
            $table->dropColumn('site_bubble_until');
        });
    }
}
