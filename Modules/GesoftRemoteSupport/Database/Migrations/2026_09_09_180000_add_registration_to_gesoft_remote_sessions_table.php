<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the peer whose ID we were shown actually registered with our own
 * rendezvous server.
 *
 * The panel could already say "ID available", which is all `/api/ready` ever
 * supported: a client that found somebody else's server reports an ID too, and
 * the agent had no way to tell the two apart. helpdesk-rust now answers that
 * question by comparing hbbs's stored key for the ID against the key it held
 * before the session was approved, and returns the verdict on the operator
 * listing. This column is where the poll keeps it.
 *
 * Stored verbatim, like `remote_status`: REGISTERED / NOT_REGISTERED /
 * UNPROVEN / UNAVAILABLE. The module makes no judgement of its own about which
 * of those is good news -- that reading belongs to the service that measured
 * it, and re-deriving it here is how the two would drift apart.
 */
class AddRegistrationToGesoftRemoteSessionsTable extends Migration
{
    public function up()
    {
        Schema::table('gesoft_remote_sessions', function (Blueprint $table) {
            $table->string('registration', 20)->nullable()->after('remote_status');
        });
    }

    public function down()
    {
        Schema::table('gesoft_remote_sessions', function (Blueprint $table) {
            $table->dropColumn('registration');
        });
    }
}
