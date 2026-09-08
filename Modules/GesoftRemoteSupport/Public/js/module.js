/**
 * FreeScout serves pages under `script-src 'self'`, so there are no inline
 * handlers here: every event is delegated through jQuery, which core already
 * loads. The panel carries its own URLs and ids as data attributes, so this
 * file never needs to know a route or an id of its own.
 *
 * It also never learns the backend's address or its ops token. Every request
 * below goes to this module's own routes; the server adds the credential and
 * returns a session's own facts — code, link, status, reported RustDesk ID.
 */
(function ($) {
    'use strict';

    // Slow enough to be invisible in the load of an open conversation, quick
    // enough that an agent watching the panel sees the ID land: this is a poll
    // of one small row, and only while a session is actually open.
    var POLL_MS = 5000;
    // Transient failures are ordinary. A backend that stays unreachable is
    // not, and polling it forever helps nobody.
    var MAX_POLL_FAILURES = 3;

    var timer = null;
    var failures = 0;

    function csrfToken() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function panel() {
        return $('#gesoft-rs-panel');
    }

    // Render a state object exactly the way the Blade view rendered the
    // initial one, so an action, a poll and a page reload all agree.
    function applyState(state) {
        var p = panel();

        p.find('.gesoft-rs-status')
            .removeClass('label-success label-default label-info label-warning')
            .addClass(state.status_class)
            .text(state.status_label);

        p.find('.gesoft-rs-session-ref').text(state.session_ref);
        p.find('.gesoft-rs-code').text(state.code);
        p.find('.gesoft-rs-remote-id').text(state.remote_id);

        var linkRow = p.find('.gesoft-rs-link-row');
        if (state.customer_url) {
            linkRow.find('.gesoft-rs-link').attr('href', state.customer_url).text(state.customer_url);
            linkRow.show();
        } else {
            linkRow.hide();
        }

        var expiresRow = p.find('.gesoft-rs-expires-row');
        if (state.expires_at) {
            expiresRow.find('.gesoft-rs-expires').text(state.expires_at);
            expiresRow.show();
        } else {
            expiresRow.hide();
        }

        p.find('.gesoft-rs-hint').toggle(!!state.is_active);
        p.find('.gesoft-rs-start').prop('disabled', !!state.is_active || !!state.is_busy);
        p.find('.gesoft-rs-close').prop('disabled', !state.is_active);

        if (state.is_active) {
            startPolling();
        } else {
            stopPolling();
        }
    }

    function call(url, method) {
        var p = panel(),
            msg = p.find('.gesoft-rs-msg');

        p.find('.gesoft-rs-start, .gesoft-rs-close').prop('disabled', true);
        msg.removeClass('text-danger').text('…');

        $.ajax({
            url: url,
            type: method || 'POST',
            dataType: 'json',
            data: method === 'GET' ? {} : { _token: csrfToken() }
        }).done(function (response) {
            if (response && response.state) {
                applyState(response.state);
            }

            if (response && response.status === 'success') {
                msg.removeClass('text-danger').text('');
            } else {
                // The server has already reduced whatever went wrong to a
                // sentence an agent can act on. Nothing else is shown.
                msg.addClass('text-danger')
                    .text((response && response.msg) || 'Remote Support unavailable.');
            }
        }).fail(function (xhr) {
            // 419 is an expired CSRF token, which a reload fixes; anything
            // else here is FreeScout itself, not the remote backend.
            msg.addClass('text-danger').text(
                xhr.status === 419
                    ? 'Your session expired — reload the page and try again.'
                    : 'Request failed (HTTP ' + xhr.status + ').'
            );
            refreshButtons();
        });
    }

    // Re-enable from what is on screen, for the case where the request never
    // reached the server and so returned no state to render.
    function refreshButtons() {
        var p = panel(),
            active = p.find('.gesoft-rs-close').data('was-active');

        p.find('.gesoft-rs-start').prop('disabled', !!active);
        p.find('.gesoft-rs-close').prop('disabled', !active);
    }

    function poll() {
        var p = panel();
        if (!p.length) {
            stopPolling();
            return;
        }

        $.ajax({
            url: p.data('url-status'),
            type: 'GET',
            dataType: 'json'
        }).done(function (response) {
            if (response && response.state) {
                applyState(response.state);
            }
            if (response && response.status === 'success') {
                failures = 0;
                p.find('.gesoft-rs-msg').removeClass('text-danger').text('');
            } else {
                noteFailure((response && response.msg) || 'Remote Support unavailable.');
            }
        }).fail(function () {
            noteFailure('Remote Support unavailable.');
        });
    }

    function noteFailure(message) {
        failures++;
        panel().find('.gesoft-rs-msg').addClass('text-danger').text(message);

        if (failures >= MAX_POLL_FAILURES) {
            stopPolling();
        }
    }

    function startPolling() {
        panel().find('.gesoft-rs-close').data('was-active', true);
        if (timer) {
            return;
        }
        failures = 0;
        timer = setInterval(poll, POLL_MS);
    }

    function stopPolling() {
        panel().find('.gesoft-rs-close').data('was-active', false);
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    $(document).on('click', '.gesoft-rs-start', function () {
        call(panel().data('url-start'), 'POST');
    });

    $(document).on('click', '.gesoft-rs-close', function () {
        call(panel().data('url-close'), 'POST');
    });

    $(document).on('click', '.gesoft-rs-copy', function () {
        var link = panel().find('.gesoft-rs-link').attr('href'),
            button = $(this);

        if (!link || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(link).then(function () {
            var original = button.text();
            button.text('copied');
            setTimeout(function () {
                button.text(original);
            }, 1200);
        }, function () {});
    });

    // The toolbar button only brings the panel into view — no session is
    // started and nothing external is called from here.
    $(document).on('click', '.gesoft-rs-jump', function (e) {
        e.preventDefault();
        var p = panel();
        if (!p.length) {
            return;
        }
        $('html, body').animate({ scrollTop: p.offset().top - 60 }, 200);
        p.addClass('gesoft-rs-highlight');
        setTimeout(function () {
            p.removeClass('gesoft-rs-highlight');
        }, 1200);
    });

    // A conversation that is already running when the page loads starts
    // polling without waiting for a click.
    $(function () {
        if (!panel().find('.gesoft-rs-close').prop('disabled')) {
            startPolling();
        }
    });
})(jQuery);
