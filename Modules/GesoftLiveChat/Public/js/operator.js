/**
 * Tells an agent that somebody is waiting in a chat, from anywhere in the
 * interface, and carries the chat actions that need a browser.
 *
 * FreeScout does not. Its Chats link in the sidebar carries no count, and the
 * realtime channel that announces a new chat is subscribed to only when the
 * sidebar is *already* showing the chat list — `main.js` guards it on
 * `#folders.chats`. So the one property that makes chat different from email,
 * that somebody is waiting right now, is invisible to an agent reading a
 * ticket.
 *
 * This adds, and patches no core file:
 *
 *   - a count on the Chats link and next to the bell;
 *   - an alert that opens the chat, a sound, and a browser notification;
 *   - whether each chat's visitor is still there, as a mark in the chat list;
 *   - "the customer is typing…" in a chat, and three dots in the visitor's
 *     bubble while the agent writes a reply;
 *   - "are you still there?" and the block dialog, from More Actions;
 *   - a chat reply that stays on the page instead of reloading it;
 *   - in Chat Mode, the newest message at the bottom and the editor under it.
 *
 * Words follow the agent's own interface language, read from the page core
 * rendered; English is the fallback.
 *
 * It reuses what core already loads — the Polycast connection, the audio cue,
 * the Push library, Bootstrap's modal — rather than bringing its own.
 */
(function ($) {
    'use strict';

    // The floor, not the mechanism: the realtime subscription below answers
    // within a second. Ten rather than twenty because this is how long an agent
    // can be unaware that somebody is waiting when the subscription is not
    // available, and twenty seconds of that is too long. It is also the agents'
    // heartbeat, which is what tells the bubble somebody is there.
    var POLL_MS = 10000;

    var WORDS = {
        en: {
            newFrom: 'New message from {name}',
            newChat: 'New chat message',
            several: '{n} chats with new messages',
            clickToOpen: ' — click to open',
            open: 'Open',
            pushList: 'Open the chat list.',
            pushWaiting: 'A visitor is waiting for an answer.',
            activeChats: 'Active chats',
            here: 'The customer is in the chat',
            left: 'The customer left the chat',
            ended: 'The customer ended the chat',
            typing: 'The customer is typing…',
            sent: 'Sent',
            delivered: 'Delivered',
            seen: 'Seen',
            seenAt: 'Seen at {time}',
            newer: 'New messages',
            failed: 'That did not work. Try again.'
        },
        ro: {
            newFrom: 'Mesaj nou de la {name}',
            newChat: 'Mesaj nou în chat',
            several: '{n} chaturi cu mesaje noi',
            clickToOpen: ' — click pentru a deschide',
            open: 'Deschide',
            pushList: 'Deschide lista de chaturi.',
            pushWaiting: 'Un vizitator așteaptă un răspuns.',
            activeChats: 'Chaturi active',
            here: 'Clientul este în chat',
            left: 'Clientul a părăsit chatul',
            ended: 'Clientul a încheiat chatul',
            typing: 'Clientul scrie…',
            sent: 'Trimis',
            delivered: 'Primit',
            seen: 'Văzut',
            seenAt: 'Văzut la {time}',
            newer: 'Mesaje noi',
            failed: 'Nu a funcționat. Încercați din nou.'
        }
    };
    var T = WORDS[String(document.documentElement.lang || 'en').slice(0, 2).toLowerCase()] || WORDS.en;

    var url = null;
    var known_latest = null;
    var started = false;

    // Built from the application's own base rather than looked up in laroute.
    //
    // laroute's JavaScript is generated at build time, and a module's routes
    // are only in it if somebody regenerated it after installing the module.
    // They were not, so `laroute.route()` threw, `refresh()` returned on its
    // first line, and nothing was ever painted — the indicator was not subtle,
    // it was absent. A URL this module already knows needs no build step to
    // stay true.
    function base() {
        try {
            if (typeof Vars !== 'undefined' && Vars.public_url) { return Vars.public_url; }
        } catch (e) {}

        return '';
    }

    function csrf() {
        return $('meta[name="csrf-token"]').attr('content');
    }

    function endpoint() {
        if (url) { return url; }
        url = base() + '/gesoft-live-chat/agent/chats';

        return url;
    }

    function chatsLink() {
        // Core renders this link by hand, with no id and no class of its own,
        // so it is found by where it goes rather than by what it is called.
        return $('#folders a[href*="chat_mode=1"]').filter(function () {
            return $(this).find('.folder-name').length > 0;
        }).first();
    }

    // The sidebar exists only on mailbox pages. This one sits next to the bell
    // in the header, which is on every page of the application, so an agent in
    // settings or on the dashboard can still see that somebody is waiting.
    function paintHeader(count, mailbox_id) {
        var bar = $('ul.navbar-nav.navbar-right').first();
        if (!bar.length) { return; }

        var item = bar.find('.gesoft-chat-header');

        if (!count) {
            item.remove();
            return;
        }

        if (!item.length) {
            item = $(
                '<li class="dropdown gesoft-chat-header">' +
                '  <a href="#" class="dropdown-toggle-icon">' +
                '    <i class="glyphicon glyphicon-comment"></i>' +
                '    <small class="gesoft-chat-header-count"></small>' +
                '  </a>' +
                '</li>'
            );
            item.find('a').attr('title', T.activeChats);
            bar.prepend(item);
        }

        item.find('.gesoft-chat-header-count').text(count);
        if (mailbox_id) {
            item.find('a').attr('href', base() + '/mailbox/' + mailbox_id + '/chats');
        }
    }

    function paint(count) {
        var link = chatsLink();
        if (!link.length) { return; }

        var badge = link.find('.gesoft-chat-count');
        if (!badge.length) {
            badge = $('<strong class="gesoft-chat-count pull-right"></strong>');
            link.append(badge);
        }

        if (count > 0) {
            badge.text(count).show();
            link.closest('li').addClass('gesoft-chats-waiting');
        } else {
            badge.hide();
            link.closest('li').removeClass('gesoft-chats-waiting');
        }
    }

    function isLookingAt(conversation_id) {
        if (!conversation_id || document.hidden) { return false; }
        try {
            return String(getGlobalAttr('conversation_id')) === String(conversation_id);
        } catch (e) {
            return false;
        }
    }

    // The alert offers the chat; it never takes the agent there by itself. An
    // agent halfway through a reply to a ticket would lose it, and two
    // customers typing at once would bounce them between chats.
    function announce(res) {
        var several = res.new_conversations > 1;

        // The agent is reading that chat already, and the message is on the
        // screen in front of them. Telling them again is noise.
        if (!several && isLookingAt(res.latest_conversation_id)) { return; }

        // One chat: open that one. Several: any single one would be a guess,
        // so open the list and let the agent choose.
        var target = several ? (res.list_url || res.latest_url) : (res.latest_url || res.list_url);
        var title = several
            ? T.several.replace('{n}', res.new_conversations)
            : (res.latest_name ? T.newFrom.replace('{name}', res.latest_name) : T.newChat);

        // Core's own cue, so chat sounds like the rest of the application
        // rather than like a second product bolted on.
        if (typeof playAudioNotification === 'function') {
            try { playAudioNotification(null); } catch (e) {}
        }

        // In the page as well as out of it. A browser notification needs a
        // permission the agent may never have granted, and a chat nobody is
        // told about is the failure this whole file exists to prevent.
        if (typeof showFloatingAlert === 'function') {
            try {
                showFloatingAlert('success', target ? title + T.clickToOpen : title);

                // Core's alert is plain text and not a link. It appends to the
                // body and returns, so the last one is the one just made.
                if (target) {
                    $('.alert-floating').last()
                        .addClass('gesoft-chat-alert')
                        .attr('title', T.open)
                        .on('click', function () { window.location.href = target; });
                }
            } catch (e) {}
        }

        if (typeof Push === 'undefined' || !Push.Permission.has()) { return; }

        try {
            Push.create(title, {
                body: several ? T.pushList : T.pushWaiting,
                timeout: 8000,
                onClick: function () {
                    window.focus();
                    if (target) { window.location.href = target; }
                    this.close();
                }
            });
        } catch (e) {}
    }

    // Whether the visitor in each chat is still there, as a mark before the
    // name with the same thing said in its tooltip, so it never rests on colour
    // alone. The conversation itself carries a line when somebody leaves.
    var presence = {};

    function paintPresence() {
        $('.chats li.chat-item[data-chat_id]').each(function () {
            var li = $(this), state = presence[li.attr('data-chat_id')];

            li.removeClass('gesoft-presence-here gesoft-presence-left gesoft-presence-ended');
            if (!state) { return; }

            li.addClass('gesoft-presence-' + state);
            li.find('.folder-name').first().attr('title', T[state] || '');
        });
    }

    // Core re-renders the chat list from its own realtime events, which drops
    // these classes. Put them back whenever the list changes, at most once a
    // frame.
    if (window.MutationObserver) {
        $(function () {
            var queued = false;
            new MutationObserver(function () {
                if (queued) { return; }
                queued = true;
                window.requestAnimationFrame(function () { queued = false; paintPresence(); });
            }).observe(document.body, { childList: true, subtree: true });
        });
    }

    function refresh(announce_new) {
        var u = endpoint();
        if (!u) { return; }

        // What the browser has already seen, so the answer can say how many
        // different chats have spoken since.
        var params = known_latest ? { since: known_latest } : {};

        $.getJSON(u, params).done(function (res) {
            if (!res || res.status !== 'success') { return; }

            paint(res.count);
            paintHeader(res.count, res.mailbox_id);

            presence = res.presence || {};
            paintPresence();

            // First answer of the page load only establishes what is already
            // there. Announcing then would greet an agent with a notification
            // for every chat that was open before they sat down.
            if (known_latest === null) {
                known_latest = res.latest_id;
                return;
            }

            if (announce_new && res.latest_id && res.latest_id > known_latest) {
                announce(res);
            }
            known_latest = res.latest_id;

            // Pages outside a mailbox have no mailbox id of their own, so the
            // subscription could not be made there and the badge fell back to
            // the poll — which is what made a new message look like it arrived
            // one message late. The endpoint knows the mailbox; use it.
            if (res.mailbox_id) { subscribe(3, res.mailbox_id); }
        });
    }

    // Ride core's existing Polycast connection rather than opening another.
    // `poly` is a file-scope global in main.js and is created on ready, so this
    // waits for it instead of assuming an order between two scripts.
    var subscribed = {};

    function subscribe(attempts, mailbox_id) {
        if (typeof poly === 'undefined' || !poly || !poly.subscribe) {
            if (attempts > 0) {
                setTimeout(function () { subscribe(attempts - 1, mailbox_id); }, 1000);
            }
            return;
        }

        if (!mailbox_id) {
            try { mailbox_id = getGlobalAttr('mailbox_id'); } catch (e) {}
        }
        if (!mailbox_id || subscribed[mailbox_id]) { return; }
        subscribed[mailbox_id] = true;

        try {
            var channel = poly.subscribe('chat.' + mailbox_id);
            channel.on('App\\Events\\RealtimeChat', function () {
                refresh(true);
            });
        } catch (e) { /* the badge still has its poll */ }
    }

    // "Are you still there?" from the conversation's More Actions menu.
    $(document).on('click', '.gesoft-chat-nudge', function (e) {
        e.preventDefault();

        var link = $(this), id = link.data('conversation-id');
        if (!id || link.data('busy')) { return; }
        link.data('busy', true);

        $.post(base() + '/gesoft-live-chat/agent/' + id + '/nudge', { _token: csrf() })
            .done(function () { window.location.reload(); })
            .fail(function () { link.data('busy', false); });
    });

    // "Block visitor…": a small dialog, built here from labels the server
    // translated. Every label goes in with .text(), never as markup.
    $(document).on('click', '.gesoft-chat-block-open', function (e) {
        e.preventDefault();

        var link = $(this), id = link.data('conversation-id'), L = link.data('labels') || {};
        if (!id) { return; }

        $('#gesoft-chat-block-modal').remove();

        var modal = $(
            '<div class="modal fade" id="gesoft-chat-block-modal" tabindex="-1" role="dialog">' +
            ' <div class="modal-dialog modal-sm" role="document"><div class="modal-content">' +
            '  <div class="modal-header">' +
            '   <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>' +
            '   <h4 class="modal-title"></h4>' +
            '  </div>' +
            '  <div class="modal-body">' +
            '   <p class="text-help gesoft-block-help"></p>' +
            '   <div class="checkbox"><label><input type="checkbox" name="block_ip" checked> <span class="gesoft-l-ip"></span></label></div>' +
            '   <div class="checkbox"><label><input type="checkbox" name="block_email" checked> <span class="gesoft-l-email"></span></label></div>' +
            '   <div class="form-group"><label class="control-label gesoft-l-for"></label><select class="form-control" name="days"></select></div>' +
            '   <div class="form-group"><label class="control-label gesoft-l-reason"></label><input type="text" class="form-control" name="reason" maxlength="191"></div>' +
            '   <p class="text-danger gesoft-block-error"></p>' +
            '  </div>' +
            '  <div class="modal-footer">' +
            '   <button type="button" class="btn btn-default gesoft-block-cancel" data-dismiss="modal"></button>' +
            '   <button type="button" class="btn btn-danger gesoft-block-submit"></button>' +
            '  </div>' +
            ' </div></div>' +
            '</div>'
        );

        modal.find('.modal-title').text(L.title || '');
        modal.find('.gesoft-block-help').text(L.help || '');
        modal.find('.gesoft-l-ip').text(L.ip || '');
        modal.find('.gesoft-l-email').text(L.email || '');
        modal.find('.gesoft-l-for').text(L['for'] || '');
        modal.find('.gesoft-l-reason').text(L.reason || '');
        modal.find('.gesoft-block-cancel').text(L.cancel || '');
        modal.find('.gesoft-block-submit').text(L.submit || '');

        var select = modal.find('select[name="days"]');
        [['1', L.d1], ['7', L.d7], ['30', L.d30], ['0', L.forever]].forEach(function (o) {
            select.append($('<option>').val(o[0]).text(o[1] || o[0]));
        });

        modal.on('click', '.gesoft-block-submit', function () {
            var button = $(this), error = modal.find('.gesoft-block-error');
            button.prop('disabled', true);
            error.text('');

            $.post(base() + '/gesoft-live-chat/agent/' + id + '/block', {
                _token: csrf(),
                block_ip: modal.find('input[name="block_ip"]').is(':checked') ? 1 : 0,
                block_email: modal.find('input[name="block_email"]').is(':checked') ? 1 : 0,
                days: select.val(),
                reason: modal.find('input[name="reason"]').val()
            }).done(function (res) {
                if (res && res.status === 'success') {
                    window.location.reload();
                    return;
                }
                button.prop('disabled', false);
                error.text((res && res.msg) || T.failed);
            }).fail(function (xhr) {
                button.prop('disabled', false);
                error.text((xhr.responseJSON && xhr.responseJSON.msg) || T.failed);
            });
        });

        $('body').append(modal);
        modal.modal('show');
    });

    // "The customer is typing…", and the other direction: whether this agent
    // is writing a reply, so the visitor's bubble can show three dots. One
    // request carries both, every three seconds, only on a chat conversation's
    // page — the line is rendered there and nowhere else — and only while the
    // page is visible.
    var TYPING_MS = 3000;
    var typedAt = 0;
    var typingBusy = false;

    // A reply, never a note. A note is written for colleagues, and a customer
    // watching dots while somebody writes about them is what a note exists to
    // avoid. Read from the field core submits the form with; if that field
    // ever moves, nothing is reported rather than a note taken for a reply.
    function writingReply() {
        var field = $(".form-reply:first :input[name='is_note']:first");
        if (!field.length || field.val()) { return false; }

        return Date.now() - typedAt < TYPING_MS + 1000;
    }

    // The visitor's newest message on this page. The beat runs only while the
    // page is visible, so this is what the agent had in front of them.
    function newestVisitorMessageShown() {
        var newest = 0;
        $('#conv-layout-main .thread.thread-type-customer[data-thread_id]').each(function () {
            newest = Math.max(newest, parseInt($(this).attr('data-thread_id'), 10) || 0);
        });
        return newest;
    }

    // Ticks under the agents' replies, from the pointers the beat returns:
    // one once sent, two once the bubble fetched it, green "Seen" once it was
    // on the visitor's screen, the newest seen one with the time.
    var lastReceipts = null;

    function paintReceipts(receipts) {
        if (!receipts) { return; }
        lastReceipts = receipts;

        var delivered = parseInt(receipts.delivered, 10) || 0,
            seen = parseInt(receipts.seen, 10) || 0,
            marks = $('.gesoft-chat-receipt[data-thread-id]'),
            newest = 0;

        marks.each(function () {
            var id = parseInt($(this).attr('data-thread-id'), 10) || 0;
            if (id <= seen && id > newest) { newest = id; }
        });

        marks.each(function () {
            var mark = $(this),
                id = parseInt(mark.attr('data-thread-id'), 10) || 0,
                state = id <= seen ? 'seen' : id <= Math.max(delivered, seen) ? 'delivered' : 'sent',
                text = state === 'seen'
                    ? (id === newest && receipts.seen_at ? T.seenAt.replace('{time}', receipts.seen_at) : T.seen)
                    : state === 'delivered' ? T.delivered : T.sent;

            mark.attr('data-state', state);
            mark.find('.gesoft-receipt-ticks').text(state === 'sent' ? '✓' : '✓✓');
            mark.find('.gesoft-receipt-text').text(text);
        });
    }

    function typingBeat(id) {
        if (typingBusy || document.hidden) { return; }
        typingBusy = true;

        $.post(base() + '/gesoft-live-chat/agent/' + id + '/typing', {
            _token: csrf(),
            typing: writingReply() ? 1 : 0,
            seen: newestVisitorMessageShown()
        })
            .done(function (res) {
                paintReceipts(res && res.receipts);

                // Found again every time rather than kept: core redraws parts
                // of the conversation page when a message arrives.
                var box = $('.gesoft-chat-typing').first(), on = !!(res && res.visitor_typing);
                box.find('.gesoft-typing-text').text(on ? T.typing : '');
                box.prop('hidden', !on);

                // The visitor said something this page does not show yet.
                // Core's realtime poll would bring it within five seconds;
                // this beat already runs every three, so use it.
                var latest = parseInt(res && res.latest_customer_thread_id, 10);
                if (latest && !document.getElementById('thread-' + latest)) {
                    refreshConversation();
                }
            })
            .always(function () { typingBusy = false; });
    }

    function watchTyping() {
        var id = $('.gesoft-chat-typing').first().data('conversation-id');
        if (!id) { return; }

        $(document).on('input', '.form-reply .note-editable', function () {
            var was = writingReply();
            typedAt = $.trim($(this).text()) !== '' ? Date.now() : 0;

            // The first keystroke after a pause is told at once rather than
            // at the next beat.
            if (!was && writingReply()) { typingBeat(id); }
        });

        typingBeat(id);
        setInterval(function () { typingBeat(id); }, TYPING_MS);

        // A hidden page skips its beats. Coming back, catch up at once rather
        // than at the next one.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { typingBeat(id); }
        });
    }

    // A chat in Chat Mode, read like a chat window: the editor fixed at the
    // bottom of the window, and the messages above it in a pane of their own,
    // where a new one appears just above the editor and the rest move up.
    // operator.css has already turned the messages around on the server's mark.
    //
    // The page scrolling instead, as it first did, leaves the editor under the
    // last message of a short chat, moving down the screen with every new one.
    //
    // This runs before core's own start-up shows the editor, which core keeps
    // hidden until then, so the editor is never seen above the messages first.
    // A browser without `:has()` cannot turn the messages around; the editor
    // then stays where core put it, because half of this layout would be worse
    // than either whole one.
    var chatLayout = null;

    function newestAtBottom() {
        var mark = $('#conv-layout-main > .gesoft-chat-layout');
        if (!mark.length) { return; }

        var editor = $('#conv-layout-header .conv-action-wrapper').first(), turned = false;
        try { turned = !!(window.CSS && CSS.supports('selector(:has(*))')); } catch (e) {}
        if (!editor.length || !turned) {
            mark.remove();
            return;
        }

        var newer = $('<button type="button" class="gesoft-chat-newer" hidden></button>').text(T.newer + ' ↓');
        editor.addClass('gesoft-chat-composer').prepend(newer).insertAfter('#conv-layout-main');

        // The customer panel, with Start Remote Support, scrolls in its own
        // box beside the messages when it is taller than the window.
        $('#conv-layout-customer').wrapInner('<div class="gesoft-chat-aside"></div>');

        // Core binds "switch to a note" inside the subject block on its own
        // start-up, and finds nothing there now.
        $(document).on('click', '.gesoft-chat-composer .switch-to-note', function (e) {
            if (e.isDefaultPrevented() || typeof switchToNote !== 'function') { return; }
            e.preventDefault();
            switchToNote();
        });

        var main = document.getElementById('conv-layout-main'),
            composer = editor[0],
            atBottom = true,
            height = main.scrollHeight;

        // The messages' pane takes what the window leaves between the top of
        // the pane and the editor, so the editor ends at the bottom of the
        // window. Never less than a few messages' worth; on a small screen
        // the page scrolls as well, and the editor stays in view there too.
        function fit() {
            var top = main.getBoundingClientRect().top + window.pageYOffset;
            main.style.height = Math.max(Math.floor(window.innerHeight - top - composer.offsetHeight), 240) + 'px';
        }

        // A reversed pane counts its scroll from the bottom: 0 is the newest
        // message, and reading further up is negative.
        function toBottom() {
            main.scrollTop = 0;
            atBottom = true;
            newer.prop('hidden', true);
        }

        // New content arrives at the bottom. At the bottom the pane shows it
        // by itself. Further up, reading, the agent stays on what they were
        // reading — the browser's own anchoring is switched off in the CSS so
        // that this is the only thing that moves the pane — and a message is
        // announced rather than scrolled to.
        function grown(announce) {
            var by = main.scrollHeight - height;
            height = main.scrollHeight;
            if (atBottom) { main.scrollTop = 0; return; }
            if (by) { main.scrollTop -= by; }
            if (announce) { newer.prop('hidden', false); }
        }

        main.addEventListener('scroll', function () {
            atBottom = Math.abs(main.scrollTop) < 40;
            height = main.scrollHeight;
            if (atBottom) { newer.prop('hidden', true); }
        });
        newer.on('click', toBottom);

        if (window.MutationObserver) {
            new MutationObserver(function (changes) {
                var message = false;
                for (var i = 0; i < changes.length && !message; i++) {
                    for (var j = 0; j < changes[i].addedNodes.length; j++) {
                        if ($(changes[i].addedNodes[j]).is('.thread')) { message = true; break; }
                    }
                }
                grown(message);
            }).observe(main, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
        }
        // A picture in a message changes the height when it loads.
        main.addEventListener('load', function () { grown(false); }, true);

        // The editor grows with a long reply, a note's extra fields or an
        // attachment; the header with "Show Details"; the window is resized.
        if (window.ResizeObserver) {
            var resized = new ResizeObserver(fit);
            resized.observe(composer);
            resized.observe(document.getElementById('conv-layout-header'));
        }
        $(window).on('resize', fit);

        chatLayout = { toBottom: toBottom };
        fit();
        toBottom();
    }

    // The editor's placeholder goes with the first character, not after a pause.
    //
    // Core's editor, Summernote 0.8.9, hides it on its `change` event, which it
    // debounces by 100 ms — and a debounce fires only once typing stops, so the
    // first words of a reply were written over "Use ENTER to send the message".
    // `input` comes before the character is drawn. Showing it again when the
    // editor is emptied stays Summernote's.
    $(document).on('input compositionstart', '.note-editable', function (e) {
        var placeholder = $(this).closest('.note-editing-area').children('.note-placeholder');
        if (!placeholder.length) { return; }
        if (e.type === 'compositionstart' || $.trim($(this).text()) !== '' || $(this).find('img').length) {
            placeholder.hide();
        }
    });

    // A reply in a chat stays on the page.
    //
    // After an agent sends a chat reply, core reloads the whole conversation
    // (`window.location.href = ''` in main.js): the editor is disabled, the
    // page blinks and forgets where it was scrolled, and the agent waits for
    // it to come back before writing the next line. Only that reload is
    // replaced. Validation, drafts and the send itself stay core's, through
    // core's own request; this sees the answer first — for a chat reply in
    // chat mode only, never a note, never an email.
    //
    // If putting the page right throws, core's reload runs as before.
    function keepChatRepliesOnPage() {
        if (typeof window.fsAjax !== 'function'
            || !$('#conv-layout').hasClass('conv-type-chat')
            || !$('body').hasClass('chat-mode')
        ) {
            return;
        }

        var coreAjax = window.fsAjax;

        window.fsAjax = function (data, url, success_callback, no_loader, error_callback, custom_options) {
            var chatReply = typeof data === 'string'
                && /(^|&)action=send_reply(&|$)/.test(data)
                && !/(^|&)is_note=1(&|$)/.test(data)
                && typeof success_callback === 'function';

            if (!chatReply) {
                return coreAjax.apply(this, arguments);
            }

            return coreAjax.call(this, data, url, function (response) {
                if (!response || response.status !== 'success') {
                    return success_callback(response);
                }
                try {
                    replySent();
                } catch (e) {
                    return success_callback(response);
                }
            }, no_loader, error_callback, custom_options);
        };
    }

    // What core's callback and the reload between them used to reset.
    function replySent() {
        window.fs_processing_send_reply = false;
        if (typeof loaderHide === 'function') { loaderHide(); }

        var form = $('.form-reply:first');

        // The draft this reply was autosaved as is a sent message now. Left in
        // the form, the next autosave or send would point at it, and core
        // refuses that with "Message has been already sent".
        form.find(':input[name="thread_id"]').val('');
        form.find(':input[name="saved_reply_id"]').val('');
        $('.attachments-upload:first :input, .attachments-upload:first li').remove();

        var body = $('#body');
        body.summernote('enable');
        body.summernote('code', '');
        body.val('');
        window.fs_reply_changed = false;
        $('.btn-reply-submit').button('reset');
        typedAt = 0;

        // The agent's own reply is always followed, wherever they were.
        if (chatLayout) { chatLayout.toBottom(); }
        refreshConversation();
        body.summernote('focus');
    }

    // The reply as the server now shows it, and the status and assignee it
    // left behind — a reply makes a chat pending and the agent's — from the
    // conversation page, fetched in the background.
    var refreshing = false;

    function refreshConversation() {
        if (refreshing) { return; }
        refreshing = true;

        $.get(window.location.href).always(function () { refreshing = false; }).done(function (html) {
            var page = $('<div>').append($.parseHTML(String(html)));
            var main = $('#conv-layout-main');

            // Messages not on screen yet, in the order the page lists them,
            // newest first, ahead of the ones already there: where core's own
            // realtime handler puts a colleague's reply. A chat in Chat Mode
            // shows the list turned around, so they appear at the bottom.
            var fresh = page.find('#conv-layout-main > .thread[id^="thread-"]').filter(function () {
                return !document.getElementById(this.id);
            });
            if (fresh.length) {
                var first = main.children('.thread').first();
                if (first.length) { fresh.insertBefore(first); } else { main.append(fresh); }
                // The page was drawn a moment ago; the beat may know better.
                paintReceipts(lastReceipts);
            }

            syncMenu(page, '#conv-status', '.conv-status', 'data-status');
            syncMenu(page, '#conv-assignee', '.conv-user', 'data-user_id');
        });
    }

    // The same few changes core's realtime handler makes when a colleague
    // changes the status or the assignee: the active item, the label, and the
    // button's colour and icon.
    function syncMenu(page, block, list, attr) {
        var value = page.find(block + ' ' + list + ' li.active a:first').attr(attr);
        var here = $(block);
        if (value === undefined || here.find(list + ' li.active a:first').attr(attr) === value) {
            return;
        }

        here.find(list + ' li.active').removeClass('active');
        var item = here.find(list + ' li a[' + attr + '="' + value + '"]').first();
        item.parent().addClass('active');
        here.find('.conv-info-val span:first').text(item.text());

        var theirs = page.find(block + ' .btn');
        here.find('.btn').each(function (i) {
            if (theirs[i]) { this.className = theirs[i].className; }
        });
        var icon = page.find(block + ' .btn:first .glyphicon').attr('class');
        if (icon) { here.find('.btn:first .glyphicon').attr('class', icon); }
    }

    $(function () {
        if (started) { return; }
        started = true;

        // Only for signed-in agents; the visitor bubble shares nothing here.
        if (!$('meta[name="csrf-token"]').length) { return; }

        // First, before core's own start-up shows the reply editor.
        try { newestAtBottom(); } catch (e) {}

        refresh(false);
        setInterval(function () { refresh(true); }, POLL_MS);
        subscribe(10);
        watchTyping();
        keepChatRepliesOnPage();
    });
})(jQuery);
