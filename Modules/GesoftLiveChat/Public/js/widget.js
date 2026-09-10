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
 *           data-title="Asistență Gesoft"
 *           data-lang="ro"></script>
 *
 * `data-base` is the helpdesk's own address and must be set whenever the page
 * is not served from it, which is every real embedding. `data-lang` is `ro` or
 * `en`; without it the bubble follows the page's own language, then the
 * browser's.
 *
 * Like Live Helper Chat's widget it asks first whether anybody is available,
 * and offers a chat or a message form accordingly.
 *
 * Everything the visitor sees is written with textContent. No string from the
 * server, the agent or the page ever becomes markup.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    function attr(name) { return script ? script.getAttribute(name) : null; }

    var WORDS = {
        ro: {
            title: 'Asistență',
            online: 'Suntem online',
            offline: 'Lăsați-ne un mesaj',
            openChat: 'Deschide chatul',
            closeChat: 'Închide chatul',
            close: 'Închide',
            end: 'Încheie',
            endQuestion: 'Încheiați conversația?',
            endYes: 'Da, încheie',
            endNo: 'Nu',
            ended: 'Ați încheiat conversația. Ne puteți scrie oricând din nou.',
            closed: 'Conversația a fost închisă. Scrieți din nou pentru a începe una nouă.',
            introTitle: 'Începeți o conversație',
            introNote: 'Spuneți-ne cine sunteți și cu ce vă putem ajuta.',
            name: 'Nume',
            email: 'Email',
            message: 'Mesaj',
            start: 'Începeți conversația',
            privacy: 'Folosim aceste date doar ca să vă răspundem.',
            offlineTitle: 'Nu suntem online acum',
            offlineNote: 'Lăsați-ne un mesaj și vă răspundem pe email cât de curând.',
            offlineSend: 'Trimiteți mesajul',
            offlineDone: 'Mulțumim! Am primit mesajul și vă răspundem pe email.',
            leaveMessage: 'Lăsați un mesaj',
            placeholder: 'Scrieți un mesaj…',
            send: 'Trimite',
            waiting: 'Mulțumim! Un coleg vă răspunde în curând.',
            nobodyAvailable: 'Nu este niciun coleg disponibil chiar acum. Dacă nu puteți aștepta, lăsați-ne un mesaj și vă răspundem pe email.',
            errEmpty: 'Scrieți mai întâi un mesaj.',
            errEmail: 'Lăsați o adresă de email ca să vă putem răspunde.',
            errTooMany: 'Ați pornit prea multe conversații. Încercați din nou peste câteva minute.',
            errBlocked: 'Chatul nu este disponibil. Ne puteți scrie la {contact}.',
            errBlockedNoContact: 'Chatul nu este disponibil. Vă rugăm să ne contactați altfel.',
            errUnavailable: 'Chatul nu este disponibil momentan.',
            errNetwork: 'Nu am putut trimite. Verificați conexiunea.',
            errGeneric: 'Mesajul nu a putut fi trimis. Încercați din nou.'
        },
        en: {
            title: 'Support',
            online: 'We are online',
            offline: 'Leave us a message',
            openChat: 'Open chat',
            closeChat: 'Close chat',
            close: 'Close',
            end: 'End chat',
            endQuestion: 'End this conversation?',
            endYes: 'Yes, end it',
            endNo: 'No',
            ended: 'You ended the conversation. You can write to us again any time.',
            closed: 'This conversation was closed. Write again to start a new one.',
            introTitle: 'Start a conversation',
            introNote: 'Tell us who you are and how we can help.',
            name: 'Name',
            email: 'Email',
            message: 'Message',
            start: 'Start the conversation',
            privacy: 'We use these details only to answer you.',
            offlineTitle: 'We are not online right now',
            offlineNote: 'Leave us a message and we will answer by email as soon as we can.',
            offlineSend: 'Send the message',
            offlineDone: 'Thank you! We have your message and will answer by email.',
            leaveMessage: 'Leave a message',
            placeholder: 'Write a message…',
            send: 'Send',
            waiting: 'Thank you! Someone will be with you shortly.',
            nobodyAvailable: 'Nobody is available right now. If you cannot wait, leave us a message and we will answer by email.',
            errEmpty: 'Please write a message first.',
            errEmail: 'Please leave an email address so we can answer you.',
            errTooMany: 'You have started too many conversations. Please try again in a few minutes.',
            errBlocked: 'Chat is not available. You can write to us at {contact}.',
            errBlockedNoContact: 'Chat is not available. Please contact us another way.',
            errUnavailable: 'Chat is not available right now.',
            errNetwork: 'Could not send. Please check your connection.',
            errGeneric: 'The message could not be sent. Please try again.'
        }
    };

    function pickLang() {
        var candidates = [attr('data-lang'), document.documentElement.lang, navigator.language];
        for (var i = 0; i < candidates.length; i++) {
            var lang = String(candidates[i] || '').slice(0, 2).toLowerCase();
            if (WORDS[lang]) { return lang; }
        }
        return 'ro';
    }

    var LANG = pickLang();
    var T = WORDS[LANG];
    var BASE = attr('data-base') || '';
    var TITLE = attr('data-title') || T.title;

    // Identity the host page already knows. An application where the customer
    // is signed in should hand it over rather than make them introduce
    // themselves a second time; a plain website leaves this empty and the
    // visitor is asked.
    var IDENTITY = (window.GesoftLiveChat && window.GesoftLiveChat.identity) || {};
    var ASK = attr('data-prechat') !== 'off' && !(IDENTITY.email || IDENTITY.name);
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
    var noticed = {};
    var online = null;
    var unread = 0;

    // ---------------------------------------------------------------- markup

    var host = document.createElement('div');
    host.setAttribute('data-gesoft-live-chat', '');
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var ICON_CHAT = '<svg class="i-chat" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5z" fill="currentColor"/></svg>';
    var ICON_CLOSE = '<svg class="i-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none"/></svg>';
    var ICON_SEND = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l15-7-5 15-2.6-6.2z" fill="currentColor"/></svg>';

    root.innerHTML = [
        '<style>',
        ':host { all: initial; }',
        '* { box-sizing: border-box; }',
        '.wrap {',
        '  --brand: #0d5652; --brand-ink: #ffffff; --brand-soft: #e3f0ee;',
        '  --bg: #ffffff; --ink: #16201f; --muted: #5b696c; --line: #e2e8e7; --log: #f4f7f6;',
        '  --agent: #ffffff; --agent-line: #dde5e3; --danger: #9c3a2c; --ok: #2e9b5f;',
        '  font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink);',
        '}',
        '@media (prefers-color-scheme: dark) {',
        '  .wrap { --bg: #141b1c; --ink: #e3eae8; --muted: #93a3a3; --line: #26312f; --log: #0f1516;',
        '    --agent: #1b2425; --agent-line: #26312f; --brand-soft: #16302d; --danger: #e08a78; }',
        '}',
        '.launcher {',
        '  position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;',
        '  width: 58px; height: 58px; border-radius: 50%; border: 0; cursor: pointer;',
        '  background: var(--brand); color: var(--brand-ink);',
        '  box-shadow: 0 8px 24px rgba(0,0,0,.25); display: grid; place-items: center;',
        '  transition: transform .15s ease;',
        '}',
        '.launcher:hover { transform: scale(1.05); }',
        '.launcher:focus-visible { outline: 3px solid #7fd3cb; outline-offset: 3px; }',
        '.launcher svg { width: 26px; height: 26px; grid-area: 1 / 1; }',
        '.launcher .i-close { display: none; }',
        '.launcher[aria-expanded="true"] .i-chat { display: none; }',
        '.launcher[aria-expanded="true"] .i-close { display: block; }',
        '.badge {',
        '  position: absolute; top: -2px; right: -2px; min-width: 20px; height: 20px; padding: 0 6px;',
        '  border-radius: 10px; background: #c0392b; color: #fff; font-size: 12px; font-weight: 700;',
        '  line-height: 20px; text-align: center; border: 2px solid #fff;',
        '}',
        '.panel {',
        '  position: fixed; right: 20px; bottom: 92px; z-index: 2147483000;',
        '  width: 370px; max-width: calc(100vw - 40px); height: 560px; max-height: calc(100vh - 120px);',
        '  display: none; flex-direction: column; overflow: hidden;',
        '  background: var(--bg); border-radius: 16px; border: 1px solid var(--line);',
        '  box-shadow: 0 16px 48px rgba(0,0,0,.28);',
        '}',
        '.panel.open { display: flex; }',
        '@media (max-width: 480px) {',
        '  .panel { right: 0; bottom: 0; width: 100vw; max-width: 100vw; height: 100%; max-height: 100%; border-radius: 0; border: 0; }',
        '  .launcher[aria-expanded="true"] { display: none; }',
        '}',
        '.head { background: var(--brand); color: var(--brand-ink); padding: 14px 12px 14px 16px; display: flex; align-items: center; gap: 8px; }',
        '.who { flex: 1; min-width: 0; }',
        '.title { font-weight: 650; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }',
        '.status { display: flex; align-items: center; gap: 6px; font-size: 12px; opacity: .9; }',
        '.dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,.55); flex: none; }',
        '.dot.on { background: #6ee7a8; box-shadow: 0 0 0 3px rgba(110,231,168,.25); }',
        '.head button { font: inherit; color: var(--brand-ink); background: transparent; cursor: pointer; }',
        '.end { border: 1px solid rgba(255,255,255,.5); border-radius: 8px; padding: 4px 10px; font-size: 12px !important; }',
        '.end:hover, .x:hover { background: rgba(255,255,255,.14); }',
        '.x { border: 0; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; }',
        '.x svg { width: 18px; height: 18px; }',
        '.head button:focus-visible { outline: 2px solid #7fd3cb; outline-offset: 2px; }',
        '.confirm { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 14px; background: var(--brand-soft); border-bottom: 1px solid var(--line); font-size: 13px; }',
        '.confirm span { flex: 1; font-weight: 600; }',
        '.btn { font: inherit; font-weight: 600; border-radius: 8px; padding: 8px 14px; cursor: pointer; border: 1px solid transparent; }',
        '.btn.primary { background: var(--brand); color: var(--brand-ink); }',
        '.btn.plain { background: transparent; color: var(--ink); border-color: var(--line); }',
        '.btn.small { padding: 5px 10px; font-size: 12px; }',
        '.btn[disabled] { opacity: .55; cursor: default; }',
        '.btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }',
        '.screen { flex: 1; overflow-y: auto; padding: 16px 18px 14px; display: flex; flex-direction: column; gap: 10px; }',
        '.screen h2 { margin: 0; font-size: 17px; line-height: 1.3; }',
        '.screen p { margin: 0; color: var(--muted); }',
        '.field { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 600; color: var(--muted); }',
        '.field input, .field textarea {',
        '  font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg);',
        '  padding: 9px 11px; border: 1px solid var(--line); border-radius: 10px; resize: vertical; width: 100%;',
        '}',
        '.field input:focus, .field textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(13,86,82,.18); }',
        '.screen .btn.primary { width: 100%; padding: 11px; }',
        '.small-print { font-size: 12px; color: var(--muted); }',
        '.error { color: var(--danger); font-size: 13px; }',
        '.error:empty { display: none; }',
        '.done { align-items: center; justify-content: center; text-align: center; }',
        '.done .check { width: 48px; height: 48px; border-radius: 50%; background: var(--brand-soft); color: var(--brand); display: grid; place-items: center; font-size: 24px; }',
        '.hp { position: absolute !important; left: -10000px !important; width: 1px; height: 1px; overflow: hidden; }',
        '.log { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: var(--log); }',
        '.row { display: flex; flex-direction: column; max-width: 84%; }',
        '.row.visitor { align-self: flex-end; align-items: flex-end; }',
        '.row.agent { align-self: flex-start; align-items: flex-start; }',
        '.author { font-size: 11px; font-weight: 600; color: var(--muted); margin: 0 4px 2px; }',
        '.msg { padding: 9px 12px; border-radius: 14px; white-space: pre-wrap; overflow-wrap: anywhere; }',
        '.row.visitor .msg { background: var(--brand); color: var(--brand-ink); border-bottom-right-radius: 4px; }',
        '.row.agent .msg { background: var(--agent); border: 1px solid var(--agent-line); border-bottom-left-radius: 4px; }',
        '.msg a { color: inherit; text-decoration: underline; }',
        '.row.agent .msg a { color: var(--brand); }',
        '@media (prefers-color-scheme: dark) { .row.agent .msg a { color: #7fd3cb; } }',
        '.time { font-size: 10px; color: var(--muted); margin: 2px 4px 0; }',
        '.note { align-self: center; max-width: 92%; text-align: center; font-size: 12px; color: var(--muted);',
        '  background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 7px 12px; }',
        '.note .btn { margin-top: 6px; }',
        '.form { display: flex; align-items: flex-end; gap: 8px; padding: 10px; border-top: 1px solid var(--line); background: var(--bg); }',
        '.form textarea {',
        '  flex: 1; resize: none; min-height: 40px; max-height: 120px; padding: 9px 12px;',
        '  font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg);',
        '  border: 1px solid var(--line); border-radius: 12px;',
        '}',
        '.form textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(13,86,82,.18); }',
        '.form .send { width: 40px; height: 40px; border-radius: 12px; border: 0; background: var(--brand); color: var(--brand-ink); cursor: pointer; display: grid; place-items: center; flex: none; }',
        '.form .send svg { width: 18px; height: 18px; }',
        '.form .send[disabled] { opacity: .5; cursor: default; }',
        '[hidden] { display: none !important; }',
        '@media (prefers-reduced-motion: reduce) { .launcher { transition: none; } }',
        '</style>',
        '<div class="wrap">',
        '<button class="launcher" type="button" aria-expanded="false">' + ICON_CHAT + ICON_CLOSE + '<span class="badge" hidden></span></button>',
        '<section class="panel" role="dialog">',
        '  <header class="head">',
        '    <div class="who"><div class="title"></div><div class="status"><span class="dot"></span><span class="status-text"></span></div></div>',
        '    <button class="end" type="button" hidden></button>',
        '    <button class="x" type="button">' + ICON_CLOSE + '</button>',
        '  </header>',
        '  <div class="confirm" hidden><span class="confirm-text"></span>',
        '    <button class="btn primary small confirm-yes" type="button"></button>',
        '    <button class="btn plain small confirm-no" type="button"></button></div>',
        '  <form class="screen intro" hidden novalidate>',
        '    <h2 class="t-intro-title"></h2><p class="t-intro-note"></p>',
        '    <label class="field"><span class="t-name"></span><input type="text" name="name" autocomplete="name" maxlength="40"></label>',
        '    <label class="field"><span class="t-email"></span><input type="email" name="email" autocomplete="email" maxlength="191"></label>',
        '    <label class="field"><span class="t-message"></span><textarea name="message" rows="3" maxlength="4000"></textarea></label>',
        '    <div class="hp" aria-hidden="true"><label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>',
        '    <button class="btn primary" type="submit"></button>',
        '    <span class="error" role="alert"></span>',
        '    <span class="small-print t-privacy"></span>',
        '  </form>',
        '  <form class="screen offline" hidden novalidate>',
        '    <h2 class="t-offline-title"></h2><p class="t-offline-note"></p>',
        '    <label class="field"><span class="t-name"></span><input type="text" name="name" autocomplete="name" maxlength="40"></label>',
        '    <label class="field"><span class="t-email"></span><input type="email" name="email" autocomplete="email" maxlength="191"></label>',
        '    <label class="field"><span class="t-message"></span><textarea name="message" rows="3" maxlength="4000"></textarea></label>',
        '    <div class="hp" aria-hidden="true"><label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>',
        '    <button class="btn primary" type="submit"></button>',
        '    <span class="error" role="alert"></span>',
        '    <span class="small-print t-privacy"></span>',
        '  </form>',
        '  <div class="screen done" hidden><div class="check" aria-hidden="true">&#10003;</div><p class="t-done"></p></div>',
        '  <div class="log" role="log" aria-live="polite" hidden></div>',
        '  <form class="form" hidden>',
        '    <textarea rows="1" maxlength="4000"></textarea>',
        '    <button class="send" type="submit">' + ICON_SEND + '</button>',
        '  </form>',
        '</section>',
        '</div>'
    ].join('');

    function $(selector) { return root.querySelector(selector); }
    function $all(selector) { return root.querySelectorAll(selector); }

    var launcher = $('.launcher');
    var badge    = $('.badge');
    var panel    = $('.panel');
    var intro    = $('.intro');
    var offline  = $('.offline');
    var done     = $('.done');
    var log      = $('.log');
    var form     = $('.form');
    var endBtn   = $('.end');
    var confirm  = $('.confirm');
    var dot      = $('.dot');
    // Scoped to the chat form. The introduction has a textarea of its own that
    // comes first in the markup, and an unscoped lookup found that one: Enter
    // in the chat box did nothing, and Send re-sent the introduction's message.
    var input    = form.querySelector('textarea');
    var sendBtn  = form.querySelector('button');

    // Every fixed word goes in as text.
    function words() {
        $('.title').textContent = TITLE;
        panel.setAttribute('aria-label', TITLE);
        launcher.setAttribute('aria-label', T.openChat);
        endBtn.textContent = T.end;
        $('.x').setAttribute('aria-label', T.close);
        $('.confirm-text').textContent = T.endQuestion;
        $('.confirm-yes').textContent = T.endYes;
        $('.confirm-no').textContent = T.endNo;
        $('.t-intro-title').textContent = T.introTitle;
        $('.t-intro-note').textContent = T.introNote;
        $('.t-offline-title').textContent = T.offlineTitle;
        $('.t-offline-note').textContent = T.offlineNote;
        $('.t-done').textContent = T.offlineDone;
        Array.prototype.forEach.call($all('.t-name'), function (el) { el.textContent = T.name; });
        Array.prototype.forEach.call($all('.t-email'), function (el) { el.textContent = T.email; });
        Array.prototype.forEach.call($all('.t-message'), function (el) { el.textContent = T.message; });
        Array.prototype.forEach.call($all('.t-privacy'), function (el) { el.textContent = T.privacy; });
        intro.querySelector('button[type=submit]').textContent = T.start;
        offline.querySelector('button[type=submit]').textContent = T.offlineSend;
        input.setAttribute('placeholder', T.placeholder);
        input.setAttribute('aria-label', T.message);
        sendBtn.setAttribute('aria-label', T.send);
        paintStatus();
    }

    function paintStatus() {
        var on = online !== false;
        dot.className = 'dot' + (on ? ' on' : '');
        $('.status-text').textContent = on ? T.online : T.offline;
    }

    // ---------------------------------------------------------------- screens

    // chat | intro | offline | done. `data-mode` on the panel says which is
    // showing, which is also what the browser tests read.
    function show(mode) {
        panel.setAttribute('data-mode', mode);
        intro.hidden = mode !== 'intro';
        offline.hidden = mode !== 'offline';
        done.hidden = mode !== 'done';
        log.hidden = mode !== 'chat';
        form.hidden = mode !== 'chat';
        confirm.hidden = true;

        var focus = mode === 'intro' ? intro.querySelector('[name=name]')
            : mode === 'offline' ? offline.querySelector('[name=name]')
            : mode === 'chat' ? input : null;
        if (focus && panel.classList.contains('open')) {
            try { focus.focus(); } catch (e) {}
        }
    }

    function copyIdentity(from, to) {
        ['name', 'email'].forEach(function (field) {
            var target = to.querySelector('[name=' + field + ']');
            if (target && !target.value) { target.value = from.querySelector('[name=' + field + ']').value; }
        });
    }

    // ------------------------------------------------------------- rendering

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    function clock(iso) {
        try {
            var date = iso ? new Date(iso) : new Date();
            return date.toLocaleTimeString(LANG === 'ro' ? 'ro-RO' : 'en-GB', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    // The server sends plain text, and this stays the second place that is true
    // rather than assumed: every piece of the message is written with
    // textContent, including the label of a link. Only the href is built, and
    // only from something that already matched http(s).
    //
    // Links are made clickable because the support link arrives this way and a
    // customer being told to select-and-copy a URL out of a chat bubble is a
    // support call that fails on the last step.
    var URL_RE = /(https?:\/\/[^\s<>"']+)/g;

    function add(from, body, author, at) {
        var row = el('div', 'row ' + from);
        if (from === 'agent' && author) { row.appendChild(el('div', 'author', author)); }

        var bubble = el('div', 'msg');
        var parts = String(body).split(URL_RE);
        for (var i = 0; i < parts.length; i++) {
            if (!parts[i]) { continue; }
            if (i % 2 === 1) {
                var a = el('a', null, parts[i]);
                a.href = parts[i];
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                bubble.appendChild(a);
            } else {
                bubble.appendChild(document.createTextNode(parts[i]));
            }
        }
        row.appendChild(bubble);
        row.appendChild(el('div', 'time', clock(at)));

        log.appendChild(row);
        log.scrollTop = log.scrollHeight;

        if (from === 'agent' && !panel.classList.contains('open')) {
            unread++;
            badge.textContent = String(unread);
            badge.hidden = false;
        }
    }

    function note(text, action, onAction) {
        var box = el('div', 'note', text);
        if (action) {
            box.appendChild(document.createElement('br'));
            var button = el('button', 'btn plain small', action);
            button.type = 'button';
            button.addEventListener('click', onAction);
            box.appendChild(button);
        }
        log.appendChild(box);
        log.scrollTop = log.scrollHeight;
    }

    function explain(res) {
        switch (res && res.code) {
            case 'empty': return T.errEmpty;
            case 'email_required': return T.errEmail;
            case 'too_many': return T.errTooMany;
            case 'blocked': return res.contact ? T.errBlocked.replace('{contact}', res.contact) : T.errBlockedNoContact;
            case 'closed': return T.closed;
            case 'unavailable': return T.errUnavailable;
        }
        return T.errGeneric;
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
        confirm.hidden = true;
    }

    // --------------------------------------------------------------- network

    function post(path, data) {
        data.lang = LANG;
        return fetch(BASE + '/gesoft-live-chat/' + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (r) { return r.json(); });
    }

    function checkStatus() {
        return fetch(BASE + '/gesoft-live-chat/status')
            .then(function (r) { return r.json(); })
            .then(function (res) { online = !!(res && res.online); paintStatus(); return online; })
            // Not knowing is not the same as nobody being there: offer the chat.
            .catch(function () { online = true; paintStatus(); return online; });
    }

    function poll() {
        if (!token) { return; }

        fetch(BASE + '/gesoft-live-chat/poll?token=' + encodeURIComponent(token) + '&since=' + since + '&lang=' + LANG)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.status !== 'success') { return; }

                // Messages first: the agent's last words before closing arrive
                // in the same answer that says the conversation is closed.
                (res.messages || []).forEach(function (m) {
                    if (seen[m.id]) { return; }
                    seen[m.id] = true;
                    add(m.from, m.body, m.author, m.at);
                });

                if (typeof res.since === 'number') { since = res.since; }

                if (res.notice && !noticed[token + res.notice]) {
                    noticed[token + res.notice] = true;
                    if (res.notice === 'nobody_available') {
                        note(T.nobodyAvailable, T.leaveMessage, function () {
                            copyIdentity(intro, offline);
                            show('offline');
                        });
                    } else {
                        note(T.waiting);
                    }
                }

                if (res.closed) {
                    stopPolling();
                    note(T.closed);
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

    function open() {
        panel.classList.add('open');
        launcher.setAttribute('aria-expanded', 'true');
        launcher.setAttribute('aria-label', T.closeChat);
        unread = 0;
        badge.hidden = true;

        if (token) {
            show('chat');
            poll();
            startPolling();
            return;
        }

        var current = panel.getAttribute('data-mode');
        if (current === 'done' || current === 'chat' && log.childNodes.length) {
            show(current);
            return;
        }

        checkStatus().then(function (available) {
            if (!available) { show('offline'); return; }
            show(ASK ? 'intro' : 'chat');
        });
    }

    function close() {
        panel.classList.remove('open');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-label', T.openChat);
    }

    launcher.addEventListener('click', function () {
        if (panel.classList.contains('open')) { close(); } else { open(); }
    });
    $('.x').addEventListener('click', close);
    root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) { close(); launcher.focus(); }
    });

    // The introduction. One screen — who you are and what is wrong — rather
    // than a form and then a chat, because two steps before saying anything is
    // where people give up.
    intro.addEventListener('submit', function (e) {
        e.preventDefault();

        var message = intro.querySelector('[name=message]'),
            text = message.value.trim(),
            error = intro.querySelector('.error'),
            button = intro.querySelector('button[type=submit]');

        error.textContent = '';
        if (!text) { error.textContent = T.errEmpty; return; }

        button.disabled = true;
        post('start', {
            name: intro.querySelector('[name=name]').value.trim(),
            email: intro.querySelector('[name=email]').value.trim(),
            message: text,
            company: intro.querySelector('[name=company]').value
        }).then(function (res) {
            button.disabled = false;

            if (!res || res.status !== 'success') {
                error.textContent = explain(res);
                return;
            }

            remember(res.token);
            if (typeof res.since === 'number') { since = res.since; }

            // Name and email stay filled in for a next conversation on this
            // page; the message does not, or it would be sent twice.
            message.value = '';

            show('chat');
            add('visitor', text, null, null);
            startPolling();
        }).catch(function () {
            button.disabled = false;
            error.textContent = T.errNetwork;
        });
    });

    // The message form, for when nobody is available. It becomes an email
    // conversation, so an email address is required.
    offline.addEventListener('submit', function (e) {
        e.preventDefault();

        var message = offline.querySelector('[name=message]'),
            email = offline.querySelector('[name=email]').value.trim(),
            text = message.value.trim(),
            error = offline.querySelector('.error'),
            button = offline.querySelector('button[type=submit]');

        error.textContent = '';
        if (!text) { error.textContent = T.errEmpty; return; }
        if (!email) { error.textContent = T.errEmail; return; }

        button.disabled = true;
        post('offline', {
            name: offline.querySelector('[name=name]').value.trim(),
            email: email,
            message: text,
            company: offline.querySelector('[name=company]').value
        }).then(function (res) {
            button.disabled = false;
            if (!res || res.status !== 'success') {
                error.textContent = explain(res);
                return;
            }
            message.value = '';
            stopPolling();
            forget();
            show('done');
        }).catch(function () {
            button.disabled = false;
            error.textContent = T.errNetwork;
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var text = input.value.trim();
        if (!text) { return; }

        // The conversation in this tab is over and the visitor has to say who
        // they are again. Carry what they just wrote into the introduction
        // rather than sending a first message with no name and no address.
        if (!token && ASK) {
            intro.querySelector('[name=message]').value = text;
            input.value = '';
            show('intro');
            return;
        }

        input.value = '';
        grow();
        sendBtn.disabled = true;
        add('visitor', text, null, null);

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
                    note(explain(res));

                    // The conversation ended between the last poll and this
                    // message. Start a new one with what they just wrote.
                    if (res && res.closed) {
                        stopPolling();
                        forget();
                        if (ASK) {
                            intro.querySelector('[name=message]').value = text;
                            show('intro');
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
                note(T.errNetwork);
            });
    });

    function grow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    input.addEventListener('input', grow);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    // Ending on purpose, confirmed inside the bubble rather than with the
    // browser's own dialog, which some sites and browsers suppress. The
    // operator is told at once instead of learning it from silence.
    endBtn.addEventListener('click', function () {
        if (!token) { return; }
        confirm.hidden = false;
        $('.confirm-no').focus();
    });
    $('.confirm-no').addEventListener('click', function () { confirm.hidden = true; input.focus(); });
    $('.confirm-yes').addEventListener('click', function () {
        if (!token) { confirm.hidden = true; return; }

        var t = token;
        stopPolling();
        forget();
        post('end', { token: t }).catch(function () {});
        note(T.ended);
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

    words();
    document.body.appendChild(host);

    // A reload in the same tab picks the conversation back up without clicking.
    if (token) {
        endBtn.hidden = false;
        show('chat');
        poll();
        startPolling();
    }
})();
