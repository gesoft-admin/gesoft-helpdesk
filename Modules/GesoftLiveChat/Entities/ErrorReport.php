<?php

namespace Modules\GesoftLiveChat\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Modules\GesoftLiveChat\Support\Diagnostic;

/**
 * A diagnostic report an application sent, and where it ended up.
 *
 * The row is the record; the report itself is a file beside it. They are kept
 * apart on purpose. The row is safe to read, to list, to count and to include
 * in an export -- it names an identity, a conversation, a note and a path, and
 * says nothing about what went wrong. The file is the part with the detail in
 * it, and it lives where nothing serves it by accident.
 *
 * "Where nothing serves it by accident" is literal. FreeScout's own attachments
 * are on the `private` disk but are handed out by `OpenController@downloadAttachment`
 * -- an unauthenticated route that checks an HMAC in the query string and
 * nothing else. Anybody holding the link holds the file, whether or not they
 * may open the conversation, whether or not they are an agent at all. That is a
 * reasonable trade for a customer's screenshot travelling by email. It is the
 * wrong one for a file assembled out of a running application's internals, so
 * these do not go through it: they are written under `gesoftlivechat/`, which
 * no route serves, and reached only through this module's own download action,
 * which asks the same question the conversation view asks.
 */
class ErrorReport extends Model
{
    protected $table = 'gesoft_live_chat_error_reports';

    protected $fillable = [
        'provider', 'incident_id', 'identity_id', 'conversation_id', 'thread_id', 'file', 'size',
    ];

    /** Where reports live on the private disk. Served by no route. */
    const DIRECTORY = 'gesoftlivechat/diagnostics';

    /** The disk FreeScout keeps out of the webroot. */
    const DISK = 'private';

    public function conversation()
    {
        return $this->belongsTo('App\Conversation');
    }

    public function identity()
    {
        return $this->belongsTo('Modules\GesoftLiveChat\Entities\AppIdentity', 'identity_id');
    }

    /**
     * The report this application already made for this incident, if it did.
     *
     * The question an idempotent endpoint asks first and, after the unique key
     * has spoken, again: a caller that retried a request whose answer it never
     * saw must be told what happened the first time, not given a second
     * conversation to explain to somebody.
     */
    public static function existing($provider, $incident_id)
    {
        return self::where('provider', $provider)->where('incident_id', $incident_id)->first();
    }

    /**
     * Claim this incident, or find out who claimed it first.
     *
     * Returns `[$report, $created]`. The claim is the unique index, not a
     * preceding read: two calls that arrive together both find nothing and both
     * insert, and exactly one of them is told no by the database. Deciding it
     * in PHP would decide it in whichever order the two processes happened to
     * get there.
     */
    public static function claim($provider, $incident_id, AppIdentity $identity, $conversation_id)
    {
        try {
            $report = self::create([
                'provider'        => $provider,
                'incident_id'     => $incident_id,
                'identity_id'     => $identity->id,
                'conversation_id' => $conversation_id,
            ]);

            return [$report, true];
        } catch (QueryException $e) {
            $report = self::existing($provider, $incident_id);

            if ($report) {
                return [$report, false];
            }

            throw $e;
        }
    }

    /**
     * Write the report to the private disk and remember where.
     */
    public function store(array $report)
    {
        $bytes = Diagnostic::render($report);
        $path = $this->provider.'/'.Diagnostic::filename($this->incident_id);

        Storage::disk(self::DISK)->put(self::DIRECTORY.'/'.$path, $bytes);

        $this->file = $path;
        $this->size = strlen($bytes);
        $this->save();

        return $this->size;
    }

    /**
     * Remove a file written for an incident that did not survive.
     *
     * The file is written inside the transaction that claims the incident, so
     * a rollback takes the row and leaves the bytes. Nothing would ever look at
     * them again -- there is no row pointing at them and the sweep works from
     * rows -- so they would sit on the disk for good. This is the one case that
     * needs the path without the row, which is why it can be worked out from
     * the two things the caller still has.
     */
    public static function discard($provider, $incident_id)
    {
        $path = self::DIRECTORY.'/'.$provider.'/'.Diagnostic::filename($incident_id);

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Where the file is on the disk, in the disk's own terms. */
    public function storagePath()
    {
        return $this->file ? self::DIRECTORY.'/'.$this->file : null;
    }

    public function exists()
    {
        return $this->file && Storage::disk(self::DISK)->exists($this->storagePath());
    }

    /** The name an agent's browser will save it under. */
    public function filename()
    {
        return Diagnostic::filename($this->incident_id);
    }

    /**
     * Remove the file, leaving the row.
     *
     * Used by the sweep when the conversation a report belongs to is gone. The
     * row stays because it is the answer to "was this incident ever reported",
     * which outlives the detail and costs nothing to keep.
     */
    public function forgetFile()
    {
        if ($this->file && Storage::disk(self::DISK)->exists($this->storagePath())) {
            Storage::disk(self::DISK)->delete($this->storagePath());
        }

        $this->file = null;
        $this->size = 0;
        $this->save();
    }
}
