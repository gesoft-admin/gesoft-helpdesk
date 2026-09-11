<?php

namespace Modules\GesoftRemoteSupport\Services;

use App\Conversation;
use Modules\GesoftRemoteSupport\Entities\RemoteSession;
use Modules\GesoftRemoteSupport\Exceptions\HelpdeskException;

/**
 * Closing a ticket closes the remote session nobody has used yet.
 *
 * Without this a closed ticket left its session open until the backend's
 * deadline, and the customer's firewall grant with it (`docs/BACKLOG.md` in
 * helpdesk-rust, item 7). That was accepted while nothing else was registered
 * on our RustDesk server; technicians' machines are now, so the window is no
 * longer empty.
 *
 * A session whose customer has already reported an ID is left alone. A
 * technician may be connected to it right now, and a ticket is often closed
 * while the work is still finishing; cutting that off would be worse than the
 * hour the session has left. The panel still shows it open, with Close.
 *
 * Only a deliberate close counts: the status menu, a bulk action, the chat
 * module's inactivity sweep, a deleted or spam ticket. A reply that also sets
 * the status does not, because the reply is how the agent sends the link, and
 * a mailbox set to close on reply would otherwise end every session the moment
 * it was offered.
 */
class ClosedTicket
{
    const LOG_PREFIX = 'GesoftRemoteSupport';

    /** Whether a status change should end an unused session. */
    public static function ends($status, $changed_on_reply)
    {
        if ($changed_on_reply) {
            return false;
        }

        return in_array((int) $status, [Conversation::STATUS_CLOSED, Conversation::STATUS_SPAM], true);
    }

    /**
     * End the conversation's session if the customer has not reported an ID.
     * Returns true only when a session was closed here.
     *
     * Never throws: this runs inside whatever changed the ticket, and a backend
     * that is down must not stop an agent closing a ticket. The session then
     * ends at its deadline, as it did before.
     */
    public static function closeUnused($conversation, $reason)
    {
        $session = RemoteSession::where('conversation_id', $conversation->id)->first();
        if (!$session || !$session->isActive() || !$session->helpdesk_session_id) {
            return false;
        }

        $client = new HelpdeskClient();
        if (!$client->isConfigured()) {
            return false;
        }

        try {
            // Ask rather than trust the row: the ID may have arrived since the
            // last poll, and that is exactly the session not to close.
            $row = $client->findById($session->helpdesk_session_id);

            if ($row === null) {
                $expired = $session->expires_at && $session->expires_at->isPast();
                $session->markClosed($expired ? RemoteSession::REMOTE_EXPIRED : RemoteSession::REMOTE_CLOSED);
                $session->save();

                return false;
            }

            $session->applyRemoteRow($row);
            if ($session->remote_id) {
                $session->save();
                \Log::info(self::LOG_PREFIX.': conversation '.$conversation->id.' '.$reason
                    .'; helpdesk session '.$session->helpdesk_session_id.' left open, customer ID '.$session->remote_id);

                return false;
            }

            $client->close($session->helpdesk_session_id);
        } catch (HelpdeskException $e) {
            if (!$e->meansAlreadyGone()) {
                \Log::warning(self::LOG_PREFIX.': conversation '.$conversation->id.' '.$reason
                    .'; could not close helpdesk session '.$session->helpdesk_session_id.' ('.$e->getMessage().')');

                return false;
            }
        }

        $session->markClosed();
        $session->save();

        \Log::info(self::LOG_PREFIX.': conversation '.$conversation->id.' '.$reason
            .'; closed unused helpdesk session '.$session->helpdesk_session_id);

        return true;
    }
}
