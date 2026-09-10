<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per visitor tab holding one conversation.
 *
 * Replaces a token that addressed a customer. That token was found by email,
 * so anybody who typed another person's address into the form was given a
 * token for that person, and once their own chat closed it led into the other
 * person's open conversation. A session belongs to one conversation, and
 * nothing about the customer leads to it.
 */
class CreateGesoftLiveChatSessionsTable extends Migration
{
    public function up()
    {
        Schema::create('gesoft_live_chat_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('conversation_id')->index();
            // sha256 of the token. The token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('left_noted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // The old tokens are still sitting in `customer_channel` and
        // `customers.channel_id`, in the clear. Nothing reads them any more, but
        // a credential nobody uses is still a credential in a backup. Replace
        // them with an identifier that says only which customer it is.
        $channel = (int) config('gesoftlivechat.channel', 100);

        foreach (DB::table('customer_channel')->where('channel', $channel)->get() as $row) {
            DB::table('customer_channel')->where('id', $row->id)
                ->update(['channel_id' => 'customer-'.$row->customer_id]);
        }

        foreach (DB::table('customers')->where('channel', $channel)->get(['id']) as $row) {
            DB::table('customers')->where('id', $row->id)
                ->update(['channel_id' => 'customer-'.$row->id]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_sessions');
    }
}
