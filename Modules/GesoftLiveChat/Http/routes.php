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
 *    and starting a conversation has a tighter budget of its own;
 *  - nothing here can reach an operator route. The agent side is `web` + `auth`
 *    as before and shares no code path with this file.
 */
Route::group([
    'middleware' => ['open', 'throttle:30,1'],
    'prefix'     => \Helper::getSubdirectory().'/gesoft-live-chat',
    'namespace'  => 'Modules\GesoftLiveChat\Http\Controllers',
], function () {
    // Preflight, so the bubble can live on a customer's own domain.
    Route::options('/{any}', 'ChatController@preflight')->where('any', '.*');

    // First message: mints the customer, the conversation and the session.
    Route::post('/start', 'ChatController@start')->name('gesoftlivechat.start');

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

    // The demo page, which is a test harness rather than a product: it hosts
    // the bubble on this server so the transport can be exercised end to end
    // before anybody embeds it on a real site. Off unless dev tools are on.
    Route::get('/demo', 'ChatController@demo')->name('gesoftlivechat.demo');
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
});
