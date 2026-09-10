/**
 * The visitor's chat bubble.
 *
 * One script tag, no dependencies, no iframe. The whole widget lives in a
 * shadow root so a customer's own stylesheet cannot reach into it and ours
 * cannot leak out — and so that embedding it never becomes a negotiation about
 * CSS specificity on somebody else's site.
 *
 * An iframe was the obvious alternative and is the wrong one here: FreeScout
 * sets `X-Frame-Options` globally from `app.x_frame_options`, so framing our
 * own page would mean weakening framing protection across the whole operator
 * interface to gain a bubble.
 *
 * Configure by putting the attributes on the script tag:
 *
 *   <script src="https://helpdesk.example.com/modules/gesoftlivechat/js/widget.js"
 *           data-base="https://helpdesk.example.com"
 *           data-title="Asistență Gesoft"></script>
 *
 * `data-base` is the helpdesk's own address and must be set whenever the page
 * is not served from it, which is every real embedding.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var BASE = (script && script.getAttribute('data-base')) || '';
    var TITLE = (script && script.getAttribute('data-title')) || 'Asistență';

    // Identity the host page already knows. An application where the customer
    // is signed in should hand it over rather than make them introduce
    // themselves a second time; a plain website leaves this empty and the
    // visitor is asked.
    var IDENTITY = (window.GesoftLiveChat && window.GesoftLiveChat.identity) || {};
    var ASK = (script && script.getAttribute('data-prechat')) !== 'off'
              && !(IDENTITY.email || IDENTITY.name);
    var STORE = 'gesoft-live-chat-token';
    var POLL_MS = 3000;

    // The tab, not the browser. sessionStorage survives a reload and moving
    // between pages of the same site in the same tab, and dies with the tab.
    // A new tab is a new conversation, and a closed browser keeps nothing —
    // which is the point on a shared computer, where the next person to open
    // the site must not find the last person's chat waiting for them.
    function store() {
        try { return window.sessionStorage; } catch (e) { return null; }
    }

    var token = null;
    try { token = store() ? store().getItem(STORE) : null; } catch (e) { /* storage blocked */ }

    // Earlier builds kept the token in localStorage, where it outlived the
    // browser. Those tokens address nothing any more; do not leave them behind.
    try { window.localStorage.removeItem(STORE); } catch (e) {}

    var since = 0;
    var timer = null;
    var seen = {};

    // ---------------------------------------------------------------- markup

    var host = document.createElement('div');
    host.setAttribute('data-gesoft-live-chat', '');
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    root.innerHTML = [
        '<style>',
        ':host, * { box-sizing: border-box; }',
        '.launcher {',
        '  position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;',
        '  width: 56px; height: 56px; border-radius: 50%; border: 0; cursor: pointer;',
        '  background: #0d5652; color: #fff; font-size: 22px; line-height: 1;',
        '  box-shadow: 0 6px 20px rgba(0,0,0,.28);',
        '}',
        '.launcher:focus-visible { outline: 3px solid #7fd3cb; outline-offset: 2px; }',
        '.panel {',
        '  position: fixed; right: 20px; bottom: 88px; z-index: 2147483000;',
        '  width: 340px; max-width: calc(100vw - 40px); height: 460px; max-height: calc(100vh - 120px);',
        '  display: none; flex-direction: column; overflow: hidden;',
        '  background: #fff; color: #16201f; border-radius: 12px;',
        '  box-shadow: 0 12px 40px rgba(0,0,0,.3);',
        '  font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;',
        '}',
        '.panel.open { display: flex; }',
        '.head { background: #0d5652; color: #fff; padding: 12px 14px; font-weight: 600;',
        '  display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }',
        '.head small { display: block; font-weight: 400; opacity: .8; font-size: 12px; }',
        '.end { flex: none; background: transparent; color: #fff; cursor: pointer;',
        '  border: 1px solid rgba(255,255,255,.55); border-radius: 6px; padding: 2px 9px; font: inherit; font-size: 12px; font-weight: 400; }',
        '.end:hover { background: rgba(255,255,255,.12); }',
        '.end:focus-visible { outline: 2px solid #7fd3cb; outline-offset: 2px; }',
        '.log { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; background: #f4f7f6; }',
        '.msg { max-width: 82%; padding: 8px 11px; border-radius: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }',
        '.msg.visitor { align-self: flex-end; background: #0d5652; color: #fff; border-bottom-right-radius: 3px; }',
        '.msg.agent { align-self: flex-start; background: #fff; border: 1px solid #dde5e3; border-bottom-left-radius: 3px; }',
        '.msg a { color: inherit; text-decoration: underline; }',
        '.msg.agent a { color: #0d5652; }',
        '.note { align-self: center; font-size: 12px; color: #5b696c; text-align: center; }',
        '.form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #e2e8e7; background: #fff; }',
        '.form textarea {',
        '  flex: 1; resize: none; height: 38px; padding: 8px 10px; font: inherit;',
        '  border: 1px solid #cfdad8; border-radius: 8px; color: inherit; background: #fff;',
        '}',
        '.form textarea:focus-visible { outline: 2px solid #0d5652; outline-offset: 0; }',
        '.form button {',
        '  border: 0; border-radius: 8px; padding: 0 14px; cursor: pointer;',
        '  background: #0d5652; color: #fff; font: inherit; font-weight: 600;',
        '}',
        '.form button[disabled] { opacity: .5; cursor: default; }',
        '.intro { display: none; flex-direction: column; gap: 10px; padding: 14px; overflow-y: auto; }',
        '.panel.asking .intro { display: flex; }',
        '.panel.asking .log, .panel.asking .form { display: none; }',
        '.intro-note { margin: 0; color: #5b696c; font-size: 13px; }',
        '.intro label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #5b696c; }',
        '.intro input, .intro textarea {',
        '  font: 14px/1.5 inherit; padding: 8px 10px; border: 1px solid #cfdad8;',
        '  border-radius: 8px; background: #fff; color: #16201f; resize: vertical;',
        '}',
        '.intro input:focus-visible, .intro textarea:focus-visible { outline: 2px solid #0d5652; }',
        '.intro button { border: 0; border-radius: 8px; padding: 10px; cursor: pointer; background: #0d5652; color: #fff; font: inherit; font-weight: 600; }',
        '.intro-error { color: #9c3a2c; font-size: 12px; min-height: 1em; }',
        '@media (prefers-color-scheme: dark) {',
        '  .intro-note, .intro label { color: #93a3a3; }',
        '  .intro input, .intro textarea { background: #0f1516; border-color: #2c3839; color: #e3eae8; }',
        '  .intro-error { color: #d97a67; }',
        '}',
        '@media (prefers-color-scheme: dark) {',
        '  .panel { background: #141b1c; color: #e3eae8; }',
        '  .log { background: #0f1516; }',
        '  .msg.agent { background: #1b2425; border-color: #26312f; }',
        '  .form { background: #141b1c; border-top-color: #26312f; }',
        '  .form textarea { background: #0f1516; border-color: #2c3839; color: #e3eae8; }',
        '  .note { color: #93a3a3; }',
        '}',
        '</style>',
        '<button class="launcher" type="button" aria-label="', esc(TITLE), '">&#128172;</button>',
        '<section class="panel" role="dialog" aria-label="', esc(TITLE), '">',
        '  <div class="head"><div>', esc(TITLE), '<small>Scrieți-ne, vă răspundem imediat.</small></div>',
        '    <button class="end" type="button" hidden>Încheie</button></div>',
        '  <form class="intro">',
        '    <p class="intro-note">Lăsați-ne datele dumneavoastră și vă răspundem imediat.</p>',
        '    <label>Nume<input type="text" name="name" autocomplete="name" required></label>',
        '    <label>Email<input type="email" name="email" autocomplete="email" required></label>',
        '    <label>Mesaj<textarea name="message" rows="3" required></textarea></label>',
        '    <button type="submit">Începeți conversația</button>',
        '    <span class="intro-error" role="alert"></span>',
        '  </form>',
        '  <div class="log" role="log" aria-live="polite"></div>',
        '  <form class="form">',
        '    <textarea rows="1" placeholder="Mesajul dumneavoastră…" aria-label="Mesaj"></textarea>',
        '    <button type="submit">Trimite</button>',
        '  </form>',
        '</section>'
    ].join('');

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var launcher = root.querySelector('.launcher');
    var intro    = root.querySelector('.intro');
    var panel    = root.querySelector('.panel');
    var log      = root.querySelector('.log');
    var form     = root.querySelector('.form');
    var endBtn   = root.querySelector('.end');
    // Scoped to the chat form. The introduction has a textarea of its own that
    // comes first in the markup, and an unscoped lookup found that one: Enter
    // in the chat box did nothing, and Send re-sent the introduction's message.
    var input    = form.querySelector('textarea');
    var sendBtn  = form.querySelector('button');

    // ------------------------------------------------------------- rendering

    // The server sends plain text, and this stays the second place that is true
    // rather than assumed: every piece of the message is written with
    // textContent, including the label of a link. Only the href is built, and
    // only from something that already matched http(s).
    //
    // Links are made clickable because the support link arrives this way and a
    // customer being told to select-and-copy a URL out of a chat bubble is a
    // support call that fails on the last step.
    var URL_RE = /(https?:\/\/[^\s<>"']+)/g;

    function add(from, body) {
        var el = document.createElement('div');
        el.className = 'msg ' + from;

        var parts = String(body).split(URL_RE);
        for (var i = 0; i < parts.length; i++) {
            if (!parts[i]) { continue; }

            if (i % 2 === 1) {
                var a = document.createElement('a');
                a.href = parts[i];
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = parts[i];
                el.appendChild(a);
            } else {
                el.appendChild(document.createTextNode(parts[i]));
            }
        }

        log.appendChild(el);
        log.scrollTop = log.scrollHeight;
    }

    function note(text) {
        var el = document.createElement('div');
        el.className = 'note';
        el.textContent = text;
        log.appendChild(el);
        log.scrollTop = log.scrollHeight;
    }

    // --------------------------------------------------------------- session

    function remember(t) {
        token = t;
        try { if (store()) { store().setItem(STORE, t); } } catch (e) {}
        endBtn.hidden = false;
    }

    // The conversation this tab held is over. The next message starts a new
    // one — through the introduction again, because nothing about the old
    // conversation may carry a visitor into the next.
    function forget() {
        try { if (store()) { store().removeItem(STORE); } } catch (e) {}
        token = null;
        since = 0;
        seen = {};
        endBtn.hidden = true;
    }

    // --------------------------------------------------------------- network

    function post(path, data) {
        return fetch(BASE + '/gesoft-live-chat/' + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json(); });
    }

    function poll() {
        if (!token) { return; }

        fetch(BASE + '/gesoft-live-chat/poll?token=' + encodeURIComponent(token) + '&since=' + since)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.status !== 'success') { return; }

                // Messages first: the agent's last words before closing arrive
                // in the same answer that says the conversation is closed.
                (res.messages || []).forEach(function (m) {
                    if (seen[m.id]) { return; }
                    seen[m.id] = true;
                    add(m.from, m.body);
                });

                if (typeof res.since === 'number') { since = res.since; }

                if (res.closed) {
                    stopPolling();
                    note('Conversația a fost închisă. Scrieți din nou pentru a începe una nouă.');
                    forget();
                }
            })
            .catch(function () { /* a dropped poll is not worth telling anyone */ });
    }

    function startPolling() {
        if (!timer) { timer = setInterval(poll, POLL_MS); }
    }

    function stopPolling() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    // ---------------------------------------------------------------- events

    function asking() {
        return ASK && !token;
    }

    function showChat() {
        panel.classList.remove('asking');
        input.focus();
    }

    launcher.addEventListener('click', function () {
        var open = panel.classList.toggle('open');
        if (!open) { return; }

        if (asking()) {
            panel.classList.add('asking');
            intro.querySelector('[name="name"]').focus();
            return;
        }

        showChat();
        if (token) { poll(); startPolling(); }
    });

    // The introduction. One screen — who you are and what is wrong — rather
    // than a form and then a chat, because two steps before saying anything is
    // where people give up.
    intro.addEventListener('submit', function (e) {
        e.preventDefault();

        var name = intro.querySelector('[name="name"]').value.trim(),
            email = intro.querySelector('[name="email"]').value.trim(),
            message = intro.querySelector('[name="message"]'),
            text = message.value.trim(),
            err = intro.querySelector('.intro-error'),
            button = intro.querySelector('button');

        err.textContent = '';
        if (!text) { return; }

        button.disabled = true;
        post('start', { name: name, email: email, message: text })
            .then(function (res) {
                button.disabled = false;

                if (!res || res.status !== 'success') {
                    err.textContent = (res && res.msg) || 'Nu am putut începe conversația.';
                    return;
                }

                remember(res.token);
                if (typeof res.since === 'number') { since = res.since; }

                // Name and email stay filled in for a next conversation on this
                // page; the message does not, or it would be sent twice.
                message.value = '';

                showChat();
                add('visitor', text);
                startPolling();
            })
            .catch(function () {
                button.disabled = false;
                err.textContent = 'Nu am putut trimite. Verificați conexiunea.';
            });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var text = input.value.trim();
        if (!text) { return; }

        input.value = '';
        sendBtn.disabled = true;
        add('visitor', text);

        var path = token ? 'send' : 'start';
        var payload = { token: token, message: text };

        // No token yet means this is the first message, which happens here only
        // when the host page supplied the identity and the form was skipped.
        if (!token) {
            payload.name = IDENTITY.name || '';
            payload.email = IDENTITY.email || '';
            payload.phone = IDENTITY.phone || '';
        }

        post(path, payload)
            .then(function (res) {
                sendBtn.disabled = false;

                if (!res || res.status !== 'success') {
                    note((res && res.msg) || 'Mesajul nu a putut fi trimis. Încercați din nou.');

                    // The conversation ended between the last poll and this
                    // message. Start a new one with what they just wrote.
                    if (res && res.closed) {
                        stopPolling();
                        forget();
                        if (ASK) {
                            intro.querySelector('[name="message"]').value = text;
                            panel.classList.add('asking');
                        }
                    }
                    return;
                }

                if (res.token) { remember(res.token); }
                if (typeof res.since === 'number' && res.since > since) { since = res.since; }

                startPolling();
            })
            .catch(function () {
                sendBtn.disabled = false;
                note('Nu am putut trimite mesajul. Verificați conexiunea.');
            });
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    // Ending on purpose. The operator is told at once, instead of learning it
    // from silence two minutes later.
    endBtn.addEventListener('click', function () {
        if (!token || !window.confirm('Încheiați conversația?')) { return; }

        var t = token;
        stopPolling();
        forget();
        post('end', { token: t }).catch(function () {});
        note('Ați încheiat conversația. Ne puteți scrie oricând din nou.');
    });

    // The tab is going away. A reload sends this too, a moment before polling
    // again, which is why the server treats it as "maybe gone" until the
    // visitor fails to come back rather than as leaving.
    //
    // text/plain keeps it a simple request: a bubble on another domain gets no
    // preflight, and a beacon could not make one.
    window.addEventListener('pagehide', function () {
        if (!token || !navigator.sendBeacon) { return; }
        try {
            navigator.sendBeacon(
                BASE + '/gesoft-live-chat/leave',
                new Blob([JSON.stringify({ token: token })], { type: 'text/plain' })
            );
        } catch (e) {}
    });

    document.body.appendChild(host);

    // A reload in the same tab picks the conversation back up without clicking.
    if (token) { endBtn.hidden = false; poll(); startPolling(); }
})();
