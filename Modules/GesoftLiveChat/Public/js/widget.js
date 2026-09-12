/**
 * The visitor's chat bubble.
 *
 * One script tag, no dependencies, no iframe. The whole widget lives in a
 * shadow root so a customer's own stylesheet cannot reach into it and ours
 * cannot leak out — and so that embedding it never becomes a negotiation about
 * CSS specificity on somebody else's site.
 *
 * An iframe is the wrong shape for *this* job: a bubble on a customer's own
 * website is a guest that has to sit over their layout, and framing it would
 * mean core's `X-Frame-Options: SAMEORIGIN` had to be weakened for the whole
 * helpdesk, operator interface included, to gain a bubble.
 *
 * Inside one of our own applications it is the right shape, and the trade goes
 * the other way: there the script would be the guest -- with that application's
 * DOM, cookies and session -- and a frame has none of those. So the same script
 * also runs in `data-display="embed"` mode, served from `/chat/embed` into a
 * frame, talking to the page around it only through `postMessage` and only with
 * the origin the helpdesk has registered. The framing rule is stated on that
 * one route in CSP `frame-ancestors`; nothing else about this helpdesk moves.
 *
 * Configure by putting the attributes on the script tag:
 *
 *   <script src="https://helpdesk.example.com/modules/gesoftlivechat/js/widget.js"
 *           data-base="https://helpdesk.example.com"
 *           data-title="Asistență"
 *           data-color="#0d5652"
 *           data-theme="light"
 *           data-lang="ro"></script>
 *
 * `data-base` is the helpdesk's own address and must be set whenever the page
 * is not served from it, which is every real embedding. `data-lang` is `ro` or
 * `en`; without it the bubble follows the page's own language, then the
 * browser's. `data-color` is the site's colour as `#rrggbb`; `data-theme`
 * (`light` or `dark`) stops the bubble following the visitor's system; and
 * `data-stylesheet` adds a stylesheet of the site's inside the bubble.
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
            typing: 'Un coleg scrie…',
            typingNamed: '{name} scrie…',
            sending: 'Se trimite…',
            sent: 'Trimis',
            delivered: 'Primit',
            seen: 'Văzut',
            errEmpty: 'Scrieți mai întâi un mesaj.',
            errEmail: 'Lăsați o adresă de email ca să vă putem răspunde.',
            errTooMany: 'Ați pornit prea multe conversații. Încercați din nou peste câteva minute.',
            errTooFast: 'Trimiteți mesaje prea des. Așteptați câteva secunde.',
            errBlocked: 'Chatul nu este disponibil. Ne puteți scrie la {contact}.',
            errBlockedNoContact: 'Chatul nu este disponibil. Vă rugăm să ne contactați altfel.',
            errUnavailable: 'Chatul nu este disponibil momentan.',
            history: 'Conversațiile mele',
            historyBack: 'Înapoi',
            historyEmpty: 'Nu aveți încă nicio conversație.',
            historyMore: 'Sunt afișate cele mai recente conversații.',
            historyNew: 'Conversație nouă',
            historyOpen: 'Deschide conversația',
            stActive: 'Deschis',
            stPending: 'Așteaptă răspunsul dvs.',
            stClosed: 'Rezolvat',
            reopenNote: 'Conversația a fost redeschisă.',
            errHistory: 'Nu am putut încărca conversațiile.',
            sourceTitle: 'Codul sursă al acestui helpdesk (AGPL-3.0)',
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
            typing: 'Someone is typing…',
            typingNamed: '{name} is typing…',
            sending: 'Sending…',
            sent: 'Sent',
            delivered: 'Delivered',
            seen: 'Seen',
            errEmpty: 'Please write a message first.',
            errEmail: 'Please leave an email address so we can answer you.',
            errTooMany: 'You have started too many conversations. Please try again in a few minutes.',
            errTooFast: 'You are sending messages too quickly. Please wait a few seconds.',
            errBlocked: 'Chat is not available. You can write to us at {contact}.',
            errBlockedNoContact: 'Chat is not available. Please contact us another way.',
            errUnavailable: 'Chat is not available right now.',
            history: 'My conversations',
            historyBack: 'Back',
            historyEmpty: 'You have no conversations yet.',
            historyMore: 'Showing the most recent conversations.',
            historyNew: 'New conversation',
            historyOpen: 'Open conversation',
            stActive: 'Open',
            stPending: 'Waiting for your reply',
            stClosed: 'Resolved',
            reopenNote: 'This conversation has been reopened.',
            errHistory: 'The conversations could not be loaded.',
            sourceTitle: 'The source code of this helpdesk (AGPL-3.0)',
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

    // data-color="#rrggbb": the site's own colour for the launcher, the header
    // and the visitor's messages. Checked as a colour before it goes anywhere
    // near the stylesheet; anything else keeps the bubble's own.
    var COLOR = /^#[0-9a-f]{6}$/i.test(attr('data-color') || '') ? attr('data-color') : '';

    // data-theme="light" or "dark" fixes the bubble's scheme; without it the
    // bubble follows the visitor's system. A site whose own pages are light
    // wants a light bubble even on a machine set to dark.
    var THEME = attr('data-theme') === 'light' || attr('data-theme') === 'dark' ? attr('data-theme') : '';
    function dark(css) {
        if (THEME === 'light') { return ''; }
        return THEME === 'dark' ? css : '@media (prefers-color-scheme: dark) { ' + css + ' }';
    }

    // data-stylesheet: one stylesheet of the site's, loaded inside the bubble
    // after its own, for what a colour cannot say: shapes, sizes, the
    // neutrals. A path starting with / is on the helpdesk, like data-base.
    var SHEET = (function (href) {
        if (/^https?:\/\//i.test(href)) { return href; }
        if (/^\/[^\/]/.test(href)) { return (attr('data-base') || '') + href; }
        return '';
    })(attr('data-stylesheet') || '');

    // data-display="page": the chat fills the window and is open from the
    // start, with no launcher and nothing to close — for the /chat page, which
    // is linked to rather than embedded.
    var PAGE = attr('data-display') === 'page';

    // data-display="embed": the chat inside a frame on a page of one of our own
    // applications, where the person is already signed in. Open and filling the
    // frame like the page above, but it never asks who anybody is and never
    // keeps a token: identity is settled between that application's server and
    // this one, and arrives here as a permission the page around us hands over.
    var EMBED = attr('data-display') === 'embed';

    // The one origin this page may be framed by, and the only address it will
    // ever post a message to. The helpdesk names it from its own register --
    // `?o=` is checked there, not believed -- and it is checked again here
    // before it is used as a target. Never `*`, and never the referrer: either
    // of those would be the whole protection, gone.
    var PARENT = (function (origin) {
        return EMBED && /^https?:\/\/[A-Za-z0-9.-]{1,253}(:\d{1,5})?$/.test(origin) ? origin : '';
    })(attr('data-embed') || '');

    // data-source: where this instance's source lives, offered at the foot of
    // the chat window on the /chat page. The AGPL asks that people who use the
    // software over a network be offered it, and that page has no FreeScout
    // footer to make the offer in. The helpdesk composes the address; this
    // checks it again before writing it into an href, because the bubble also
    // runs on sites we do not serve, where the attribute is the site's to set.
    var SOURCE = (function (href) {
        return /^https?:\/\/[^\s"'<>]+$/i.test(href) ? href : '';
    })(attr('data-source') || '');

    // Identity the host page already knows. An application where the customer
    // is signed in should hand it over rather than make them introduce
    // themselves a second time; a plain website leaves this empty and the
    // visitor is asked.
    var IDENTITY = (window.GesoftLiveChat && window.GesoftLiveChat.identity) || {};
    // Nobody is asked to introduce themselves in a frame inside an application
    // they are signed in to -- and nothing they typed there would be believed
    // anyway, because the identity came from that application's server.
    var ASK = !EMBED && attr('data-prechat') !== 'off' && !(IDENTITY.email || IDENTITY.name);
    var STORE = 'gesoft-live-chat-token';
    // How often the bubble asks for news: often while the conversation is
    // alive and on screen, rarely when it is quiet or the tab is hidden. See
    // nextDelay().
    var FAST_MS = 1500;
    var QUIET_MS = 5000;
    var HIDDEN_MS = 30000;
    var ACTIVE_FOR_MS = 120000;
    // How long after the last keystroke the visitor still counts as typing.
    var TYPING_MS = 4000;

    // The tab, not the browser. sessionStorage survives a reload and moving
    // between pages of the same site in the same tab, and dies with the tab.
    // A new tab is a new conversation, and a closed browser keeps nothing —
    // which is the point on a shared computer, where the next person to open
    // the site must not find the last person's chat waiting for them.
    function store() {
        // Embedded panels keep nothing. The application asks its own server for
        // a fresh permission every time a page opens the panel, and the chat
        // this identity is in is looked up there -- so a conversation survives
        // moving between pages without a single credential being left behind in
        // the browser for whoever signs in at this desk next.
        if (EMBED) { return null; }

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
    var typedAt = 0;
    var lastPollAt = 0;
    var lastActivity = 0;
    var polling = false;
    var inFlight = false;
    var timerDue = 0;
    // How far the agents have got with the visitor's messages, from the last
    // poll; null when the server does not keep receipts.
    var receipts = { delivered: 0, seen: 0, seen_at: null };

    // ---------------------------------------------------------------- parent

    // The permission this panel is acting on, handed over by the page around
    // us after its own server asked the helpdesk for it. Held in this closure
    // and nowhere else: not in storage, not in the URL, not in the DOM.
    var appToken = null;
    // Whether a message left outside our hours could be answered at all, which
    // depends on the application having told us an address. The panel has no
    // form to collect one in, so it asks rather than offers and then fails.
    var canLeaveMessage = false;
    // Which conversation the panel is showing, when it is not the live chat:
    // the id of one opened from the history, or null. It decides where a
    // message goes, so it is the one piece of state here that must never be
    // guessed at.
    var viewing = null;
    var readOnly = false;
    // Whether this person has any conversations at all, and how many answers
    // they have not read -- both from the server, both across every
    // conversation, and neither one this page's to invent.
    var hasHistory = false;
    var unreadTotal = 0;

    // Everything this page says to the application it is embedded in. One
    // target origin, named by the helpdesk; if there is none, we say nothing.
    function toParent(type, value) {
        if (!PARENT) { return; }

        var message = { type: type };
        if (value !== undefined) { message.value = value; }

        try { window.parent.postMessage(message, PARENT); } catch (e) {}
    }

    // ---------------------------------------------------------------- markup

    var host = document.createElement('div');
    host.setAttribute('data-gesoft-live-chat', '');
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var ICON_CHAT = '<svg class="i-chat" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5z" fill="currentColor"/></svg>';
    var ICON_CLOSE = '<svg class="i-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none"/></svg>';
    var ICON_SEND = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l15-7-5 15-2.6-6.2z" fill="currentColor"/></svg>';
    var ICON_LIST = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>';
    var ICON_BACK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6l-6 6 6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>';

    root.innerHTML = [
        '<style>',
        ':host { all: initial; }',
        '* { box-sizing: border-box; }',
        '.wrap {',
        '  --brand: #0d5652; --brand-ink: #ffffff; --brand-soft: #e3f0ee; --brand-light: #7fd3cb; --brand-ring: rgba(13,86,82,.18);',
        '  --bg: #ffffff; --ink: #16201f; --muted: #5b696c; --line: #e2e8e7; --log: #f4f7f6;',
        '  --agent: #ffffff; --agent-line: #dde5e3; --danger: #9c3a2c; --ok: #2e9b5f;',
        '  font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink);',
        '}',
        dark('.wrap { --bg: #141b1c; --ink: #e3eae8; --muted: #93a3a3; --line: #26312f; --log: #0f1516;' +
            ' --agent: #1b2425; --agent-line: #26312f; --brand-soft: #16302d; --danger: #e08a78; }'),
        COLOR ? '.wrap { --brand: ' + COLOR + '; --brand-soft: color-mix(in srgb, ' + COLOR + ' 12%, #ffffff);' +
            ' --brand-light: color-mix(in srgb, ' + COLOR + ' 45%, #ffffff); --brand-ring: color-mix(in srgb, ' + COLOR + ' 20%, transparent); }' +
            dark('.wrap { --brand-soft: color-mix(in srgb, ' + COLOR + ' 25%, #141b1c); }') : '',
        '.launcher {',
        '  position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;',
        '  width: 58px; height: 58px; border-radius: 50%; border: 0; cursor: pointer;',
        '  background: var(--brand); color: var(--brand-ink);',
        '  box-shadow: 0 8px 24px rgba(0,0,0,.25); display: grid; place-items: center;',
        '  transition: transform .15s ease;',
        '}',
        '.launcher:hover { transform: scale(1.05); }',
        '.launcher:focus-visible { outline: 3px solid var(--brand-light); outline-offset: 3px; }',
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
        '.head button:focus-visible { outline: 2px solid var(--brand-light); outline-offset: 2px; }',
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
        '.field input:focus, .field textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }',
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
        dark('.row.agent .msg a { color: var(--brand-light); }'),
        '.time { font-size: 10px; color: var(--muted); margin: 2px 4px 0; }',
        '.receipt { margin-left: 5px; font-weight: 700; }',
        '.receipt:empty { display: none; }',
        '.receipt.seen { color: var(--brand); }',
        dark('.receipt.seen { color: var(--brand-light); }'),
        '.row.pending .msg { opacity: .65; }',
        '.note { align-self: center; max-width: 92%; text-align: center; font-size: 12px; color: var(--muted);',
        '  background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 7px 12px; }',
        '.note .btn { margin-top: 6px; }',
        '.typing { display: flex; align-items: center; gap: 8px; padding: 0 14px 10px; font-size: 12px; color: var(--muted); background: var(--log); }',
        '.dots { display: inline-flex; gap: 4px; padding: 9px 11px; border-radius: 14px; border-bottom-left-radius: 4px; background: var(--agent); border: 1px solid var(--agent-line); }',
        '.dots i { width: 6px; height: 6px; border-radius: 50%; background: var(--muted); animation: typing 1.2s infinite ease-in-out; }',
        '.dots i:nth-child(2) { animation-delay: .15s; }',
        '.dots i:nth-child(3) { animation-delay: .3s; }',
        '@keyframes typing { 0%, 80%, 100% { opacity: .3; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-2px); } }',
        '@media (prefers-reduced-motion: reduce) { .dots i { animation: none; opacity: .6; } }',
        '.form { display: flex; align-items: flex-end; gap: 8px; padding: 10px; border-top: 1px solid var(--line); background: var(--bg); }',
        '.form textarea {',
        '  flex: 1; resize: none; min-height: 40px; max-height: 120px; padding: 9px 12px;',
        '  font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg);',
        '  border: 1px solid var(--line); border-radius: 12px;',
        '}',
        '.form textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }',
        '.form .send { width: 40px; height: 40px; border-radius: 12px; border: 0; background: var(--brand); color: var(--brand-ink); cursor: pointer; display: grid; place-items: center; flex: none; }',
        '.form .send svg { width: 18px; height: 18px; }',
        '.form .send[disabled] { opacity: .5; cursor: default; }',
        '.wrap.page .launcher, .wrap.page .x { display: none !important; }',
        '.wrap.page .panel { display: flex; top: 0; left: 0; right: 0; bottom: 0; margin: auto; width: 760px; max-width: 100vw; height: 860px; max-height: 100%; }',
        '@media (max-height: 860px) { .wrap.page .panel { height: 100%; border-radius: 0; border-top: 0; border-bottom: 0; } }',
        '@media (max-width: 760px) { .wrap.page .panel { width: 100vw; height: 100%; border-radius: 0; border: 0; } }',
        // Embedded: the panel *is* the frame. No launcher -- the application
        // has its own button in its own toolbar -- but the close button stays,
        // because in a frame closing means asking the page around us to put the
        // panel away, which is a different thing from ending the conversation.
        '.wrap.embed .launcher { display: none !important; }',
        '.wrap.embed .panel { position: absolute; top: 0; right: 0; bottom: 0; left: 0;' +
            ' width: auto; max-width: none; height: auto; max-height: none;' +
            ' border: 0; border-radius: 0; box-shadow: none; }',
        // The history: one row per conversation, and nothing on a row that an
        // agent would not say out loud.
        '.history { padding: 8px; gap: 8px; }',
        '.conv {',
        '  display: block; width: 100%; text-align: left; font: inherit; color: var(--ink); cursor: pointer;',
        '  background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px;',
        '}',
        '.conv:hover { border-color: var(--brand); }',
        '.conv:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }',
        '.conv .subject { font-weight: 600; display: flex; align-items: center; gap: 6px; }',
        '.conv .subject span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
        '.conv .meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; font-size: 12px; color: var(--muted); }',
        '.conv .state { border-radius: 999px; padding: 1px 8px; background: var(--brand-soft); color: var(--brand); font-weight: 600; }',
        '.conv.closed .state { background: var(--line); color: var(--muted); }',
        '.conv .n { flex: none; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: #c0392b; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; }',
        '.head .nav { position: relative; border: 0; width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; flex: none; }',
        '.head .nav svg { width: 18px; height: 18px; }',
        '.head .nav:hover { background: rgba(255,255,255,.14); }',
        '.head .nav[hidden] { display: none; }',
        '.src { padding: 2px 12px 8px; text-align: center; background: var(--bg); }',
        '.src a { font-size: 11px; color: var(--muted); text-decoration: none; }',
        '.src a:hover { text-decoration: underline; }',
        '.src a:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; border-radius: 4px; }',
        '[hidden] { display: none !important; }',
        '@media (prefers-reduced-motion: reduce) { .launcher { transition: none; } }',
        '</style>',
        '<div class="wrap">',
        '<button class="launcher" type="button" aria-expanded="false">' + ICON_CHAT + ICON_CLOSE + '<span class="badge" hidden></span></button>',
        '<section class="panel" role="dialog">',
        '  <header class="head">',
        '    <button class="nav back" type="button" hidden>' + ICON_BACK + '</button>',
        '    <div class="who"><div class="title"></div><div class="status"><span class="dot"></span><span class="status-text"></span></div></div>',
        '    <button class="nav list" type="button" hidden>' + ICON_LIST + '<span class="badge list-badge" hidden></span></button>',
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
        '  <div class="screen history" hidden></div>',
        '  <div class="log" role="log" aria-live="polite" hidden></div>',
        '  <div class="typing" role="status" hidden><span class="dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="typing-text"></span></div>',
        '  <form class="form" hidden>',
        '    <textarea rows="1" maxlength="4000"></textarea>',
        '    <button class="send" type="submit">' + ICON_SEND + '</button>',
        '  </form>',
        // Only on the /chat page, and only when the helpdesk gave an address:
        // an embedded bubble is a guest on somebody else's page and adds
        // nothing to it. "Open Source" reads the same in both languages, so it
        // is the one label here that is not translated; what it means is in the
        // title, which words() sets.
        (PAGE && SOURCE ? '  <div class="src"><a href="' + SOURCE + '" target="_blank" rel="noopener noreferrer">Open Source</a></div>' : ''),
        '</section>',
        '</div>'
    ].join('');

    if (SHEET) {
        var sheet = document.createElement('link');
        sheet.rel = 'stylesheet';
        sheet.href = SHEET;
        root.insertBefore(sheet, root.querySelector('style').nextSibling);
    }

    function $(selector) { return root.querySelector(selector); }
    function $all(selector) { return root.querySelectorAll(selector); }

    var launcher = $('.launcher');
    var badge    = $('.badge');
    var panel    = $('.panel');
    var intro    = $('.intro');
    var offline  = $('.offline');
    var done     = $('.done');
    var historyBox = $('.history');
    var backBtn  = $('.back');
    var listBtn  = $('.list');
    var listBadge = $('.list-badge');
    var log      = $('.log');
    var form     = $('.form');
    var endBtn   = $('.end');
    var confirm  = $('.confirm');
    var dot      = $('.dot');
    var typingBox = $('.typing');
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
        listBtn.setAttribute('aria-label', T.history);
        listBtn.setAttribute('title', T.history);
        backBtn.setAttribute('aria-label', T.historyBack);
        backBtn.setAttribute('title', T.historyBack);
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
        if ($('.src a')) { $('.src a').setAttribute('title', T.sourceTitle); }
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
        historyBox.hidden = mode !== 'history';
        log.hidden = mode !== 'chat';
        // A conversation from the history that can no longer be written into --
        // an agent deleted it, or marked it spam -- is still worth reading, so
        // the messages stay and only the box to type in goes.
        form.hidden = mode !== 'chat' || readOnly;
        confirm.hidden = true;
        paintNav();

        // "End" ends the conversation you are in. It has no meaning over a list
        // of conversations, and none over one out of the history either — that
        // one is already over, or it is the live chat and will say so.
        if (EMBED) { endBtn.hidden = mode !== 'chat' || !token || viewing !== null; }

        if (mode !== 'chat') { typingBox.hidden = true; }

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

    // For a history row, where a conversation may be from this morning or from
    // March. `clock()` shows a time and only a time, which on a list of months
    // is the one thing that tells you nothing.
    function when(iso) {
        try {
            var date = new Date(iso),
                now = new Date(),
                locale = LANG === 'ro' ? 'ro-RO' : 'en-GB';

            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
            }

            return date.toLocaleDateString(locale, date.getFullYear() === now.getFullYear()
                ? { day: 'numeric', month: 'short' }
                : { day: 'numeric', month: 'short', year: 'numeric' });
        } catch (e) {
            return '';
        }
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

    // `id` is the message's own, once the server has it. A visitor's message
    // is drawn before that, as pending, and given its id by markSent().
    function add(from, body, author, at, id) {
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
        var time = el('div', 'time', clock(at));
        if (from === 'visitor') { time.appendChild(el('span', 'receipt')); }
        row.appendChild(time);

        if (id) {
            row.setAttribute('data-id', String(id));
            place(row);
        } else {
            if (from === 'visitor') {
                row.classList.add('pending');
                row.querySelector('.receipt').setAttribute('title', T.sending);
            }
            log.appendChild(row);
        }
        paintReceipts();
        log.scrollTop = log.scrollHeight;

        if (from === 'agent' && !panel.classList.contains('open')) {
            unread++;
            badge.textContent = String(unread);
            badge.hidden = false;
            // Embedded, the badge the person actually sees is the application's
            // own, on the button in its toolbar. Ours is behind a hidden frame.
            toParent('UNREAD_COUNT', unread);
        }

        return row;
    }

    // In the order the server wrote them. A reply the agent wrote while the
    // visitor's own message was on its way arrives after it on screen, but
    // was said first.
    function place(row) {
        var id = parseInt(row.getAttribute('data-id'), 10) || 0,
            rows = log.querySelectorAll('.row[data-id]');

        for (var i = 0; i < rows.length; i++) {
            if (rows[i] !== row && (parseInt(rows[i].getAttribute('data-id'), 10) || 0) > id) {
                log.insertBefore(row, rows[i]);
                return;
            }
        }
        log.appendChild(row);
    }

    // The server has the visitor's message: it has an id now, and the poll
    // that brings it back must not draw it a second time.
    function markSent(row, id) {
        if (!id) { return; }
        seen[id] = true;
        if (!row || !row.parentNode) { return; }
        row.classList.remove('pending');
        row.setAttribute('data-id', String(id));
        place(row);
        paintReceipts();
    }

    function pendingWith(body) {
        var squash = function (s) { return String(s).replace(/\s+/g, ' ').trim(); },
            rows = log.querySelectorAll('.row.visitor.pending');

        for (var i = 0; i < rows.length; i++) {
            var bubble = rows[i].querySelector('.msg');
            if (bubble && squash(bubble.textContent) === squash(body)) { return rows[i]; }
        }
        return null;
    }

    // Ticks only: one once sent, two once an agent's screen fetched it. The
    // visitor is never shown words or a time for it; those are for the agent.
    function paintReceipts() {
        var rows = log.querySelectorAll('.row.visitor[data-id]');

        for (var i = 0; i < rows.length; i++) {
            var mark = rows[i].querySelector('.receipt');
            if (!mark) { continue; }
            if (!receipts) {
                mark.textContent = '';
                continue;
            }

            var id = parseInt(rows[i].getAttribute('data-id'), 10) || 0,
                state = id <= receipts.seen ? 'seen'
                    : id <= Math.max(receipts.delivered, receipts.seen) ? 'delivered' : 'sent';

            mark.className = 'receipt ' + state;
            mark.textContent = state === 'sent' ? '✓' : '✓✓';
            mark.setAttribute('title', state === 'seen' ? T.seen : state === 'delivered' ? T.delivered : T.sent);
        }
    }

    // What the visitor can be said to have seen: the newest agent message in
    // the window, while the window is open, on the chat, in a visible tab.
    function newestAgentMessageShown() {
        if (document.hidden || !panel.classList.contains('open') || panel.getAttribute('data-mode') !== 'chat') {
            return 0;
        }

        var newest = 0, rows = log.querySelectorAll('.row.agent[data-id]');
        for (var i = 0; i < rows.length; i++) {
            newest = Math.max(newest, parseInt(rows[i].getAttribute('data-id'), 10) || 0);
        }
        return newest;
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
            case 'too_fast': return T.errTooFast;
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
        toParent('SESSION_ENDED');
        try { if (store()) { store().removeItem(STORE); } } catch (e) {}
        token = null;
        since = 0;
        seen = {};
        receipts = { delivered: 0, seen: 0, seen_at: null };
        typedAt = 0;
        endBtn.hidden = true;
        confirm.hidden = true;
        typingBox.hidden = true;
    }

    // ---------------------------------------------------------------- typing

    // Only that the visitor is typing, never what: nothing of the text leaves
    // the page before they send it.
    function visitorTyping() {
        return !!token && Date.now() - typedAt < TYPING_MS && input.value.trim() !== '';
    }

    function paintTyping(sign) {
        if (!sign || !token) {
            typingBox.hidden = true;
            return;
        }
        $('.typing-text').textContent = sign.name ? T.typingNamed.replace('{name}', sign.name) : T.typing;
        typingBox.hidden = false;
    }

    // A new conversation starts on an empty window. The last one's messages
    // stay on screen after it ends, so the visitor can read what was said —
    // but left there, the next conversation's first message landed under
    // them and read as the old chat carrying on, when the server had opened a
    // new one.
    function fresh() {
        while (log.firstChild) { log.removeChild(log.firstChild); }
        seen = {};
        receipts = { delivered: 0, seen: 0, seen_at: null };
        unread = 0;
        badge.hidden = true;
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

    // Something happened in the conversation: poll often for a while.
    function active() {
        lastActivity = Date.now();
    }

    // Every second and a half while the conversation is alive — a message or
    // somebody typing in the last two minutes — and the visitor can see it;
    // every five seconds when it has gone quiet; every thirty while the tab
    // is hidden, which still keeps the visitor counted as present. Each poll
    // costs the server about ten milliseconds of processor time, so asking
    // often only while it matters keeps the cost close to what a fixed
    // three-second poll spent. Live Helper Chat asks every three and a half
    // seconds whatever is happening.
    function nextDelay() {
        var delay = document.hidden ? HIDDEN_MS
            : Date.now() - lastActivity < ACTIVE_FOR_MS ? FAST_MS : QUIET_MS;

        // A little jitter, so tabs opened together do not ask together.
        return Math.round(delay * (0.9 + Math.random() * 0.2));
    }

    // ------------------------------------------------------------- history

    // The badge on the application's own button, and the one on the list
    // control inside the panel, are the same number: how many answers this
    // person has not read, across every conversation they own. It comes from
    // the server, because it has to survive the tab being closed.
    function setUnread(total) {
        unreadTotal = Math.max(0, parseInt(total, 10) || 0);

        listBadge.textContent = unreadTotal > 9 ? '9+' : String(unreadTotal);
        listBadge.hidden = !unreadTotal;

        toParent('UNREAD_COUNT', unreadTotal);
    }

    // What the header offers, which depends only on where we are.
    function paintNav() {
        if (!EMBED) { return; }

        var mode = panel.getAttribute('data-mode');

        backBtn.hidden = !(viewing !== null || mode === 'history');
        listBtn.hidden = !hasHistory || mode === 'history';
    }

    function stateWord(key) {
        return key === 'closed' ? T.stClosed : key === 'pending' ? T.stPending : T.stActive;
    }

    // One row per conversation. Every value is written with textContent: a
    // subject is whatever the first message said, so it is somebody else's
    // text even when that somebody is this customer.
    function paintHistory(list, more) {
        while (historyBox.firstChild) { historyBox.removeChild(historyBox.firstChild); }

        if (!list.length) {
            historyBox.appendChild(el('p', null, T.historyEmpty));
            return;
        }

        list.forEach(function (item) {
            var row = el('button', 'conv' + (item.status === 'closed' ? ' closed' : ''));
            row.type = 'button';
            row.setAttribute('data-id', String(item.id));
            row.setAttribute('aria-label', T.historyOpen);

            var subject = el('div', 'subject');
            subject.appendChild(el('span', null, item.subject || T.title));
            if (item.unread > 0) {
                subject.appendChild(el('span', 'n', item.unread > 9 ? '9+' : String(item.unread)));
            }
            row.appendChild(subject);

            var meta = el('div', 'meta');
            meta.appendChild(el('span', 'state', stateWord(item.status)));
            if (item.at) { meta.appendChild(el('span', null, when(item.at))); }
            row.appendChild(meta);

            row.addEventListener('click', function () { openFromHistory(item.id); });
            historyBox.appendChild(row);
        });

        if (more) {
            historyBox.appendChild(el('p', 'small-print', T.historyMore));
        }
    }

    function loadHistory() {
        show('history');
        stopPolling();

        // `data-history` says whether what is on screen is this load's answer
        // or the last one's. The list is fetched, so there is a moment when it
        // shows the conversations as they were a minute ago -- long enough for
        // somebody to read a state that has since changed, and long enough for
        // a test to check one.
        panel.setAttribute('data-history', 'loading');

        post('app/history', { app_token: appToken })
            .then(function (res) {
                if (!res || res.status !== 'success') {
                    paintHistory([], false);
                    panel.setAttribute('data-history', 'error');
                    return;
                }

                hasHistory = !!res.history;
                if (res.unread) { setUnread(res.unread.messages); }
                paintHistory(res.conversations || [], res.more);
                paintNav();
                panel.setAttribute('data-history', 'ready');
            })
            .catch(function () {
                paintHistory([], false);
                panel.setAttribute('data-history', 'error');
            });
    }

    // Open one conversation from the list. Reading it is what marks it read --
    // not listing it, and not the panel having been opened.
    function openFromHistory(id) {
        post('app/conversation', { app_token: appToken, conversation_id: id })
            .then(function (res) {
                if (!res || res.status !== 'success') {
                    note(T.errHistory);
                    return;
                }

                stopPolling();
                // Which conversation is on screen, for anything that needs to
                // know the drawing has caught up with the asking.
                panel.setAttribute('data-conversation', String(res.id));
                // Whether this is the live chat is decided by what the server
                // just said, and never by what this page still holds. The
                // token in hand proves nothing: a chat closed by an agent while
                // the panel was showing the list was never polled again, so the
                // token outlives the chat it addressed. Replying on it would be
                // answered "this conversation is no longer open" — with a
                // reopening path sitting right there unused.
                //
                // So: the current conversation, still open, is the live chat.
                // Everything else goes through the history, which is the path
                // that reopens.
                viewing = res.current && res.can_reply && res.status_key !== 'closed' ? null : res.id;
                readOnly = !res.can_reply;
                fresh();

                (res.messages || []).forEach(function (m) {
                    seen[m.id] = true;
                    add(m.from, m.body, m.author, m.at, m.id);
                });

                show('chat');

                // The live chat carries on polling; a conversation out of the
                // history does not, because the token in hand addresses the
                // live one and this is not it.
                if (viewing === null) {
                    since = 0;
                    if (token) { pollNow(); startPolling(); }
                } else {
                    markSeen(res.id, res.messages || []);
                }
            })
            .catch(function () { note(T.errHistory); });
    }

    // Tell the server how far this person has now actually read. Only the
    // newest agent message that was drawn, because that is all they saw.
    function markSeen(id, messages) {
        var newest = 0;
        messages.forEach(function (m) {
            if (m.from === 'agent' && m.id > newest) { newest = m.id; }
        });

        if (!newest) { return; }

        post('app/seen', { app_token: appToken, conversation_id: id, seen: newest })
            .then(function (res) {
                if (res && res.status === 'success' && res.unread) { setUnread(res.unread.messages); }
            })
            .catch(function () { /* the count is refreshed on the next load */ });
    }

    // ----------------------------------------------------- embedded identity

    // The permission the application's page has handed over, which its own
    // server asked this helpdesk for. Taken once, and only in the shape a token
    // has: anything else is not an argument worth having with a message that
    // has already failed the origin check.
    function authorise(value) {
        if (typeof value !== 'string' || !/^[0-9a-f]{64}$/.test(value) || value === appToken) { return; }

        appToken = value;
        resumeChat();
    }

    // Which chat this identity is already in, if any — the question the
    // introduction form answers for a stranger. An open conversation is picked
    // up where it was left, whichever page of the application we are on now;
    // no conversation means the next message opens one.
    function resumeChat() {
        post('app/resume', { app_token: appToken })
            .then(function (res) {
                show('chat');

                if (!res || res.status !== 'success') {
                    appToken = null;
                    note(T.errUnavailable);
                    return;
                }

                canLeaveMessage = !!res.offline;
                hasHistory = !!res.history;
                if (res.unread) { setUnread(res.unread.messages); }
                paintNav();

                if (res.chat && res.chat.token) {
                    remember(res.chat.token);
                    since = res.chat.since || 0;
                    pollNow();
                    startPolling();
                }

                checkStatus();
            })
            .catch(function () { show('chat'); note(T.errNetwork); });
    }

    // The panel is on screen again: nothing is unread any more, and the
    // conversation catches up now rather than at the next quiet poll.
    function shown() {
        panel.classList.add('open');
        unread = 0;
        badge.hidden = true;
        toParent('UNREAD_COUNT', 0);

        if (token) { pollNow(); startPolling(); }
        try { input.focus(); } catch (e) {}
    }

    // Put away, but still listening: replies keep arriving and are counted.
    function hidden() {
        panel.classList.remove('open');
    }

    // The only door the application has into this page. Three things are true
    // of every message that gets past this line, and none of them is optional:
    // it came from the origin the helpdesk registered, it came from the window
    // this page is framed in, and it is one of the three messages there are.
    // Anything else is dropped without an answer — a page that argues with
    // messages it does not know is a page that can be probed.
    if (EMBED) {
        window.addEventListener('message', function (e) {
            if (!PARENT || e.origin !== PARENT || e.source !== window.parent) { return; }

            var message = e.data;
            if (!message || typeof message !== 'object' || typeof message.type !== 'string') { return; }

            if (message.type === 'AUTH') { authorise(message.value); }
            else if (message.type === 'OPEN') { shown(); }
            else if (message.type === 'MINIMIZE') { hidden(); }
        });
    }

    function poll() {
        if (!token || inFlight) { return; }

        inFlight = true;
        lastPollAt = Date.now();
        fetch(BASE + '/gesoft-live-chat/poll?token=' + encodeURIComponent(token) + '&since=' + since + '&lang=' + LANG
            + '&typing=' + (visitorTyping() ? 1 : 0) + '&seen=' + newestAgentMessageShown())
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.status !== 'success') { return; }

                // Before the messages, so each is drawn with its ticks.
                if ('receipts' in res) {
                    receipts = res.receipts || null;
                }

                // Messages first: the agent's last words before closing arrive
                // in the same answer that says the conversation is closed.
                (res.messages || []).forEach(function (m) {
                    if (seen[m.id]) { return; }

                    // A poll that crossed the send can bring the visitor's
                    // message back before the send answered. It is the one
                    // already on screen as pending, not a second message.
                    var mine = m.from === 'visitor' && pendingWith(m.body);
                    if (mine) {
                        markSent(mine, m.id);
                        return;
                    }

                    seen[m.id] = true;
                    add(m.from, m.body, m.author, m.at, m.id);
                    active();
                });
                paintReceipts();

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

                // The server stops naming the agent once their message is
                // in, so the dots give way to the message in the same answer.
                paintTyping(res.typing);
                if (res.typing) { active(); }

                if (res.closed) {
                    stopPolling();
                    note(T.closed);
                    forget();
                }
            })
            .catch(function () { /* a dropped poll is not worth telling anyone */ })
            .then(function () {
                inFlight = false;
                schedule();
            });
    }

    // The next poll, counted from when the last one answered, so there is
    // never more than one on its way.
    function schedule() {
        if (timer) { clearTimeout(timer); timer = null; }
        if (!polling || !token || inFlight) { return; }

        var delay = nextDelay();
        timerDue = Date.now() + delay;
        timer = setTimeout(poll, delay);
    }

    // Starts the loop, or brings the next poll forward to the active pace.
    function startPolling() {
        polling = true;
        if (!timer || timerDue - Date.now() > FAST_MS) { schedule(); }
    }

    function stopPolling() {
        polling = false;
        if (timer) { clearTimeout(timer); timer = null; }
    }

    // Now rather than at the next turn: the visitor started typing, or came
    // back to the tab.
    function pollNow() {
        if (!polling || !token || inFlight) { return; }
        if (timer) { clearTimeout(timer); timer = null; }
        poll();
    }

    // ---------------------------------------------------------------- events

    function open() {
        panel.classList.add('open');
        launcher.setAttribute('aria-expanded', 'true');
        launcher.setAttribute('aria-label', T.closeChat);
        unread = 0;
        badge.hidden = true;
        toParent('UNREAD_COUNT', 0);

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
        if (PAGE) { return; }

        // In a frame there is nothing of ours to hide -- the panel *is* the
        // frame -- and putting it away belongs to the application around us. So
        // we ask, and stay exactly as we are until it says it has.
        if (EMBED) { toParent('MINIMIZE'); return; }

        panel.classList.remove('open');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-label', T.openChat);
    }

    launcher.addEventListener('click', function () {
        if (panel.classList.contains('open')) { close(); } else { open(); }
    });
    $('.x').addEventListener('click', close);

    // The list, and the way back out of it. Both exist only in a panel
    // embedded in an application, because only there is there an identity to
    // have a history.
    listBtn.addEventListener('click', function () { loadHistory(); });

    backBtn.addEventListener('click', function () {
        if (panel.getAttribute('data-mode') === 'history') {
            // Out of the list and back to whatever was on screen: the live
            // chat when there is one, an empty one when there is not.
            viewing = null;
            readOnly = false;
            backToChat();
            return;
        }

        loadHistory();
    });

    // The live chat, as it was. Redrawn from the server rather than from
    // whatever was left on screen, because the screen may have been showing
    // somebody's conversation from March a moment ago.
    function backToChat() {
        viewing = null;
        readOnly = false;
        panel.removeAttribute('data-conversation');
        fresh();
        since = 0;
        show('chat');

        if (token) {
            pollNow();
            startPolling();
        }
    }
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

            fresh();
            show('chat');
            var first = res.id || res.since;
            add('visitor', text, null, null, first);
            if (first) { seen[first] = true; }
            active();
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

        // A message is on its way, or the visitor was asked to slow down.
        // What they typed stays in the box.
        if (sendBtn.disabled) { return; }

        var text = input.value.trim();
        if (!text) { return; }

        // The page around us has not handed over its permission yet, which
        // takes a moment on a page that has only just loaded. What they wrote
        // stays in the box.
        if (EMBED && !token && !appToken) { return; }

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
        typedAt = 0;
        grow();
        sendBtn.disabled = true;
        // A first message starts on a clean screen -- but not when the screen
        // is showing a conversation out of the history, which is the one being
        // replied to.
        if (!token && viewing === null) { fresh(); }
        var row = add('visitor', text, null, null);

        // Not sent after all: take it back out of the conversation, where it
        // would look delivered, and give the visitor their words back.
        function takeBack() {
            if (row && row.parentNode) { row.parentNode.removeChild(row); }
            if (!input.value) {
                input.value = text;
                grow();
            }
        }

        // A conversation opened from the history is answered where it is, not
        // where the live chat happens to be. The helpdesk reopens it if it was
        // closed -- that is core's rule, "reply from customer makes
        // conversation active" -- and hands back a token, so from the next
        // message on this simply *is* the live chat.
        //
        // Outside our hours a first message becomes an email conversation
        // instead of a chat nobody will answer tonight, but only when there is
        // an address to answer it at, which the helpdesk said when the panel
        // resumed.
        var path = viewing !== null ? 'app/reply'
            : token ? 'send'
            : (EMBED && online === false && canLeaveMessage ? 'offline' : 'start');
        var payload = { token: token, message: text };

        if (path === 'app/reply') {
            payload.app_token = appToken;
            payload.conversation_id = viewing;
        }

        // No token yet means this is the first message, which happens here only
        // when the host page supplied the identity and the form was skipped.
        if (!token && EMBED) {
            // The permission instead of a name and an address. Who this is was
            // settled between two servers; nothing typed in this frame is
            // consulted, and nothing typed in it could be.
            payload.app_token = appToken;
        } else if (!token) {
            payload.name = IDENTITY.name || '';
            payload.email = IDENTITY.email || '';
            payload.phone = IDENTITY.phone || '';
        }

        post(path, payload)
            .then(function (res) {
                sendBtn.disabled = false;

                if (!res || res.status !== 'success') {
                    if (row && row.parentNode) { row.parentNode.removeChild(row); }
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
                        return;
                    }

                    takeBack();

                    // Too fast: Send stays off for as long as the server said.
                    if (res && res.code === 'too_fast') {
                        sendBtn.disabled = true;
                        setTimeout(function () { sendBtn.disabled = false; },
                            Math.max(1, parseInt(res.retry_after, 10) || 5) * 1000);
                    }
                    return;
                }

                // Left as a message because nobody was available: there is no
                // conversation to poll, only an answer coming by email.
                if (path === 'offline') {
                    fresh();
                    show('done');
                    return;
                }

                // The old conversation has taken over as the live one. Its
                // token replaces the one we held, and the poll starts again
                // from the beginning of it.
                if (path === 'app/reply') {
                    viewing = null;
                    hasHistory = true;
                    paintNav();

                    if (res.chat && res.chat.token) {
                        remember(res.chat.token);
                        since = 0;
                    }

                    markSent(row, res.id || 0);
                    active();
                    startPolling();
                    return;
                }

                if (res.token) { remember(res.token); }

                // The poll pointer is not moved to this message: an agent
                // reply written since the last poll has a smaller id, and
                // moving past it lost that reply for good. The next poll
                // brings the reply, and this message only as already drawn.
                // A first message is the conversation's first thread, so
                // there is nothing before it to lose.
                if (path === 'start' && typeof res.since === 'number') { since = res.since; }
                markSent(row, res.id || res.since);

                // An answer is likely soon.
                active();
                startPolling();
            })
            .catch(function () {
                sendBtn.disabled = false;
                takeBack();
                note(T.errNetwork);
            });
    });

    function grow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    input.addEventListener('input', grow);

    // Typing travels with the next poll, and keeps the conversation at the
    // active pace. The first keystroke after a pause polls at once, so the
    // agent hears it within a moment — unless a poll has only just gone out.
    input.addEventListener('input', function () {
        var was = visitorTyping();
        typedAt = input.value.trim() !== '' ? Date.now() : 0;
        if (typedAt) { active(); }

        if (!was && visitorTyping() && Date.now() - lastPollAt >= 1000) {
            pollNow();
        }
    });
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
        endOnServer(t, 3);
        note(T.ended);
    });

    // The visitor has been told the chat is over, so it has to be over. A
    // refused or lost request used to be dropped, and the agent went on
    // seeing the visitor as present until two minutes of silence gave them
    // away; seen on the test instance when the route throttle refused it.
    function endOnServer(t, attempts) {
        post('end', { token: t })
            .then(function (res) {
                if (!res || res.status !== 'success') { throw new Error('refused'); }
            })
            .catch(function () {
                if (attempts > 1) {
                    setTimeout(function () { endOnServer(t, attempts - 1); }, 5000);
                }
            });
    }

    // Back to the tab: catch up at once rather than at the end of a hidden
    // tab's thirty-second wait.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { pollNow(); }
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
    if (PAGE) { $('.wrap').classList.add('page'); }
    if (EMBED) { $('.wrap').classList.add('embed'); }
    document.body.appendChild(host);

    // A reload in the same tab picks the conversation back up without clicking.
    if (EMBED) {
        // Open from the start: this frame is only ever on screen because the
        // application put it there. Empty, though, until the permission
        // arrives — READY is what asks for it.
        panel.classList.add('open');
        endBtn.hidden = true;
        show('chat');
        toParent('READY');
    } else if (PAGE) {
        endBtn.hidden = !token;
        open();
    } else if (token) {
        endBtn.hidden = false;
        show('chat');
        poll();
        startPolling();
    }
})();
