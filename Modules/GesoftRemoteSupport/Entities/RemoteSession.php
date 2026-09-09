<?php

namespace Modules\GesoftRemoteSupport\Entities;

use Illuminate\Database\Eloquent\Model;

/**
 * The remote-support state of one conversation.
 *
 * Two statuses live side by side and mean different things. `status` is this
 * module's own lifecycle — has an agent started something here, is a start in
 * flight, is it finished — and it is what the buttons are wired to.
 * `remote_status` is helpdesk-rust's answer, stored verbatim, and it is what
 * the panel reports.
 *
 * The distinction matters because the backend's states are not the panel's.
 * READY means the customer's client has reported an ID; it does not mean the
 * client is registered with the rendezvous server, still less that anyone is
 * connected. Nothing here renders "Connected" or "Online", because no endpoint
 * this module calls can support the claim.
 */
class RemoteSession extends Model
{
    /** No session has been started for this conversation. */
    const STATUS_NOT_STARTED = 'not_started';
    /** A start is in flight: claimed locally, backend call not finished. */
    const STATUS_STARTING = 'starting';
    /** A session exists in helpdesk-rust and has not been closed. */
    const STATUS_ACTIVE = 'active';
    /** Ended — by this panel, by the operator console, or by expiry. */
    const STATUS_CLOSED = 'closed';

    // helpdesk-rust's own states, as they arrive over the wire.
    const REMOTE_REQUESTED = 'REQUESTED';
    const REMOTE_APPROVED  = 'APPROVED';
    const REMOTE_READY     = 'READY';
    const REMOTE_CLOSED    = 'CLOSED';
    const REMOTE_EXPIRED   = 'EXPIRED';

    /**
     * What the backend says about the peer whose ID we were shown, verbatim.
     *
     * "ID available" is all `/api/ready` ever supported: a client that found
     * somebody else's rendezvous server reports an ID too. helpdesk-rust now
     * compares hbbs's key for that ID against the one it held before the
     * session was approved and returns a verdict, which the panel shows
     * beside the ID instead of leaving the agent to assume.
     */
    const REG_REGISTERED     = 'REGISTERED';
    const REG_NOT_REGISTERED = 'NOT_REGISTERED';
    const REG_UNPROVEN       = 'UNPROVEN';
    const REG_UNAVAILABLE    = 'UNAVAILABLE';

    protected $table = 'gesoft_remote_sessions';

    protected $fillable = [
        'conversation_id', 'status', 'helpdesk_session_id', 'code', 'remote_status',
        'remote_id', 'registration', 'started_by_user_id', 'started_at', 'expires_at', 'synced_at',
        'closed_at',
    ];

    protected $dates = ['started_at', 'expires_at', 'synced_at', 'closed_at'];

    /**
     * The stored state, or an unsaved "not started" one so the view never has
     * to test for null.
     */
    public static function forConversation($conversation_id)
    {
        return self::where('conversation_id', $conversation_id)->first()
            ?: new self([
                'conversation_id' => $conversation_id,
                'status'          => self::STATUS_NOT_STARTED,
            ]);
    }

    public function isActive()
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isStarting()
    {
        return $this->status === self::STATUS_STARTING;
    }

    /**
     * Whether a second Start may proceed. Anything already running belongs to
     * somebody, except a `starting` row old enough that the request which
     * claimed it cannot still be alive.
     */
    public function isClaimable()
    {
        if ($this->isActive()) {
            return false;
        }

        if (!$this->isStarting()) {
            return true;
        }

        $window = (int) config('gesoftremotesupport.start_lock_seconds', 60);

        return !$this->started_at || $this->started_at->lt(now()->subSeconds($window));
    }

    /**
     * The name written into the backend's `hostname` field, which is what the
     * operator console shows for the session. It used to double as a join key,
     * because create answered without an id and the id had to be matched out of
     * the operator listing; create returns the id now, so this is a label and
     * nothing more. It still names the conversation, so an operator looking at
     * the console can tell which ticket a session belongs to.
     */
    public static function hostnameMarker($conversation)
    {
        return 'FreeScout #'.$conversation->number.' (conv '.$conversation->id.')';
    }

    /**
     * The link the agent sends the customer, or null when there is nothing
     * worth sending.
     *
     * Gated on the session still being active, not merely on a code existing.
     * `markClosed()` deliberately keeps the code on the row -- it is the record
     * of what this conversation was given access to -- but a closed or expired
     * session's `/d/{code}` serves nothing, so rendering it leaves a dead link
     * on screen that still looks live, still copies, and sits next to a
     * "Closed" label that nobody reads because the link is louder.
     *
     * It also made the recovery look wrong: with a stale link showing, pressing
     * Start again reads as a mistake rather than as the way to get a new one.
     * Reported from a real session on 2026-09-09.
     *
     * The code itself stays visible in its own row. That is the record, and it
     * is unambiguous next to the status; the link is the thing that invites a
     * click.
     */
    public function customerUrl()
    {
        if (!$this->code || !$this->isActive()) {
            return null;
        }

        $base = (string) config('gesoftremotesupport.customer_base');
        if ($base === '') {
            $base = (string) config('gesoftremotesupport.api_base');
        }
        if ($base === '') {
            return null;
        }

        return rtrim($base, '/').'/d/'.$this->code;
    }

    /**
     * What the panel says about the session.
     *
     * Every wording here is one the API can actually support. "Waiting for
     * customer" is an approved session whose client has not reported; "ID
     * available" is READY, which is exactly that and no more — the ID exists,
     * whether the peer is reachable is a question this module cannot answer.
     */
    /**
     * How the registration verdict reads in the panel, or an empty string when
     * there is nothing to say yet.
     *
     * Four answers, and only one of them is proof. `UNPROVEN` is deliberately
     * not dressed up as a failure: a client whose config survived a failed
     * teardown reuses its key and looks exactly like one that never arrived,
     * so the honest label is that nothing could be shown either way.
     */
    public function registrationLabel()
    {
        switch ($this->registration) {
            case self::REG_REGISTERED:     return __('Registered with our server');
            case self::REG_NOT_REGISTERED: return __('Not on our server');
            case self::REG_UNPROVEN:       return __('Could not be confirmed');
            case self::REG_UNAVAILABLE:    return __('Check unavailable');
            default:                       return '';
        }
    }

    /** Bootstrap label class for the verdict. Only proof is green. */
    public function registrationClass()
    {
        switch ($this->registration) {
            case self::REG_REGISTERED:     return 'label-success';
            case self::REG_NOT_REGISTERED: return 'label-danger';
            default:                       return 'label-default';
        }
    }

    public function statusLabel()
    {
        if ($this->isStarting()) {
            return __('Starting…');
        }

        if ($this->isActive()) {
            switch ($this->remote_status) {
                case self::REMOTE_REQUESTED: return __('Awaiting approval');
                case self::REMOTE_APPROVED:  return __('Waiting for customer');
                case self::REMOTE_READY:     return __('ID available');
                default:                     return __('Active');
            }
        }

        if ($this->status === self::STATUS_CLOSED) {
            return $this->remote_status === self::REMOTE_EXPIRED ? __('Expired') : __('Closed');
        }

        return __('Not started');
    }

    /** Bootstrap label class, so the status reads at a glance. */
    public function statusClass()
    {
        if ($this->isStarting()) {
            return 'label-info';
        }

        if ($this->isActive()) {
            return $this->remote_status === self::REMOTE_READY ? 'label-success' : 'label-warning';
        }

        if ($this->status === self::STATUS_CLOSED) {
            return 'label-default';
        }

        return 'label-info';
    }

    /**
     * Copy in what the operator listing says about our session. Returns true
     * if anything changed, so a poll that learns nothing writes nothing.
     */
    public function applyRemoteRow(array $row)
    {
        $before = [$this->remote_status, $this->remote_id, $this->registration, (string) $this->expires_at];

        if (!empty($row['status'])) {
            $this->remote_status = $row['status'];
        }
        // Only ever set: the listing carries null until the client reports,
        // and a later poll must not blank an ID we already showed the agent.
        if (!empty($row['rustdesk_id'])) {
            $this->remote_id = $row['rustdesk_id'];
        }
        // Same rule, same reason: the verdict is decided once, when the ID
        // arrives, and the backend does not recompute it afterwards. A later
        // poll blanking it would make a session look like it had changed its
        // mind about evidence that no longer exists to re-check.
        if (!empty($row['registration'])) {
            $this->registration = $row['registration'];
        }
        if (!empty($row['expires_at'])) {
            try {
                $this->expires_at = \Carbon\Carbon::parse($row['expires_at']);
            } catch (\Exception $e) {
                // A timestamp we cannot read is not worth failing a poll over.
            }
        }

        $this->synced_at = now();

        return $before !== [$this->remote_status, $this->remote_id, $this->registration, (string) $this->expires_at];
    }

    /** Mark the session finished locally. The backend owns the real cleanup. */
    public function markClosed($remote_status = self::REMOTE_CLOSED)
    {
        $this->status = self::STATUS_CLOSED;
        $this->remote_status = $remote_status;
        $this->closed_at = now();
        $this->synced_at = now();
    }

    /**
     * The shape the sidebar and the AJAX responses both use, so a start, a
     * poll and a page reload render identically.
     *
     * Note what is absent: no token, no API base, no backend URL. The browser
     * gets the customer's link and the session's own facts, which is all the
     * panel has to draw.
     */
    public function toState()
    {
        return [
            'status'        => $this->status ?: self::STATUS_NOT_STARTED,
            'remote_status' => $this->remote_status ?: '',
            'status_label'  => $this->statusLabel(),
            'status_class'  => $this->statusClass(),
            'session_ref'   => $this->helpdesk_session_id ? '#'.$this->helpdesk_session_id : '—',
            'code'          => $this->code ?: '—',
            'customer_url'  => $this->customerUrl(),
            'remote_id'     => $this->remote_id ?: '—',
            'registration'       => $this->registration ?: '',
            'registration_label' => $this->registrationLabel(),
            'registration_class' => $this->registrationClass(),
            'expires_at'    => $this->expires_at ? $this->expires_at->toDateTimeString() : '',
            'is_active'     => $this->isActive(),
            'is_busy'       => $this->isStarting(),
        ];
    }
}
