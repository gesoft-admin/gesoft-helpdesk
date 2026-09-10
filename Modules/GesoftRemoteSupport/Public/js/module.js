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

    // Messages this file writes itself, in the agent's interface language as
    // core rendered it. Everything else in the panel is translated server-side.
    var WORDS = {
        en: {
            unavailable: 'Remote Support unavailable.',
            expired: 'Your session expired — reload the page and try again.',
            failed: 'Request failed (HTTP {status}).',
            copied: 'copied',
            techNote: 'The links work for {minutes} minutes, and only on the machine that uses them first. That machine can then reach our server for {hours} hours.',
            techNoFirewall: 'This server does not manage the firewall: the links only download the client.'
        },
        ro: {
            unavailable: 'Asistența la distanță nu este disponibilă.',
            expired: 'Sesiunea a expirat — reîncărcați pagina și încercați din nou.',
            failed: 'Cererea a eșuat (HTTP {status}).',
            copied: 'copiat',
            techNote: 'Linkurile sunt valabile {minutes} minute și funcționează doar pe calculatorul care le folosește primul. Acel calculator poate accesa apoi serverul nostru {hours} ore.',
            techNoFirewall: 'Acest server nu administrează firewall-ul: linkurile doar descarcă clientul.'
        }
    };
    var T = WORDS[String(document.documentElement.lang || 'en').slice(0, 2).toLowerCase()] || WORDS.en;

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

        // Shown only once the backend has an answer. Before an ID is reported
        // there is nothing to have checked, and an empty label would read as a
        // failed check rather than as one that has not happened.
        var regRow = p.find('.gesoft-rs-registration-row');
        if (state.registration_label) {
            regRow.find('.gesoft-rs-registration')
                .removeClass('label-success label-danger label-default')
                .addClass(state.registration_class)
                .text(state.registration_label);
            regRow.show();
        } else {
            regRow.hide();
        }

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
                    .text((response && response.msg) || T.unavailable);
            }
        }).fail(function (xhr) {
            // 419 is an expired CSRF token, which a reload fixes; anything
            // else here is FreeScout itself, not the remote backend.
            msg.addClass('text-danger').text(
                xhr.status === 419
                    ? T.expired
                    : T.failed.replace('{status}', xhr.status)
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
                noteFailure((response && response.msg) || T.unavailable);
            }
        }).fail(function () {
            noteFailure(T.unavailable);
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
            button.text(T.copied);
            setTimeout(function () {
                button.text(original);
            }, 1200);
        }, function () {});
    });

    // The agent's own client. Each click asks for a fresh link: they last
    // minutes and belong to the first machine that uses them, so a link shown
    // earlier may already be spent.
    $(document).on('click', '.gesoft-rs-tech-get', function () {
        var p = panel(),
            msg = p.find('.gesoft-rs-msg'),
            button = $(this);

        button.prop('disabled', true);
        msg.removeClass('text-danger').text('…');

        $.ajax({
            url: p.data('url-technician'),
            type: 'POST',
            dataType: 'json',
            data: { _token: csrfToken() }
        }).done(function (r) {
            button.prop('disabled', false);
            if (!r || r.status !== 'success') {
                msg.addClass('text-danger').text((r && r.msg) || T.unavailable);
                return;
            }
            msg.removeClass('text-danger').text('');

            var box = p.find('.gesoft-rs-tech-links');
            box.find('.gesoft-rs-tech-windows').attr('href', r.windows_url);
            box.find('.gesoft-rs-tech-open').attr('href', r.open_url);
            box.find('.gesoft-rs-tech-linux').text(r.linux_command || '');
            box.find('.gesoft-rs-tech-linux-row').toggle(!!r.linux_command);
            box.find('.gesoft-rs-tech-note').text(
                r.admits
                    ? T.techNote.replace('{minutes}', r.link_minutes).replace('{hours}', r.grant_hours)
                    : T.techNoFirewall
            );
            box.show();
        }).fail(function (xhr) {
            button.prop('disabled', false);
            msg.addClass('text-danger').text(
                xhr.status === 419 ? T.expired : T.failed.replace('{status}', xhr.status)
            );
        });
    });

    $(document).on('click', '.gesoft-rs-tech-copy', function () {
        var command = panel().find('.gesoft-rs-tech-linux').text(),
            button = $(this);

        if (!command || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(command).then(function () {
            var original = button.text();
            button.text(T.copied);
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
