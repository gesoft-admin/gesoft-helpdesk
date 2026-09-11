<?php

/**
 * Operator-only endpoints. `web` gives session + CSRF, the controller adds
 * `auth` and the per-conversation policy check. There is deliberately no
 * public route here: nothing a customer reaches touches this module.
 */
Route::group([
    'middleware' => 'web',
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\GesoftRemoteSupport\Http\Controllers',
], function () {
    Route::post('/gesoft-remote-support/{conversation_id}/start', [
        'uses'    => 'GesoftRemoteSupportController@start',
        'laroute' => true,
    ])->name('gesoftremotesupport.start');

    Route::post('/gesoft-remote-support/{conversation_id}/close', [
        'uses'    => 'GesoftRemoteSupportController@close',
        'laroute' => true,
    ])->name('gesoftremotesupport.close');

    // Read-only, so GET: the panel polls it while a session is open. It is the
    // browser's only way to learn what helpdesk-rust says, and it goes through
    // this server precisely so the browser never needs the ops token.
    Route::get('/gesoft-remote-support/{conversation_id}/status', [
        'uses'    => 'GesoftRemoteSupportController@status',
        'laroute' => true,
    ])->name('gesoftremotesupport.status');

    // A one-time link for the agent's own RustDesk client. POST: every call
    // mints a link in the backend, which is a change, not a read.
    Route::post('/gesoft-remote-support/{conversation_id}/technician', [
        'uses'    => 'GesoftRemoteSupportController@technician',
        'laroute' => true,
    ])->name('gesoftremotesupport.technician');

    // Whether the agent's own address can reach our RustDesk server: GET
    // reads it, POST admits it. The panel shows the answer above Start.
    Route::match(['get', 'post'], '/gesoft-remote-support/{conversation_id}/access', [
        'uses'    => 'GesoftRemoteSupportController@technicianAccess',
        'laroute' => true,
    ])->name('gesoftremotesupport.access');
});
