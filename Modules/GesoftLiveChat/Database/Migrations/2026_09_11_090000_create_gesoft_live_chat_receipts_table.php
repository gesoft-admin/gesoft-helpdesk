<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery receipts: how far each side of a chat has received and seen the
 * other side's messages.
 *
 * One row per conversation, holding pointers rather than a status per
 * message. A chat is a line, so "seen up to message N" says everything a
 * status on each of N messages would, costs one row instead of N, and cannot
 * leave an older message stuck behind a newer one. Live Helper Chat keeps a
 * status per message (`lh_msg.del_st`) and can leave one at "delivered" for
 * good; a pointer only ever moves forward.
 *
 * Core's `threads` table is not touched.
 *
 *   agent_delivered_id    the visitor's messages up to this thread reached an
 *                         agent's FreeScout
 *   agent_seen_id/_at     ...and were on an agent's screen, and when
 *   visitor_delivered_id  the agents' messages up to this thread reached the
 *                         visitor's bubble
 *   visitor_seen_id/_at   ...and were on the visitor's screen, and when
 */
class CreateGesoftLiveChatReceiptsTable extends Migration
{
    public function up()
    {
        Schema::create('gesoft_live_chat_receipts', function (Blueprint $table) {
            $table->unsignedInteger('conversation_id')->primary();
            $table->unsignedInteger('agent_delivered_id')->default(0);
            $table->unsignedInteger('agent_seen_id')->default(0);
            $table->timestamp('agent_seen_at')->nullable();
            $table->unsignedInteger('visitor_delivered_id')->default(0);
            $table->unsignedInteger('visitor_seen_id')->default(0);
            $table->timestamp('visitor_seen_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_receipts');
    }
}
