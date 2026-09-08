<?php

namespace Modules\GesoftRemoteSupport\Exceptions;

/**
 * A call to helpdesk-rust that did not produce a usable answer.
 *
 * Carries two separate strings on purpose. `getMessage()` is for the log and
 * may name the endpoint and the status; `userMessage()` is what the agent is
 * allowed to read, and is written so that no variant of it can leak the ops
 * token, an internal URL, or a backend stack trace into the browser.
 */
class HelpdeskException extends \Exception
{
    /** The backend could not be reached at all: DNS, refused, timeout. */
    const KIND_UNAVAILABLE = 'unavailable';
    /** No API base or no token configured on this FreeScout install. */
    const KIND_UNCONFIGURED = 'unconfigured';
    /** 401/403 — the ops token is wrong, or a proxy rejected us. */
    const KIND_AUTH = 'auth';
    /** 404, or a session the backend no longer knows about. */
    const KIND_NOT_FOUND = 'not_found';
    /** 400/409 — the backend refused the transition we asked for. */
    const KIND_CONFLICT = 'conflict';
    /** 5xx. */
    const KIND_SERVER = 'server';
    /** 2xx, but not the JSON we expect. */
    const KIND_BAD_RESPONSE = 'bad_response';

    protected $kind;
    protected $httpStatus;

    public function __construct($kind, $logMessage, $httpStatus = 0)
    {
        parent::__construct($logMessage);
        $this->kind = $kind;
        $this->httpStatus = (int) $httpStatus;
    }

    public function kind()
    {
        return $this->kind;
    }

    public function httpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * Whether this means "the session is already gone", which for a close is
     * success rather than failure: closing something twice must not leave the
     * panel stuck on a session that no longer exists.
     */
    public function meansAlreadyGone()
    {
        return $this->kind === self::KIND_NOT_FOUND || $this->kind === self::KIND_CONFLICT;
    }

    /**
     * The sentence the agent sees. Deliberately vague about the backend: an
     * agent cannot act on "connection refused to the backend host" and it is
     * not information the browser needs to have.
     */
    public function userMessage()
    {
        switch ($this->kind) {
            case self::KIND_UNCONFIGURED:
                return __('Remote Support is not configured on this server.');
            case self::KIND_AUTH:
                return __('Remote Support rejected this server. Ask an administrator to check the connection.');
            case self::KIND_NOT_FOUND:
                return __('That remote session no longer exists.');
            case self::KIND_CONFLICT:
                return __('Remote Support could not apply that change to the session.');
            default:
                return __('Remote Support unavailable. Please try again shortly.');
        }
    }
}
