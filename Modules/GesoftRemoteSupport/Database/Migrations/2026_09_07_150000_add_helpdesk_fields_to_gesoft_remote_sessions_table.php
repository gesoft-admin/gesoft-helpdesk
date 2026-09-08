<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the module has to remember once the sessions are real ones.
 *
 * A separate migration rather than an edit to the first: the skeleton's table
 * is already created on the test install, and rewriting a migration that has
 * run is a change nobody's database receives.
 *
 * Only what this module cannot re-derive is stored. The customer's URL is not
 * a column — it is `api_base` plus the code, and freezing a copy of it would
 * mean handing out a stale link the day that base changes. The customer's IP,
 * the audit trail and the firewall state stay in helpdesk-rust, which owns
 * them.
 */
class AddHelpdeskFieldsToGesoftRemoteSessionsTable extends Migration
{
    public function up()
    {
        Schema::table('gesoft_remote_sessions', function (Blueprint $table) {
            // The backend's own session id. This is the handle that
            // `/api/ops/{id}/approve` and `/api/ops/{id}/close` take, and the
            // only way this module can end a session it started.
            $table->unsignedInteger('helpdesk_session_id')->nullable()->after('status');

            // The support code. It is the customer's link (`/d/{code}`) and it
            // appears nowhere else afterwards: the operator listing does not
            // return it, so if it is lost here it is lost.
            $table->string('code', 32)->nullable()->after('helpdesk_session_id');

            // The backend's status verbatim — REQUESTED / APPROVED / READY /
            // CLOSED / EXPIRED. Kept separate from the module's own `status`
            // so the panel can report what helpdesk-rust actually says instead
            // of a local guess at it.
            $table->string('remote_status', 20)->nullable()->after('code');

            $table->timestamp('expires_at')->nullable()->after('started_at');

            // When the backend last confirmed the above, so a panel showing
            // stale values can say so rather than implying they are live.
            $table->timestamp('synced_at')->nullable()->after('expires_at');

            $table->index('helpdesk_session_id', 'gesoft_rs_helpdesk_session_id');
        });
    }

    public function down()
    {
        Schema::table('gesoft_remote_sessions', function (Blueprint $table) {
            $table->dropIndex('gesoft_rs_helpdesk_session_id');
            $table->dropColumn([
                'helpdesk_session_id', 'code', 'remote_status', 'expires_at', 'synced_at',
            ]);
        });
    }
}
