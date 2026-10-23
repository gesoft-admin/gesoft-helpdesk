<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applications connected from inside themselves, rather than written into
 * `.env` by hand.
 *
 * `gesoft_live_chat_connected_apps` holds what an administrator approved: the
 * application's name, the origin its pages are served from — as the browser
 * reported it, not as the application claimed it — and the Ed25519 public key
 * it signs with. Only the public half: nothing in this table can sign in an
 * application's name. Revoking keeps the row, so an application connected
 * again under the same provider keeps its people's conversations.
 *
 * `gesoft_live_chat_assertion_ids` is the list of spent assertion ids. An
 * assertion passes through a browser, so each one is good once; the rows only
 * have to outlive the assertions themselves, a few minutes, and are cleared on
 * the way past.
 */
class CreateGesoftLiveChatConnectedAppsTable extends Migration
{
    public function up()
    {
        Schema::create('gesoft_live_chat_connected_apps', function (Blueprint $table) {
            $table->increments('id');
            $table->string('provider', 40)->unique();
            $table->string('name', 60);
            $table->string('origin', 255);
            // base64url, no padding: 43 characters for 32 bytes.
            $table->string('public_key', 64);
            $table->unsignedInteger('connected_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gesoft_live_chat_assertion_ids', function (Blueprint $table) {
            $table->increments('id');
            $table->string('provider', 40);
            $table->string('jti', 64);
            $table->timestamp('expires_at');

            $table->unique(['provider', 'jti'], 'glc_assertion_id');
            $table->index('expires_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_assertion_ids');
        Schema::dropIfExists('gesoft_live_chat_connected_apps');
    }
}
