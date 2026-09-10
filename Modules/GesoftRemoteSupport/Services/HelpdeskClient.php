<?php

namespace Modules\GesoftRemoteSupport\Services;

use Modules\GesoftRemoteSupport\Exceptions\HelpdeskException;

/**
 * The one place that talks to helpdesk-rust.
 *
 * Everything here runs server-side. The browser never calls the backend and
 * never receives the ops token: the panel posts to this module's own routes,
 * and this class adds the credential on the way out.
 *
 * ## The API as it exists today
 *
 * The operator plane of helpdesk-rust (`crates/api/src/routes.rs`) is:
 *
 *   POST /api/ops/sessions   {"hostname": …} -> {id, code, status, expires_at}
 *   GET  /api/ops/sessions                   -> [Session, …]  (open only)
 *   POST /api/ops/{id}/approve               -> {status}
 *   POST /api/ops/{id}/close                 -> {status}
 *
 * Create answers with the id, so starting a session is create then approve and
 * nothing in between. An earlier build did not return it, and the id had to be
 * recovered by reading the operator listing back and matching the marker this
 * module writes into `hostname` — a join through a display field, with a window
 * where a created session belonged to nobody. That is gone, and deliberately
 * not kept as a fallback: a backend that answers without an id is one this
 * module does not understand, and it should say so rather than quietly resume
 * guessing.
 *
 * The public `GET /api/status/{code}` remains **not** usable from here. It
 * calls `store::mark_seen` with the caller's address, which is how the backend
 * learns the customer is on the landing page and which IP to open the firewall
 * to. Polling it from this server would record the FreeScout VM as the
 * customer. Status therefore comes from the operator list, filtered to our own
 * session id.
 */
class HelpdeskClient
{
    /**
     * The header helpdesk-rust reads the token from. Not `Authorization`:
     * in production a reverse proxy claims that header for basic auth in front
     * of the whole operator plane, and a second credential in it would collide.
     */
    const TOKEN_HEADER = 'X-Ops-Token';

    const LOG_PREFIX = 'GesoftRemoteSupport';

    protected $base;
    protected $token;

    public function __construct($base = null, $token = null)
    {
        $base = $base === null ? config('gesoftremotesupport.api_base') : $base;
        $token = $token === null ? config('gesoftremotesupport.ops_token') : $token;

        $this->base = rtrim((string) $base, '/');
        $this->token = (string) $token;
    }

    public function isConfigured()
    {
        return $this->base !== '' && $this->token !== '';
    }

    /**
     * Mint a session. Returns the reply, which must carry both the `id` this
     * module addresses the session by and the `code` the customer's link is
     * built from. `$hostname` is the name the operator console displays.
     *
     * A 2xx without an id means the backend made a session that this module has
     * no way to name — it can neither approve it nor close it. That is a version
     * mismatch, not a transient fault, so it is logged as one and the caller is
     * told the reply was bad. The session is left to expire on its own; there is
     * no listing fallback, on purpose.
     */
    public function createSession($hostname)
    {
        $reply = $this->request('POST', '/api/ops/sessions', ['hostname' => $hostname]);

        if (empty($reply['code']) || !is_string($reply['code'])) {
            throw new HelpdeskException(
                HelpdeskException::KIND_BAD_RESPONSE,
                'create returned no code'
            );
        }

        if (!isset($reply['id']) || !is_numeric($reply['id']) || (int) $reply['id'] <= 0) {
            \Log::error(self::LOG_PREFIX.': the backend created a session but its reply carries'
                .' no id, so the session can be neither approved nor closed and is left to expire.'
                .' This build of helpdesk-rust predates POST /api/ops/sessions returning an id.');

            throw new HelpdeskException(
                HelpdeskException::KIND_BAD_RESPONSE,
                'create returned no id'
            );
        }

        $reply['id'] = (int) $reply['id'];

        return $reply;
    }

    /**
     * Every session the backend still considers open. Only `findById()` reads
     * this — the status poll. Start lists nothing.
     */
    public function listSessions()
    {
        $rows = $this->request('GET', '/api/ops/sessions');

        if (!is_array($rows)) {
            throw new HelpdeskException(
                HelpdeskException::KIND_BAD_RESPONSE,
                'session list was not an array'
            );
        }

        return $rows;
    }

    /** One open session by id, or null once the backend has closed it. */
    public function findById($id)
    {
        foreach ($this->listSessions() as $row) {
            if (isset($row['id']) && (int) $row['id'] === (int) $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Confirm the session, which is what makes the customer's download
     * claimable — until this call `/d/{code}` shows the page but serves
     * nothing. The agent pressing Start is the human confirmation the operator
     * console asks for with its "Confirm & open access" button.
     */
    public function approve($id)
    {
        return $this->request('POST', '/api/ops/'.((int) $id).'/approve');
    }

    /** End the session and release whatever it held open. */
    public function close($id)
    {
        return $this->request('POST', '/api/ops/'.((int) $id).'/close');
    }

    /**
     * A one-time link for the agent's own RustDesk client.
     *
     *   POST /api/ops/technician-links {"label": …}
     *     -> {token, expires_at, windows, linux_script, open,
     *         linux_available, link_minutes, grant_minutes, admits}
     *
     * The paths come back relative; the caller puts the public base in front.
     * `$label` names the agent in the backend's audit trail.
     */
    public function createTechnicianLink($label)
    {
        $reply = $this->request('POST', '/api/ops/technician-links', ['label' => (string) $label]);

        if (empty($reply['token']) || !is_string($reply['token']) || !preg_match('/^[0-9a-f]{64}$/', $reply['token'])) {
            throw new HelpdeskException(
                HelpdeskException::KIND_BAD_RESPONSE,
                'technician link returned no token'
            );
        }

        foreach (['windows', 'linux_script', 'open'] as $path) {
            if (empty($reply[$path]) || !is_string($reply[$path]) || strpos($reply[$path], '/t/') !== 0) {
                throw new HelpdeskException(
                    HelpdeskException::KIND_BAD_RESPONSE,
                    'technician link returned no '.$path.' path'
                );
            }
        }

        return $reply;
    }

    // ------------------------------------------------------------------ http

    protected function request($method, $path, array $json = null)
    {
        if (!$this->isConfigured()) {
            throw new HelpdeskException(
                HelpdeskException::KIND_UNCONFIGURED,
                'GESOFT_REMOTE_SUPPORT_API_BASE or GESOFT_REMOTE_SUPPORT_OPS_TOKEN is not set'
            );
        }

        // FreeScout's own defaults: `app.curl_timeout` for the read, the site
        // proxy, and the site's TLS verification setting — which this module
        // takes as it finds it and never turns off.
        $options = \Helper::setGuzzleDefaultOptions([
            'headers' => [
                self::TOKEN_HEADER => $this->token,
                'Accept'           => 'application/json',
            ],
            // Statuses are the API's way of answering; they are read below, not
            // thrown. A thrown Guzzle exception embeds the response body in its
            // message, which is a worse thing to have flowing through the logs.
            'http_errors' => false,
            // The token rides in a custom header, and Guzzle replays custom
            // headers across redirects — including to another host. Nothing in
            // this API redirects, so a redirect means the base URL is wrong,
            // and the safe response is to stop rather than to follow.
            'allow_redirects' => false,
        ]);

        // A backend that is down should fail the click in seconds, not in the
        // half-minute FreeScout allows for reaching freescout.net.
        $connect = (int) config('gesoftremotesupport.connect_timeout');
        if ($connect > 0) {
            $options['connect_timeout'] = min($connect, (int) $options['connect_timeout'] ?: $connect);
        }

        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = (new \GuzzleHttp\Client())->request($method, $this->base.$path, $options);
        } catch (\Exception $e) {
            // The message holds the method, the URI and the transport error.
            // The token is a header, so it is not in either.
            \Helper::logException($e, self::LOG_PREFIX.' '.$method.' '.$path.':');

            throw new HelpdeskException(
                HelpdeskException::KIND_UNAVAILABLE,
                'transport failure on '.$method.' '.$path
            );
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            $this->fail($method, $path, $status, $body);
        }

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            \Log::error(self::LOG_PREFIX.' '.$method.' '.$path.': HTTP '.$status
                .' was not JSON: '.$this->excerpt($body));

            throw new HelpdeskException(
                HelpdeskException::KIND_BAD_RESPONSE,
                'invalid JSON from '.$method.' '.$path
            );
        }

        return $decoded;
    }

    /** Turn a non-2xx into the exception the controller can act on. */
    protected function fail($method, $path, $status, $body)
    {
        if ($status === 401 || $status === 403) {
            $kind = HelpdeskException::KIND_AUTH;
        } elseif ($status === 404) {
            $kind = HelpdeskException::KIND_NOT_FOUND;
        } elseif ($status === 409 || $status === 400) {
            // helpdesk-rust answers a refused state change with 400 and an
            // `{"error": …}` body — "no such session", or "cannot move session
            // from Closed to Closed". Both mean the same thing to us.
            $kind = HelpdeskException::KIND_CONFLICT;
        } elseif ($status >= 500) {
            $kind = HelpdeskException::KIND_SERVER;
        } else {
            $kind = HelpdeskException::KIND_BAD_RESPONSE;
        }

        // A refused transition is the backend answering, not the backend
        // failing: closing an already-closed session is the ordinary shape of
        // an idempotent retry, and it must not fill the log with errors.
        $line = self::LOG_PREFIX.' '.$method.' '.$path.': HTTP '.$status.' '.$this->excerpt($body);
        if ($kind === HelpdeskException::KIND_CONFLICT || $kind === HelpdeskException::KIND_NOT_FOUND) {
            \Log::info($line);
        } else {
            \Log::error($line);
        }

        throw new HelpdeskException($kind, $method.' '.$path.' returned '.$status, $status);
    }

    /**
     * A bounded slice of a response body for the log. helpdesk-rust keeps its
     * internals out of error bodies (`{"error":"internal error"}`), but this
     * module cannot assume whatever sits in front of it does the same, so the
     * excerpt is short and the token is scrubbed in case a proxy ever echoes a
     * request header back at us.
     */
    protected function excerpt($body)
    {
        $body = preg_replace('/\s+/', ' ', (string) $body);

        if ($this->token !== '') {
            $body = str_replace($this->token, '[redacted]', $body);
        }

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body;
    }
}
