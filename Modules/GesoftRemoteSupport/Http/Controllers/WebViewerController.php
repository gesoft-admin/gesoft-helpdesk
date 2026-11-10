<?php

// Gesoft, 2026-11-08; file transfer 2026-11-10. AGPL-3.0-only. Independent of the desktop support routes.
namespace Modules\GesoftRemoteSupport\Http\Controllers;

use App\Conversation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\GesoftRemoteSupport\Entities\RemoteSession;
use Modules\GesoftRemoteSupport\Services\HelpdeskClient;

class WebViewerController extends Controller
{
    use AuthorizesRequests;

    public function launch(Request $request, $conversation_id)
    {
        $this->enabled();
        abort_unless(auth()->check(), 401);
        $origin = (string) config('gesoftremotesupport.viewer_origin');
        abort_unless(strpos($origin, 'https://') === 0 && $request->getSchemeAndHttpHost() === $origin, 403);
        $conversation = Conversation::findOrFail($conversation_id);
        $this->authorize('viewCached', $conversation);
        $session = RemoteSession::forConversation($conversation->id);
        $client = new HelpdeskClient();
        $row = $this->liveSession($session, $client);
        $server = $client->viewerServerInfo();
        // The grant lasts as long as the intervention. It used to stop at 30
        // minutes, which put the technician out of a longer session; the
        // gateway re-checks login, mailbox access and the intervention every
        // two seconds, so closing or revoking any of them still ends it at once.
        $expires = \Carbon\Carbon::parse($row['expires_at'])->getTimestamp();
        abort_unless($expires > time(), 410);
        $attempt = bin2hex(random_bytes(24));
        $grant = [
            'actor' => (int) auth()->id(),
            'conversation' => (int) $conversation->id,
            'session' => (int) $session->helpdesk_session_id,
            'peer' => (string) $row['rustdesk_id'],
            'key' => (string) $server['key'],
            'expires' => $expires,
            'browser' => hash('sha256', $request->session()->getId()),
            'files' => (bool) config('gesoftremotesupport.viewer_files'),
        ];
        \Cache::put('gesoft-viewer:'.$attempt, $grant, now()->addSeconds($expires - time()));
        \Log::info('GesoftRemoteSupport viewer launched', [
            'actor' => $grant['actor'], 'conversation' => $conversation->id,
            'session' => $grant['session'], 'attempt' => $attempt,
        ]);
        $assets = \Module::getPublicPath(GESOFT_REMOTE_SUPPORT_MODULE).'/viewer';
        $wsBase = 'wss://'.parse_url($origin, PHP_URL_HOST)
            .(parse_url($origin, PHP_URL_PORT) ? ':'.parse_url($origin, PHP_URL_PORT) : '')
            .'/gesoft-viewer/ws/'.$attempt;
        $configuration = [
            'peerId' => $grant['peer'], 'serverKeyB64' => $grant['key'],
            'wsIdUrl' => $wsBase.'/id', 'wsRelayUrl' => $wsBase.'/relay',
            'myId' => 'helpdesk-'.$grant['actor'], 'myName' => auth()->user()->getFullName(),
            'workerUrl' => $assets.'/session.worker.js',
            'version' => 'lab', 'webrtc' => null, 'addressBook' => null,
        ];

        // Read by viewer-bootstrap.js, not by the upstream viewer: lets the page
        // stop reconnecting once the support session ends in the helpdesk.
        $lifecycle = [
            'statusUrl' => route('gesoftremotesupport.status', ['conversation_id' => $conversation->id], false),
            'session' => '#'.$grant['session'], 'expires' => $grant['expires'],
            'files' => $grant['files'],
        ];

        return response()->view('gesoftremotesupport::viewer', [
            'configuration' => $configuration, 'assets' => $assets,
            'conversation' => $conversation, 'lifecycle' => $lifecycle,
        ])->withHeaders($this->headers());
    }

    public function authorizeTransport(Request $request)
    {
        $this->enabled();
        abort_unless(auth()->check(), 401);
        $attempt = (string) $request->header('X-Viewer-Attempt', '');
        abort_unless(preg_match('/^[a-f0-9]{48}$/D', $attempt), 403);
        $grant = \Cache::get('gesoft-viewer:'.$attempt);
        abort_unless(is_array($grant) && $grant['expires'] > time(), 403);
        abort_unless($grant['actor'] === (int) auth()->id()
            && hash_equals($grant['browser'], hash('sha256', $request->session()->getId())), 403);
        $conversation = Conversation::findOrFail($grant['conversation']);
        $this->authorize('viewCached', $conversation);
        $session = RemoteSession::forConversation($conversation->id);
        abort_unless((int) $session->helpdesk_session_id === $grant['session'], 403);
        $row = $this->liveSession($session, new HelpdeskClient());
        abort_unless((string) $row['rustdesk_id'] === $grant['peer'], 403);

        // File transfer needs both the grant (the setting when the viewer was
        // opened) and the setting now: turning it off ends file transfers at
        // the gateway's next check. Grants from before this change have none.
        $files = ($grant['files'] ?? false) === true && (bool) config('gesoftremotesupport.viewer_files');

        return response()->json([
            'actor' => $grant['actor'], 'session' => $grant['session'],
            'peer' => $grant['peer'], 'expires' => $grant['expires'], 'key' => $grant['key'],
            'files' => $files,
        ])->withHeaders($this->headers());
    }

    protected function enabled()
    {
        abort_unless(config('gesoftremotesupport.viewer_enabled'), 404);
    }

    protected function liveSession(RemoteSession $session, HelpdeskClient $client)
    {
        abort_unless($session->exists && $session->isActive() && $session->helpdesk_session_id, 410);
        try {
            $row = $client->findById($session->helpdesk_session_id);
        } catch (\Exception $e) {
            abort(503, 'Remote Support temporarily unavailable.');
        }
        abort_unless($row && isset($row['status'], $row['rustdesk_id'], $row['expires_at'])
            && $row['status'] === RemoteSession::REMOTE_READY
            && preg_match('/^[0-9]{1,20}$/D', (string) $row['rustdesk_id'])
            && \Carbon\Carbon::parse($row['expires_at'])->isFuture(), 410);

        return $row;
    }

    protected function headers()
    {
        return [
            'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self'; media-src blob:; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
    }
}
