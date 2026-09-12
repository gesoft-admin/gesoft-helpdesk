<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per diagnostic report an application has sent us.
 *
 * It exists for two questions and no others: *has this incident already been
 * reported* -- which is why `(provider, incident_id)` is unique rather than
 * merely indexed -- and *where did it go*, so an operator asking about an
 * incident can be pointed at the conversation, the note and the file.
 *
 * It holds no diagnostic content. The report itself is a file on the private
 * disk; this table holds only the references, so reading every row of it tells
 * you who reported what and when, and nothing about any of their data.
 */
class CreateGesoftLiveChatErrorReportsTable extends Migration
{
    public function up()
    {
        Schema::create('gesoft_live_chat_error_reports', function (Blueprint $table) {
            $table->increments('id');

            // The application, as the register spells it, and that
            // application's own name for the incident. Unique together: an
            // application retrying a call it never saw the answer to must get
            // the first report back, not make a second one.
            $table->string('provider', 64);
            $table->string('incident_id', 64);

            // Who reported it, in the only terms this module accepts as
            // identity. `identity_id` and not `customer_id`, for the reason
            // 2C's mapping table gives: two application accounts can share one
            // FreeScout customer, and a customer is therefore not a person.
            $table->unsignedInteger('identity_id');

            // Where it landed: the conversation the customer can see, the
            // internal note only an agent can, and the file that note links to.
            //
            // `conversation_id` is nullable for the length of one transaction.
            // The row is inserted *before* the conversation exists, so that the
            // unique key -- and not an ordering of reads in PHP -- is what
            // stops a double-click from producing two conversations: the second
            // insert waits on the index, and finds the finished row when the
            // first commits.
            $table->unsignedInteger('conversation_id')->nullable();
            $table->unsignedInteger('thread_id')->nullable();
            $table->string('file', 191)->nullable();
            $table->unsignedInteger('size')->default(0);

            $table->timestamps();

            $table->unique(['provider', 'incident_id'], 'glc_error_reports_incident_unique');
            $table->index('identity_id', 'glc_error_reports_identity');
            $table->index('conversation_id', 'glc_error_reports_conversation');
        });
    }

    public function down()
    {
        Schema::dropIfExists('gesoft_live_chat_error_reports');
    }
}
