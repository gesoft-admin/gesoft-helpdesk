/**
 * Tells an agent that somebody is waiting in a chat, from anywhere in the
 * interface.
 *
 * FreeScout does not. Its Chats link in the sidebar carries no count, and the
 * realtime channel that announces a new chat is subscribed to only when the
 * sidebar is *already* showing the chat list — `main.js` guards it on
 * `#folders.chats`. So the one property that makes chat different from email,
 * that somebody is waiting right now, is invisible to an agent reading a
 * ticket.
 *
 * This adds three things and patches no core file:
 *
 *   - a count on the Chats link, and a highlight while it is non-zero;
 *   - a sound and a browser notification when a new chat arrives, on any page;
 *   - an immediate update when core's own realtime event fires, so the badge
 *     does not wait for the next poll.
 *
 * It reuses what core already loads — the Polycast connection, the audio cue,
 * the Push library — rather than opening a second channel for the same news.
 */
(function ($) {
    'use strict';

    // The floor, not the mechanism: the realtime subscription below answers
    // within a second. Ten rather than twenty because this is how long an agent
    // can be unaware that somebody is waiting when the subscription is not
    // available, and twenty seconds of that is too long.
    var POLL_MS = 10000;

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
                '  <a href="#" class="dropdown-toggle-icon" title="Chaturi active">' +
                '    <i class="glyphicon glyphicon-comment"></i>' +
                '    <small class="gesoft-chat-header-count"></small>' +
                '  </a>' +
                '</li>'
            );
            bar.prepend(item);
        }

        item.find('.gesoft-chat-header-count').text(count);
        if (mailbox_id) {
            item.find('a').attr('href', Vars.public_url + '/mailbox/' + mailbox_id + '/chats');
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
            ? res.new_conversations + ' chaturi cu mesaje noi'
            : (res.latest_name ? 'Mesaj nou de la ' + res.latest_name : 'Mesaj nou în chat');

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
                showFloatingAlert('success', target ? title + ' — click pentru a deschide' : title);

                // Core's alert is plain text and not a link. It appends to the
                // body and returns, so the last one is the one just made.
                if (target) {
                    $('.alert-floating').last()
                        .addClass('gesoft-chat-alert')
                        .attr('title', 'Deschide')
                        .on('click', function () { window.location.href = target; });
                }
            } catch (e) {}
        }

        if (typeof Push === 'undefined' || !Push.Permission.has()) { return; }

        try {
            Push.create(title, {
                body: several ? 'Deschide lista de chaturi.' : 'Un vizitator așteaptă un răspuns.',
                timeout: 8000,
                onClick: function () {
                    window.focus();
                    if (target) { window.location.href = target; }
                    this.close();
                }
            });
        } catch (e) {}
    }

    // Whether the visitor in each chat is still there, as a dot before the
    // name with the same thing said in its tooltip, so it never rests on colour
    // alone. The conversation itself carries a line when somebody leaves.
    var presence = {};
    var PRESENCE_TITLE = {
        here: 'Clientul este în chat',
        left: 'Clientul a părăsit chatul',
        ended: 'Clientul a încheiat chatul'
    };

    function paintPresence() {
        $('.chats li.chat-item[data-chat_id]').each(function () {
            var li = $(this), state = presence[li.attr('data-chat_id')];

            li.removeClass('gesoft-presence-here gesoft-presence-left gesoft-presence-ended');
            if (!state) { return; }

            li.addClass('gesoft-presence-' + state);
            li.find('.folder-name').first().attr('title', PRESENCE_TITLE[state] || '');
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

        var u = base() + '/gesoft-live-chat/agent/' + id + '/nudge';

        $.post(u, { _token: $('meta[name="csrf-token"]').attr('content') })
            .done(function () { window.location.reload(); })
            .fail(function () { link.data('busy', false); });
    });

    $(function () {
        if (started) { return; }
        started = true;

        // Only for signed-in agents; the visitor bubble shares nothing here.
        if (!$('meta[name="csrf-token"]').length) { return; }

        refresh(false);
        setInterval(function () { refresh(true); }, POLL_MS);
        subscribe(10);
    });
})(jQuery);
