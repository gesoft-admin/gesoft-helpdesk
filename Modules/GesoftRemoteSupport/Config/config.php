<?php

/**
 * Module configuration.
 *
 * This file is the only place in the module that calls `env()`. Anywhere else
 * it would read as `null` the moment someone runs `php artisan config:cache`,
 * which is the supported production setup — the cached config array is built
 * from these files once and the real environment is never consulted again.
 * Controllers read `config('gesoftremotesupport.…')` instead.
 *
 * Set in the FreeScout `.env` (never committed):
 *
 *   GESOFT_REMOTE_SUPPORT_API_BASE=http://192.168.122.1:8080
 *   GESOFT_REMOTE_SUPPORT_OPS_TOKEN=<the HELPDESK_OPS_TOKEN of that backend>
 *
 * The token is an operator credential for the whole helpdesk backend. It stays
 * server-side: no route, view or JSON response in this module ever emits it.
 */
return [
    'name' => 'GesoftRemoteSupport',

    /**
     * Base URL of the helpdesk-rust service, as reachable *from this server*.
     * Not the address the customer uses — those can differ, and only this one
     * has to resolve here.
     */
    'api_base' => env('GESOFT_REMOTE_SUPPORT_API_BASE', ''),

    /** Shared operator secret. Sent as the `X-Ops-Token` request header. */
    'ops_token' => env('GESOFT_REMOTE_SUPPORT_OPS_TOKEN', ''),

    /**
     * Base URL the *customer* uses, which is what the support link is built
     * from. It is separate from `api_base` because the two are not the same
     * address in a split-horizon deployment: this server may reach the backend
     * over a private bridge while the customer reaches it over its public name.
     * The backend knows its own public base (`HELPDESK_API_BASE`) but does not
     * publish it on any endpoint, so it is configured here. Left empty, the
     * link falls back to `api_base`, which is right whenever the two coincide.
     */
    'customer_base' => env('GESOFT_REMOTE_SUPPORT_CUSTOMER_BASE', ''),

    /**
     * Connect timeout, in seconds. The read timeout stays FreeScout's own
     * `app.curl_timeout`; this one is separate because the case it covers is a
     * backend that is *down*, and 30 s (the FreeScout default) of a blocked
     * agent click is not a useful way to learn that.
     */
    'connect_timeout' => env('GESOFT_REMOTE_SUPPORT_CONNECT_TIMEOUT', 5),

    /**
     * How long a start may sit half-finished before another one may take over.
     * Only reached if a request dies between claiming the conversation and
     * writing the result — a crash or a PHP timeout mid-call.
     */
    'start_lock_seconds' => 60,
];
