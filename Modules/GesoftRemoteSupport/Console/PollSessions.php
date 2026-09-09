<?php

namespace Modules\GesoftRemoteSupport\Console;

use Illuminate\Console\Command;
use Modules\GesoftRemoteSupport\Entities\RemoteSession;
use Modules\GesoftRemoteSupport\Exceptions\HelpdeskException;
use Modules\GesoftRemoteSupport\Services\HelpdeskClient;

/**
 * Keeps open sessions up to date when nobody is looking at the panel.
 *
 * Until this existed the module only learned anything while an agent had the
 * conversation open, because the panel's poll was the only thing that ever
 * asked. So an agent who sent the link and moved on to another ticket never
 * found out that the customer had started the tool — the ID arrived at the
 * backend and stopped there.
 *
 * That is a real gap on the customer's side of the call, not a cosmetic one:
 * the customer runs the tool, waits, and nothing happens, because the person
 * who should now connect to them is reading something else.
 *
 * Announces `gesoft.remote_support.ready` the first time an ID appears.
 * Whoever wants to do something about it — the chat module puts a line in the
 * chat — listens for that; this command has no opinion about who.
 */
class PollSessions extends Command
{
    protected $signature = 'gesoftremotesupport:poll-sessions';

    protected $description = 'Refresh open remote-support sessions and announce the ones that became ready';

    public function handle()
    {
        $client = new HelpdeskClient();
        if (!$client->isConfigured()) {
            return 0;
        }

        $sessions = RemoteSession::where('status', RemoteSession::STATUS_ACTIVE)
            ->whereNotNull('helpdesk_session_id')
            ->get();

        $ready = 0;

        foreach ($sessions as $session) {
            $had_id = (bool) $session->remote_id;

            try {
                $row = $client->findById($session->helpdesk_session_id);
            } catch (HelpdeskException $e) {
                // A backend we cannot reach is not evidence of anything. Leave
                // the row alone and try again next minute.
                continue;
            }

            if ($row === null) {
                $expired = $session->expires_at && $session->expires_at->isPast();
                $session->markClosed($expired ? RemoteSession::REMOTE_EXPIRED : RemoteSession::REMOTE_CLOSED);
                $session->save();
                continue;
            }

            $session->applyRemoteRow($row);
            $session->save();

            if (!$had_id && $session->remote_id) {
                $ready++;
                $this->line(sprintf('  ready  conversation %d  id %s', $session->conversation_id, $session->remote_id));

                // There is no relation on the model, so ask for it.
                $conversation = \App\Conversation::find($session->conversation_id);
                \Eventy::action('gesoft.remote_support.ready', $conversation, $session);
            }
        }

        if ($ready) {
            \Log::info('GesoftRemoteSupport: sessions became ready', ['count' => $ready]);
        }

        return 0;
    }
}
