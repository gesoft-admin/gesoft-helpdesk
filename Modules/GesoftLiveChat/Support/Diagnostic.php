<?php

namespace Modules\GesoftLiveChat\Support;

/**
 * What a diagnostic report is allowed to be.
 *
 * This is the helpdesk's own idea of the format, and it is deliberately not a
 * courtesy check of something we trust. The application builds the report and
 * sanitises it; this decides, separately, whether what arrived is a diagnostic
 * report at all -- and if it is not, the answer is no. That is the whole reason
 * the endpoint is not a file upload: a file upload is "store these bytes", and
 * whoever holds the application's secret would then be able to put arbitrary
 * content, under an arbitrary name, in front of an agent.
 *
 * So every field is named here, with its type and its ceiling. An unknown field
 * is a refusal rather than something to drop quietly: a report that has grown a
 * new section is a report built by something this helpdesk has not been taught
 * to read, and reading it anyway is how a format becomes whatever the caller
 * feels like sending.
 *
 * Over the schema sits one more pass, `looksSecret()`. The application is
 * supposed to have removed credentials already, and does; this is the second
 * lock, because the file outlives the request and an agent will open it. A
 * field that trips it is dropped -- never partly masked, which for a private
 * key or a password is a way of publishing most of it.
 *
 * Framework-free on purpose, like `Apps`: it is the part most worth testing
 * without a database, an HTTP request or a Laravel application behind it.
 */
class Diagnostic
{
    /** The prefix the file is written under, and the only one served. */
    const FILE_PREFIX = 'gesoft-diagnostic-';

    /** The one type this module ever writes or serves. */
    const MIME = 'application/json';

    /**
     * Fields that may appear at the top level, with what each may hold.
     *
     * 's' string, 'i' integer, 'b' boolean; the number is the ceiling --
     * characters for a string, and for the two lists the number of entries.
     */
    protected static $fields = [
        'incident_id'  => ['s', 64],
        'reported_at'  => ['s', 40],
        'application'  => ['s', 64],
        'release'      => ['s', 64],
        'environment'  => ['s', 32],
        'status'       => ['i', 0],
        'method'       => ['s', 12],
        'path'         => ['s', 500],
        'route'        => ['s', 191],
        'controller'   => ['s', 191],
        'action'       => ['s', 64],
        'request_id'   => ['s', 64],
        'php'          => ['s', 32],
        'framework'    => ['s', 64],
        'exception'    => ['a', 0],
        'stack'        => ['l', 40],
        'events'       => ['l', 20],
        'truncated'    => ['b', 0],
    ];

    /** Fields without which a report is not a report. */
    protected static $required = ['incident_id', 'reported_at', 'application', 'status'];

    /** What an `exception` may say, and how much of it. */
    protected static $exception = [
        'class'   => 191,
        'code'    => 32,
        'summary' => 2000,
    ];

    /**
     * The field each of the three small maps cannot do without.
     *
     * A frame with no file is not a frame, an event with no message is not an
     * event, and either can happen honestly: the sanitizer drops a field that
     * carries a credential, and what is left is a row that says nothing. It
     * goes, rather than travelling as an empty shape for somebody to wonder
     * about.
     */
    protected static $needs = [
        'exception' => 'class',
        'stack'     => 'file',
        'events'    => 'message',
    ];

    /** What one stack frame may say. */
    protected static $frame = [
        'file' => 300,
        'line' => 12,
        'call' => 300,
    ];

    /** What one log event may say. */
    protected static $event = [
        'at'      => 40,
        'level'   => 16,
        'message' => 500,
    ];

    /**
     * The report as this module will keep it, or null if it is not one.
     *
     * Field order is the order declared above rather than the order it arrived
     * in, so two reports of the same shape are the same file. Optional fields
     * that are absent stay absent -- an empty string in a report reads as "we
     * looked and there was nothing", which is not the same as not looking.
     */
    public static function accept($report)
    {
        if (!is_array($report) || !$report) {
            return null;
        }

        foreach (array_keys($report) as $key) {
            if (!isset(self::$fields[$key])) {
                return null;
            }
        }

        foreach (self::$required as $key) {
            if (!isset($report[$key]) || $report[$key] === '' || $report[$key] === []) {
                return null;
            }
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{7,63}$/', (string) $report['incident_id'])) {
            return null;
        }

        $clean = [];

        foreach (self::$fields as $key => $rule) {
            if (!array_key_exists($key, $report)) {
                continue;
            }

            $value = self::field($key, $rule, $report[$key]);

            // `false` means the field was not merely unusable but wrong --
            // a named object with a field this helpdesk does not know. That is
            // the whole report refused, for the reason the top-level check
            // above gives.
            if ($value === false) {
                return null;
            }

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        // The required fields have to have survived their own validation, not
        // merely have been present: a status of "yesterday" is not a status.
        foreach (self::$required as $key) {
            if (!isset($clean[$key])) {
                return null;
            }
        }

        return $clean;
    }

    /**
     * The bytes to write, and how many there are.
     *
     * Pretty-printed because somebody reads it: this file is opened by an agent
     * looking for one line, not parsed by a machine. Slashes unescaped and
     * unicode kept for the same reason.
     */
    public static function render(array $report)
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * The name the file is served under.
     *
     * Ours, not the caller's. The incident id is the only part that varies and
     * it has already been held to `[A-Z0-9-]`, so there is nothing here a
     * caller could steer -- no extension of their choosing, no path, no dots to
     * climb with.
     */
    public static function filename($incident_id)
    {
        return self::FILE_PREFIX.$incident_id.'.json';
    }

    /**
     * Whether a value looks like a credential rather than a fact about a fault.
     *
     * Deliberately eager. The cost of being wrong in one direction is a missing
     * line in a diagnostic file; in the other it is a password in a helpdesk,
     * kept for as long as the conversation is.
     */
    public static function looksSecret($value)
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $patterns = [
            // "Bearer eyJ...", "Authorization: ..." and friends.
            '/\b(?:bearer|basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
            '/\bauthorization\s*[:=]/i',
            // A JWT, whatever it is called where it appears.
            '/\beyJ[A-Za-z0-9._-]{16,}/',
            // password=..., api_key: ..., secret => ..., token = ...
            '/\b(?:pass|passwd|password|pwd|secret|api[_-]?key|apikey|token|access[_-]?token|refresh[_-]?token|client[_-]?secret|csrf[_-]?token|session[_-]?id)\b\s*[:=>]+\s*\S/i',
            // PEM blocks of any kind.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            '/-----BEGIN (?:RSA|DSA|EC|OPENSSH|PGP) /',
            // A cookie header, or something shaped like a PHP session cookie.
            '/\bcookie\s*[:=]/i',
            '/\bPHPSESSID\b|\b_identity[-_a-z]*\s*=|\bXSRF-TOKEN\b/i',
            // A database URL with credentials in it.
            '/\b[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
            // AWS-shaped keys and long opaque secrets in an obvious setting.
            '/\bAKIA[0-9A-Z]{16}\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One top-level field, held to its declared type and ceiling.
     */
    protected static function field($key, array $rule, $value)
    {
        list($type, $max) = $rule;

        if ($type === 'b') {
            return is_bool($value) ? $value : null;
        }

        if ($type === 'i') {
            return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
        }

        if ($type === 's') {
            return self::text($value, $max);
        }

        // A named object. An unknown field in it is the same drift as an
        // unknown field at the top level, and gets the same answer.
        if ($type === 'a') {
            return self::map($value, self::$exception, self::$needs['exception'], true);
        }

        // A list: the frames of a stack, or a handful of log events. Here a bad
        // entry is dropped rather than fatal -- these are best-effort detail
        // about a fault, and losing one line of it is not a reason to lose the
        // report that says which fault.
        if (!is_array($value)) {
            return null;
        }

        $shape = $key === 'stack' ? self::$frame : self::$event;
        $list = [];

        foreach (array_slice(array_values($value), 0, $max) as $entry) {
            $row = self::map($entry, $shape, self::$needs[$key], false);

            if (is_array($row)) {
                $list[] = $row;
            }
        }

        return $list ? $list : null;
    }

    /**
     * A small map of strings -- an exception, a frame, an event.
     *
     * Returns the map, or null when it does not survive. `$strict` says what an
     * unknown field means: `false` at the top of the call refuses the report,
     * which is how the `accept()` above reads it.
     */
    protected static function map($value, array $shape, $needs, $strict)
    {
        if (!is_array($value) || !$value) {
            return $strict ? false : null;
        }

        foreach (array_keys($value) as $key) {
            if (!isset($shape[$key])) {
                return $strict ? false : null;
            }
        }

        $row = [];

        foreach ($shape as $key => $max) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $text = self::text($value[$key], $max);

            if ($text !== null) {
                $row[$key] = $text;
            }
        }

        // Without the one field that carries its meaning it is not a row.
        if (!isset($row[$needs])) {
            return null;
        }

        return $row;
    }

    /**
     * A string, or nothing at all.
     *
     * Control characters go -- a report is read in a browser and in a terminal,
     * and neither has any business being steered by one. Tabs and newlines stay
     * inside a summary; everything else is a single line by the time it gets
     * here anyway.
     */
    protected static function text($value, $max)
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim((string) $value);

        if ($value === '' || self::looksSecret($value)) {
            return null;
        }

        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        } else {
            $value = substr($value, 0, $max);
        }

        return $value;
    }
}
