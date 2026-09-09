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

    // Slow, because it is only the floor: the realtime subscription below
    // updates within a second when it is available, and this is what keeps the
    // badge honest on pages where it is not.
    var POLL_MS = 20000;

    var url = null;
    var known_latest = null;
    var started = false;

    function endpoint() {
        if (url) { return url; }
        try { url = laroute.route('gesoftlivechat.agent.chats'); } catch (e) { url = null; }
        return url;
    }

    function chatsLink() {
        // Core renders this link by hand, with no id and no class of its own,
        // so it is found by where it goes rather than by what it is called.
        return $('#folders a[href*="chat_mode=1"]').filter(function () {
            return $(this).find('.folder-name').length > 0;
        }).first();
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

    function announce(name) {
        // Core's own cue, so chat sounds like the rest of the application
        // rather than like a second product bolted on.
        if (typeof playAudioNotification === 'function') {
            try { playAudioNotification(null); } catch (e) {}
        }

        if (typeof Push === 'undefined' || !Push.Permission.has()) { return; }

        try {
            Push.create(name ? 'Chat nou: ' + name : 'Chat nou', {
                body: 'Un vizitator așteaptă un răspuns.',
                timeout: 8000,
                onClick: function () { window.focus(); this.close(); }
            });
        } catch (e) {}
    }

    function refresh(announce_new) {
        var u = endpoint();
        if (!u) { return; }

        $.getJSON(u).done(function (res) {
            if (!res || res.status !== 'success') { return; }

            paint(res.count);

            // First answer of the page load only establishes what is already
            // there. Announcing then would greet an agent with a notification
            // for every chat that was open before they sat down.
            if (known_latest === null) {
                known_latest = res.latest_id;
                return;
            }

            if (announce_new && res.latest_id && res.latest_id > known_latest) {
                announce(res.latest_name);
            }
            known_latest = res.latest_id;
        });
    }

    // Ride core's existing Polycast connection rather than opening another.
    // `poly` is a file-scope global in main.js and is created on ready, so this
    // waits for it instead of assuming an order between two scripts.
    function subscribe(attempts) {
        if (typeof poly === 'undefined' || !poly || !poly.subscribe) {
            if (attempts > 0) { setTimeout(function () { subscribe(attempts - 1); }, 1000); }
            return;
        }

        var mailbox_id = null;
        try { mailbox_id = getGlobalAttr('mailbox_id'); } catch (e) {}
        if (!mailbox_id) { return; }

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

        var u;
        try { u = laroute.route('gesoftlivechat.agent.nudge', { conversation_id: id }); }
        catch (err) { link.data('busy', false); return; }

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
