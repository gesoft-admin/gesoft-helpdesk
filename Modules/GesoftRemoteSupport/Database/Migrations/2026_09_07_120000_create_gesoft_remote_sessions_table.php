<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module keeps its own table rather than adding columns to `conversations`:
 * core stays untouched, and removing the module is a single DROP.
 */
class CreateGesoftRemoteSessionsTable extends Migration
{
    public function up()
    {
        Schema::create('gesoft_remote_sessions', function (Blueprint $table) {
            $table->increments('id');
            // One live mapping per conversation, which is what the sidebar shows.
            $table->integer('conversation_id')->unsigned()->unique();
            $table->string('status', 20)->default('not_started');
            // The RustDesk ID, once helpdesk-rust reports one. Mocked for now.
            $table->string('remote_id', 64)->nullable();
            $table->integer('started_by_user_id')->unsigned()->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_remote_sessions');
    }
}
