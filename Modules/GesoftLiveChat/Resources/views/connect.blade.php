{{--
    The popup an application opens to connect itself: /gesoft-live-chat/apps/connect.

    It shows nothing of its own until its opener has posted a request, and the
    address it shows is the one the browser attached to that message, which the
    application cannot choose. See `ConnectController` for the whole flow.
--}}
@extends('layouts.app')

@section('title', __('Connect an application'))

@section('content')
<div class="container gesoft-connect" style="max-width: 640px;">
    <div class="flexy-container">
        <div class="flexy-item">
            <span class="heading">{{ __('Connect an application') }}</span>
        </div>
    </div>

    <div class="margin-top gesoft-connect-state" data-state="waiting">
        <p class="text-help">{{ __('Waiting for the application…') }}</p>
    </div>

    <div class="margin-top gesoft-connect-state hidden" data-state="no-opener">
        <p>{{ __('Open this page from the settings of the application you want to connect.') }}</p>
    </div>

    <div class="margin-top gesoft-connect-state hidden" data-state="ask">
        <p>{{ __('This application asks to show the helpdesk chat to its signed-in users:') }}</p>
        <table class="table">
            <tr><th style="width: 30%;">{{ __('Application') }}</th><td class="gesoft-connect-name"></td></tr>
            <tr><th>{{ __('Address') }}</th><td><strong><code class="gesoft-connect-origin" style="font-size: 1.15em;"></code></strong></td></tr>
            <tr class="gesoft-connect-reconnect hidden"><th>{{ __('Replaces the key of') }}</th><td><code class="gesoft-connect-provider"></code></td></tr>
        </table>
        <p class="text-help">{{ __('Approve only if this is the address you opened the application at. The application will be able to tell this helpdesk who its signed-in users are.') }}</p>
        <p class="alert alert-warning gesoft-connect-http hidden">{{ __('This address is not encrypted (http). Accept it only for an application on a private network.') }}</p>
        <p class="alert alert-danger gesoft-connect-error hidden"></p>
        <button type="button" class="btn btn-primary gesoft-connect-approve">{{ __('Approve') }}</button>
        <button type="button" class="btn btn-link gesoft-connect-cancel">{{ __('Cancel') }}</button>
    </div>

    <div class="margin-top gesoft-connect-state hidden" data-state="done">
        <p class="alert alert-success">{{ __('Connected. You can close this window.') }}</p>
    </div>

    <div class="margin-top gesoft-connect-state hidden" data-state="cancelled">
        <p>{{ __('Not connected. You can close this window.') }}</p>
    </div>
</div>
@endsection

@section('javascript')
    @parent
    (function () {
        var PREFIX = 'gesoft-helpdesk/connect:';
        var APPROVE = {!! json_encode(route('gesoftlivechat.apps.approve')) !!};
        var ERRORS = {!! json_encode([
            'origin'           => __('This address cannot be accepted: a public address has to use https.'),
            'origin_mismatch'  => __('The application is asking to replace the key of a connection made from another address.'),
            'unknown_provider' => __('The application is asking to replace the key of a connection this helpdesk does not have.'),
            'key'              => __('The application sent a key that is not valid.'),
            'name'             => __('The application did not say what it is called.'),
            'other'            => __('Could not connect the application. Try again.'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

        var opener = window.opener;
        var request = null;
        var from = null;

        function state(name) {
            $('.gesoft-connect-state').addClass('hidden').filter('[data-state="' + name + '"]').removeClass('hidden');
        }

        function tell(type, data) {
            if (!opener || !from) { return; }
            try { opener.postMessage($.extend({ type: PREFIX + type, state: request.state }, data || {}), from); } catch (e) {}
        }

        if (!opener) { state('no-opener'); return; }

        // Only the window that opened this one, and only the first request:
        // what is on screen while the administrator decides must not change
        // under them.
        window.addEventListener('message', function (e) {
            if (e.source !== opener || request) { return; }

            var m = e.data;
            if (!m || typeof m !== 'object' || m.type !== PREFIX + 'request') { return; }
            if (typeof m.name !== 'string' || typeof m.public_key !== 'string' || typeof m.state !== 'string'
                || m.state.length > 128 || m.public_key.length > 64 || !/^https?:\/\//.test(e.origin)) { return; }
            if (m.provider !== undefined && m.provider !== null && typeof m.provider !== 'string') { return; }

            request = m;
            from = e.origin;

            $('.gesoft-connect-name').text(m.name.slice(0, 60));
            $('.gesoft-connect-origin').text(from);
            $('.gesoft-connect-http').toggleClass('hidden', from.indexOf('http:') !== 0);
            if (m.provider) {
                $('.gesoft-connect-provider').text(m.provider);
                $('.gesoft-connect-reconnect').removeClass('hidden');
            }
            state('ask');
        });

        $('.gesoft-connect-approve').on('click', function () {
            var button = $(this).prop('disabled', true);
            $('.gesoft-connect-error').addClass('hidden');

            $.ajax({
                url: APPROVE,
                method: 'POST',
                dataType: 'json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: { origin: from, name: request.name, public_key: request.public_key, provider: request.provider || '' }
            }).done(function (res) {
                tell('done', { provider: res.provider, audience: res.audience, fingerprint: res.fingerprint });
                state('done');
                setTimeout(function () { window.close(); }, 1500);
            }).fail(function (xhr) {
                var code = xhr.responseJSON && xhr.responseJSON.code;
                $('.gesoft-connect-error').text(ERRORS[code] || ERRORS.other).removeClass('hidden');
                button.prop('disabled', false);
            });
        });

        $('.gesoft-connect-cancel').on('click', function () {
            tell('cancelled');
            state('cancelled');
            setTimeout(function () { window.close(); }, 800);
        });

        // Carries nothing, so it can go to whoever opened us; the request it
        // asks for is what gets checked.
        try { opener.postMessage({ type: PREFIX + 'ready' }, '*'); } catch (e) {}
    })();
@endsection
