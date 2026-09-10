<?php

namespace Modules\GesoftRemoteSupport\Http\Controllers;

use App\Conversation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\GesoftRemoteSupport\Entities\RemoteSession;
use Modules\GesoftRemoteSupport\Exceptions\HelpdeskException;
use Modules\GesoftRemoteSupport\Services\HelpdeskClient;

/**
 * Operator-side endpoints for the conversation sidebar.
 *
 * Nothing here is public: the routes sit behind the `web` and `auth`
 * middleware, so an unauthenticated caller is redirected to the login page,
 * and `web` supplies the CSRF check on every POST. On top of that each call
 * re-checks the agent's own permission on the conversation, so knowing an id
 * is not enough.
 *
 * The browser talks only to these routes. Every call to helpdesk-rust is made
 * from here, with the ops token added on the way out and never returned: the
 * panel receives a session's code, link and status, and no credential.
 */
class GesoftRemoteSupportController extends Controller
{
    // `authorize()` lives in this trait, not in the base routing controller.
    use AuthorizesRequests;

    const LOG_PREFIX = 'GesoftRemoteSupport';

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Start a session in helpdesk-rust and record it against this conversation.
     *
     * Two backend calls:
     *
     *   1. `POST /api/ops/sessions` mints the session and answers with its id
     *      and the code — the id is what every later call addresses it by, the
     *      code is the customer's link.
     *   2. `POST /api/ops/{id}/approve` is the confirmation that makes the
     *      download claimable. An unapproved session shows the customer a page
     *      that serves them nothing, so the agent's click has to carry through
     *      to it — it is the same act as the operator console's "Confirm &
     *      open access".
     *
     * There is no listing step and no matching. If either call fails after the
     * session exists, it is closed again before returning: a session this
     * module cannot account for is one nobody will ever close.
     */
    public function start(Request $request, $conversation_id)
    {
        $conversation = $this->authorized($conversation_id);
        $user = auth()->user();

        $client = new HelpdeskClient();
        if (!$client->isConfigured()) {
            $e = new HelpdeskException(
                HelpdeskException::KIND_UNCONFIGURED,
                'api base or ops token missing'
            );
            \Log::error(self::LOG_PREFIX.': start refused — '.$e->getMessage());

            return $this->error($e->userMessage(), RemoteSession::forConversation($conversation->id));
        }

        // Two agents on the same conversation, or one agent double-clicking,
        // must not produce two sessions. The claim is a row-level one in the
        // database, not a disabled button: the button is a courtesy, this is
        // the guarantee.
        list($session, $claimed) = $this->claim($conversation, $user);

        if (!$claimed) {
            // Somebody already has it. Whatever they made is what the panel
            // gets — refreshed, so a start that races a start still ends up
            // showing the live session rather than a stale snapshot.
            $session = $this->refresh($session, $client);

            // ...unless the refresh just discovered there is nothing running:
            // the backend had already closed or expired it, and only this
            // row still said otherwise. The conversation is free, so claim it
            // and go on to make the session the agent actually asked for.
            // Without this the click returns an ended session, and the agent
            // is handed a customer link that serves nothing.
            if (!$session->isActive()) {
                list($session, $claimed) = $this->claim($conversation, $user);
            }

            if (!$claimed) {
                return $this->success($session);
            }
        }

        $remote_id = null;

        try {
            $created = $client->createSession(RemoteSession::hostnameMarker($conversation));
            $remote_id = $created['id'];

            $approved = $client->approve($remote_id);

            $session->status              = RemoteSession::STATUS_ACTIVE;
            $session->helpdesk_session_id = $remote_id;
            $session->code                = $created['code'];
            $session->remote_status       = !empty($approved['status'])
                ? $approved['status']
                : RemoteSession::REMOTE_APPROVED;
            // Nothing has reported an ID yet — the customer has not run
            // anything. The status poll fills this in when they do.
            $session->remote_id           = null;
            // And no ID means nothing to have checked against hbbs yet. A
            // verdict left over from the previous session on this conversation
            // would sit beside the new session's blank ID as if it described
            // it.
            $session->registration        = null;
            $session->expires_at          = $this->parseTime(
                isset($created['expires_at']) ? $created['expires_at'] : null
            );
            $session->synced_at           = now();
            $session->save();
        } catch (\Exception $e) {
            return $this->abandonStart($client, $session, $remote_id, $e);
        }

        \Log::info(self::LOG_PREFIX.': conversation '.$conversation->id
            .' started helpdesk session '.$remote_id.' (agent '.$user->id.')');

        // Announce it, and stop there. This module's job ends with a session
        // that exists and a link the agent can read; how the customer is given
        // that link depends on what kind of conversation this is, and that is
        // not knowledge this module should carry. A chat module can put it in
        // the chat; nothing at all can listen, and the panel behaves exactly as
        // it did before.
        \Eventy::action('gesoft.remote_support.started', $conversation, $session, $user);

        return $this->success($session);
    }

    /**
     * Where the session stands now, read from the backend through this server.
     *
     * The panel polls this rather than helpdesk-rust: the browser has no
     * credential to poll with, and it must not acquire one.
     *
     * The source is the operator listing filtered to our own id, not the
     * public `/api/status/{code}`. That route records the caller as the
     * customer — it is how the backend learns which address to open the
     * firewall to — so polling it from this server would register the
     * FreeScout VM as the customer's machine.
     */
    public function status(Request $request, $conversation_id)
    {
        $conversation = $this->authorized($conversation_id);
        $session = RemoteSession::forConversation($conversation->id);

        if (!$session->exists || !$session->isActive() || !$session->helpdesk_session_id) {
            return $this->success($session);
        }

        $client = new HelpdeskClient();
        if (!$client->isConfigured()) {
            return $this->error(__('Remote Support is not configured on this server.'), $session);
        }

        try {
            $row = $client->findById($session->helpdesk_session_id);
        } catch (HelpdeskException $e) {
            // A poll that cannot reach the backend says so and changes
            // nothing. An unreachable backend is not evidence a session ended.
            return $this->error($e->userMessage(), $session);
        }

        if ($row === null) {
            // The listing carries open sessions only, so a session that has
            // dropped out of it has ended — closed from the operator console,
            // or swept past its deadline.
            $expired = $session->expires_at && $session->expires_at->isPast();
            $session->markClosed($expired ? RemoteSession::REMOTE_EXPIRED : RemoteSession::REMOTE_CLOSED);
        } else {
            $session->applyRemoteRow($row);
        }

        $session->save();

        return $this->success($session);
    }

    /**
     * End the session through helpdesk-rust, which is what releases the
     * firewall hold and writes the audit entry. This module runs no cleanup of
     * its own and never touches the firewall.
     *
     * Idempotent in both directions: closing a conversation that has nothing
     * open is a no-op, and a backend that answers "no such session" or
     * "already closed" is agreement, not an error.
     */
    public function close(Request $request, $conversation_id)
    {
        $conversation = $this->authorized($conversation_id);
        $session = RemoteSession::forConversation($conversation->id);

        if (!$session->exists || $session->status === RemoteSession::STATUS_CLOSED) {
            return $this->success($session);
        }

        if ($session->helpdesk_session_id) {
            $client = new HelpdeskClient();
            if (!$client->isConfigured()) {
                return $this->error(__('Remote Support is not configured on this server.'), $session);
            }

            try {
                $client->close($session->helpdesk_session_id);
            } catch (HelpdeskException $e) {
                if (!$e->meansAlreadyGone()) {
                    // Unreachable or refusing for some other reason: the
                    // session may well still be live, so the panel keeps
                    // offering Close rather than pretending it is done.
                    return $this->error($e->userMessage(), $session);
                }

                \Log::info(self::LOG_PREFIX.': helpdesk session '
                    .$session->helpdesk_session_id.' was already closed ('.$e->getMessage().')');
            }
        }

        // The mapping is kept — id, code and reported RustDesk ID stay on the
        // row. It is the record of what this conversation was given access to,
        // and deleting it would leave the audit trail only on the backend.
        $session->markClosed();
        $session->save();

        \Log::info(self::LOG_PREFIX.': conversation '.$conversation->id
            .' closed helpdesk session '.$session->helpdesk_session_id);

        return $this->success($session);
    }

    /**
     * A one-time link for the agent's own RustDesk client, and the ways to use
     * it: a Windows download, a Linux command, and access alone.
     *
     * The agent's RustDesk has to be pointed at our server, and our server's
     * firewall has to let the agent's machine in. It opens the RustDesk ports
     * per address, and until this only ever for customers, so an agent could
     * reach a customer only when both shared a public address. Using the link
     * does both, for the machine that uses it — which is the machine that runs
     * RustDesk, where the browser's address could be another one entirely.
     *
     * Offered from a conversation because that is where the agent is when they
     * need it. The link itself is not tied to the conversation or a session.
     */
    public function technician(Request $request, $conversation_id)
    {
        $this->authorized($conversation_id);
        $user = auth()->user();

        $client = new HelpdeskClient();
        if (!$client->isConfigured()) {
            return response()->json(['status' => 'error', 'msg' => __('Remote Support is not configured on this server.')]);
        }

        // The same public base the customer link is built from: the agent's
        // machine reaches the backend the way a customer's does.
        $base = (string) config('gesoftremotesupport.customer_base');
        if ($base === '') {
            $base = (string) config('gesoftremotesupport.api_base');
        }
        $base = rtrim($base, '/');

        try {
            $link = $client->createTechnicianLink('FreeScout user '.$user->id.' ('.$user->getFullName().')');
        } catch (HelpdeskException $e) {
            return response()->json(['status' => 'error', 'msg' => $e->userMessage()]);
        }

        \Log::info(self::LOG_PREFIX.': agent '.$user->id.' was issued a technician link');

        return response()->json([
            'status'        => 'success',
            'msg'           => '',
            'windows_url'   => $base.$link['windows'],
            'linux_command' => !empty($link['linux_available']) ? 'curl -fsSL '.$base.$link['linux_script'].' | sh' : '',
            'open_url'      => $base.$link['open'],
            'link_minutes'  => (int) ($link['link_minutes'] ?? 0),
            'grant_hours'   => round(((int) ($link['grant_minutes'] ?? 0)) / 60, 1),
            'admits'        => !empty($link['admits']),
        ]);
    }

    // ------------------------------------------------------------- internals

    /**
     * Take the conversation's start slot, or report that somebody else has it.
     *
     * Returns `[$session, $claimed]`. The lock is `SELECT … FOR UPDATE` on a
     * unique key, so a concurrent starter waits here rather than racing; the
     * duplicate-key catch covers the first-ever insert, where two requests can
     * both find no row to lock.
     */
    protected function claim($conversation, $user)
    {
        try {
            return DB::transaction(function () use ($conversation, $user) {
                $session = RemoteSession::where('conversation_id', $conversation->id)
                    ->lockForUpdate()
                    ->first();

                if ($session && !$session->isClaimable()) {
                    return [$session, false];
                }

                if (!$session) {
                    $session = new RemoteSession(['conversation_id' => $conversation->id]);
                }

                // A retry must not leave the previous attempt's code or id on
                // screen while the new one is being made.
                $session->status              = RemoteSession::STATUS_STARTING;
                $session->helpdesk_session_id = null;
                $session->code                = null;
                $session->remote_status       = null;
                $session->remote_id           = null;
                $session->registration        = null;
                $session->started_by_user_id  = $user->id;
                $session->started_at          = now();
                $session->expires_at          = null;
                $session->synced_at           = null;
                $session->closed_at           = null;
                $session->save();

                return [$session, true];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // The other request inserted the row between our read and our
            // write. Its session is the one that counts.
            \Log::info(self::LOG_PREFIX.': conversation '.$conversation->id
                .' lost the start race');

            return [RemoteSession::forConversation($conversation->id), false];
        }
    }

    /**
     * Give up a start, leaving nothing behind on either side.
     *
     * If the backend already minted a session, it is closed again here. A
     * session that exists there but is unknown here can never be closed by
     * this module, and would sit holding whatever it holds until it expires —
     * so the compensating close is attempted even when the reason we are here
     * is that the local write failed.
     */
    protected function abandonStart(HelpdeskClient $client, RemoteSession $session, $remote_id, \Exception $e)
    {
        \Helper::logException($e, self::LOG_PREFIX.': start failed for conversation '
            .$session->conversation_id.($remote_id ? ' after creating helpdesk session '.$remote_id : '').':');

        if ($remote_id) {
            try {
                $client->close($remote_id);
                \Log::info(self::LOG_PREFIX.': compensating close of helpdesk session '.$remote_id.' succeeded');
            } catch (\Exception $ce) {
                \Log::error(self::LOG_PREFIX.': helpdesk session '.$remote_id
                    .' is orphaned — it was created but neither recorded nor closed.'
                    .' Close it from the operator console. ('.$ce->getMessage().')');
            }
        }
        // With no id there is nothing to close. Either the create never landed,
        // or it landed and answered without one — which `createSession()` has
        // already logged as the version mismatch it is. Guessing the id back out
        // of the operator listing is exactly the workaround this flow dropped.

        // Never leave the conversation looking started when it is not.
        $session->status        = RemoteSession::STATUS_NOT_STARTED;
        $session->remote_status = null;
        $session->code          = null;
        $session->helpdesk_session_id = null;
        $session->expires_at    = null;
        $session->synced_at     = null;
        $session->save();

        $message = $e instanceof HelpdeskException
            ? $e->userMessage()
            : __('Remote Support unavailable. Please try again shortly.');

        return $this->error($message, $session);
    }

    /**
     * Re-read an active session from the backend, best-effort. Used when a
     * start finds one already running: the caller gets live values, and a
     * backend hiccup degrades to the stored ones rather than to an error.
     */
    protected function refresh(RemoteSession $session, HelpdeskClient $client)
    {
        if (!$session->isActive() || !$session->helpdesk_session_id) {
            return $session;
        }

        try {
            $row = $client->findById($session->helpdesk_session_id);
        } catch (HelpdeskException $e) {
            return $session;
        }

        if ($row === null) {
            // The listing carries open sessions only, so a session that has
            // dropped out of it has ended — closed from the operator console,
            // or swept past its deadline. This is the same reading `status()`
            // makes, and it has to be made here too: leaving the row `active`
            // is what made a later Start hand the agent back the dead session
            // instead of minting a new one.
            $expired = $session->expires_at && $session->expires_at->isPast();
            $session->markClosed($expired ? RemoteSession::REMOTE_EXPIRED : RemoteSession::REMOTE_CLOSED);
            $session->save();

            return $session;
        }

        $session->applyRemoteRow($row);
        $session->save();

        return $session;
    }

    /** RFC3339 from the backend into a Carbon, or null if it will not parse. */
    protected function parseTime($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function success(RemoteSession $session)
    {
        return response()->json([
            'status' => 'success',
            'msg'    => '',
            'state'  => $session->toState(),
        ]);
    }

    /**
     * An error the agent can read. `$msg` is always one of the fixed sentences
     * from `HelpdeskException::userMessage()` — never an exception message, a
     * backend URL, a response body or a header — and the state travels with it
     * so the panel can redraw truthfully instead of freezing mid-action.
     */
    protected function error($msg, RemoteSession $session)
    {
        return response()->json([
            'status' => 'error',
            'msg'    => $msg,
            'state'  => $session->toState(),
        ]);
    }

    /**
     * The conversation, or an abort. `viewCached` is the same policy check the
     * core conversation view uses, so this endpoint can never show an agent a
     * conversation their mailbox permissions would hide.
     */
    protected function authorized($conversation_id)
    {
        $conversation = Conversation::findOrFail($conversation_id);
        $this->authorize('viewCached', $conversation);

        return $conversation;
    }
}
