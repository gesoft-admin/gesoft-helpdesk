<?php

/**
 * The visitor-facing surface. Everything here is reachable without a session,
 * without a login and without a CSRF token, because the customer has none of
 * those — that is what the `open` middleware group is for.
 *
 * Being genuinely public is the whole risk of this module, so three things are
 * true of every route below and none of them are optional:
 *
 *  - the only credential is the visitor's own opaque token, and it addresses
 *    exactly one conversation — never a customer, and never anything found
 *    by what the visitor typed;
 *  - every route is rate limited, because these create rows in the database,
 *    and starting a conversation and sending messages have tighter budgets of
 *    their own;
 *  - nothing here can reach an operator route. The agent side is `web` + `auth`
 *    as before and shares no code path with this file.
 */
// A ceiling per address, not a budget per visitor. Laravel counts every route
// behind this middleware against one key, the address, so a poll, a message
// and "End" all draw on the same number. It used to be 30 a minute: one tab
// polling every three seconds used 20 of them, and a second tab — or a
// colleague in the same office — had messages and "End" refused without a
// word. What a single chat may send is limited in the controller instead.
Route::group([
    'middleware' => ['open', 'throttle:'.((int) config('gesoftlivechat.rate_per_minute') ?: 600).',1'],
    'prefix'     => \Helper::getSubdirectory().'/gesoft-live-chat',
    'namespace'  => 'Modules\GesoftLiveChat\Http\Controllers',
], function () {
    // Preflight, so the bubble can live on a customer's own domain.
    Route::options('/{any}', 'ChatController@preflight')->where('any', '.*');

    // Whether anybody is available: a chat, or the message form.
    Route::get('/status', 'ChatController@status')->name('gesoftlivechat.status');

    // First message: mints the customer, the conversation and the session.
    Route::post('/start', 'ChatController@start')->name('gesoftlivechat.start');

    // A message left while nobody is available; becomes an email conversation.
    Route::post('/offline', 'ChatController@offline')->name('gesoftlivechat.offline');

    // Every message after that.
    Route::post('/send', 'ChatController@send')->name('gesoftlivechat.send');

    // What the agent has said since the visitor last asked. Read-only, so the
    // throttle above is generous enough for a three-second poll.
    Route::get('/poll', 'ChatController@poll')->name('gesoftlivechat.poll');

    // The visitor ended the chat on purpose. The token stops working.
    Route::post('/end', 'ChatController@end')->name('gesoftlivechat.end');

    // The tab is going away, sent as a beacon. Only recorded; the sweep decides
    // later whether the visitor really left.
    Route::post('/leave', 'ChatController@leave')->name('gesoftlivechat.leave');

    // The chat this application user is already in, asked by the embedded
    // page with the permission its own server was handed. It mints nothing a
    // visitor's token cannot already do and names no conversation of its own.
    Route::post('/app/resume', 'AppController@resume')->name('gesoftlivechat.app.resume');

    // The customer's own conversations: the list, one of them, a reply into
    // one, and how far they have read it. Every one of these is authorised
    // through the identity's explicit mapping, never through the customer and
    // never through an address -- see `Entities/AppConversation.php`.
    Route::post('/app/history', 'AppController@history')->name('gesoftlivechat.app.history');
    Route::post('/app/conversation', 'AppController@conversation')->name('gesoftlivechat.app.conversation');
    Route::post('/app/reply', 'AppController@reply')->name('gesoftlivechat.app.reply');
    Route::post('/app/seen', 'AppController@seen')->name('gesoftlivechat.app.seen');

    // The demo page, which is a test harness rather than a product: it hosts
    // the bubble on this server so the transport can be exercised end to end
    // before anybody embeds it on a real site. Off unless dev tools are on.
    Route::get('/demo', 'ChatController@demo')->name('gesoftlivechat.demo');
});

/**
 * The chat as a page of its own, for a link such as helpdesk.example.com/chat:
 * the same bubble, open and filling the window, on the same public footing as
 * the endpoints above.
 */
Route::group([
    'middleware' => ['open', 'throttle:'.((int) config('gesoftlivechat.rate_per_minute') ?: 600).',1'],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\GesoftLiveChat\Http\Controllers',
], function () {
    Route::get('/chat', 'ChatController@page')->name('gesoftlivechat.page');
});

/**
 * The one call a browser never makes: an application's server asking, with a
 * shared secret, for permission for the person it has signed in to chat.
 *
 * Its own budget rather than the visitors' one. That budget is sized for
 * bubbles polling every couple of seconds and is counted per address -- and
 * every call here arrives from the same address, the application's server, so
 * sharing it would mean one busy morning of chats could refuse an application
 * its bootstrap, or the other way about.
 */
Route::group([
    'middleware' => ['open', 'throttle:'.((int) config('gesoftlivechat.app_rate_per_minute') ?: 120).',1'],
    'prefix'     => \Helper::getSubdirectory().'/gesoft-live-chat',
    'namespace'  => 'Modules\GesoftLiveChat\Http\Controllers',
], function () {
    Route::post('/app/session', 'AppController@session')->name('gesoftlivechat.app.session');

    // Two integers for the application's own button, on a page where the panel
    // was never opened. It mints nothing: a badge needs a count, not a
    // permission, and handing out the second to draw the first would be a
    // credential issued on every page view.
    Route::post('/app/unread', 'AppController@unreadCount')->name('gesoftlivechat.app.unread');

    // A diagnostic report about a fault in the application, from that
    // application's server. It is not an upload endpoint and must never become
    // one: the body has to be a report in the shape `Support/Diagnostic.php`
    // describes, and anything else is refused rather than stored.
    Route::post('/app/error-report', 'AppController@errorReport')->name('gesoftlivechat.app.error_report');
});

/**
 * The chat as a panel inside a registered application's page.
 *
 * The one route in this helpdesk that is served **without** core's `FrameGuard`,
 * and the only reason it has a group of its own. `FrameGuard` sets
 * `X-Frame-Options: SAMEORIGIN` on everything in the `web` and `open` groups,
 * and that header cannot express "this one other site may frame this one page"
 * -- `ALLOW-FROM` is gone from every browser that ever had it. So the page
 * states the rule in CSP `frame-ancestors` instead, naming the single origin
 * that asked for it, and does not also emit a header saying the opposite.
 *
 * Nothing else moves: `web` and `open` keep `FrameGuard`, so the operator
 * interface, the dashboard and every other page stay `SAMEORIGIN` exactly as
 * core ships them. The list below is `open` with that one middleware left out.
 */
Route::group([
    'middleware' => [
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \App\Http\Middleware\HttpsRedirect::class,
        \App\Http\Middleware\CustomHandle::class,
        'throttle:'.((int) config('gesoftlivechat.rate_per_minute') ?: 600).',1',
    ],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\GesoftLiveChat\Http\Controllers',
], function () {
    Route::get('/chat/embed', 'ChatController@embed')->name('gesoftlivechat.embed');
});

/**
 * The agent's side. Session and permissions as usual, and no overlap with the
 * visitor routes above: nothing a customer can reach touches this group.
 */
Route::group([
    'middleware' => 'web',
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\GesoftLiveChat\Http\Controllers',
], function () {
    Route::get('/gesoft-live-chat/agent/chats', [
        'uses'    => 'AgentController@chats',
        'laroute' => true,
    ])->name('gesoftlivechat.agent.chats');

    Route::post('/gesoft-live-chat/agent/{conversation_id}/nudge', [
        'uses'    => 'AgentController@nudge',
        'laroute' => true,
    ])->name('gesoftlivechat.agent.nudge');

    Route::post('/gesoft-live-chat/agent/{conversation_id}/typing', [
        'uses' => 'AgentController@typing',
    ])->name('gesoftlivechat.agent.typing');

    Route::post('/gesoft-live-chat/agent/{conversation_id}/block', [
        'uses' => 'AgentController@block',
    ])->name('gesoftlivechat.agent.block');

    Route::get('/gesoft-live-chat/blocks', [
        'uses' => 'AgentController@blocks',
    ])->name('gesoftlivechat.agent.blocks');

    Route::post('/gesoft-live-chat/blocks/{id}/delete', [
        'uses' => 'AgentController@unblock',
    ])->name('gesoftlivechat.agent.unblock');

    // The one way a diagnostic report is ever read. Session, `auth` in the
    // controller, and the conversation's own policy -- deliberately not core's
    // attachment route, which asks for none of the three.
    Route::get('/gesoft-live-chat/diagnostic/{id}', [
        'uses' => 'AgentController@diagnostic',
    ])->name('gesoftlivechat.agent.diagnostic');
});
