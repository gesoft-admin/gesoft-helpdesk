{{--
    The remote-support panel, rendered into the conversation sidebar by the
    `conversation.after_customer_sidebar` hook.

    Every value comes from the $conversation the hook hands us, from the
    authenticated agent, or from this module's own row for the conversation —
    which is what helpdesk-rust told this server. No credential reaches this
    template: the backend's address and its ops token stay in config, and the
    panel's own routes are the browser's only way to reach either.
--}}
<div class="conv-sidebar-block gesoft-rs-block"
     id="gesoft-rs-panel"
     data-conversation-id="{{ $conversation->id }}"
     data-url-start="{{ route('gesoftremotesupport.start', ['conversation_id' => $conversation->id]) }}"
     data-url-close="{{ route('gesoftremotesupport.close', ['conversation_id' => $conversation->id]) }}"
     data-url-status="{{ route('gesoftremotesupport.status', ['conversation_id' => $conversation->id]) }}">

    <div class="sidebar-block-header2">
        <strong>{{ __('Gesoft Remote Support') }}</strong>
    </div>

    <div class="gesoft-rs-body">
        <ul class="sidebar-block-list gesoft-rs-list">
            <li>
                <span class="gesoft-rs-key">{{ __('Status') }}</span>
                <span class="label {{ $session->statusClass() }} gesoft-rs-status">{{ $session->statusLabel() }}</span>
            </li>
            <li>
                <span class="gesoft-rs-key">{{ __('Session') }}</span>
                <span class="gesoft-rs-val gesoft-rs-session-ref">{{ $session->helpdesk_session_id ? '#'.$session->helpdesk_session_id : '—' }}</span>
            </li>
            <li>
                <span class="gesoft-rs-key">{{ __('Code') }}</span>
                <span class="gesoft-rs-val gesoft-rs-code">{{ $session->code ?: '—' }}</span>
            </li>
            <li class="gesoft-rs-link-row" @if (!$session->customerUrl()) style="display:none" @endif>
                <span class="gesoft-rs-key">{{ __('Customer link') }}</span>
                <span class="gesoft-rs-val">
                    <a href="{{ $session->customerUrl() ?: '#' }}" class="gesoft-rs-link" target="_blank" rel="noopener noreferrer">{{ $session->customerUrl() ?: '' }}</a>
                    <button type="button" class="btn btn-link btn-xs gesoft-rs-copy" title="{{ __('Copy') }}">{{ __('Copy') }}</button>
                </span>
            </li>
            <li>
                <span class="gesoft-rs-key">{{ __('RustDesk ID') }}</span>
                <span class="gesoft-rs-val gesoft-rs-remote-id">{{ $session->remote_id ?: '—' }}</span>
            </li>
            {{--
                An ID on its own never meant the client reached us: one that
                found another rendezvous server reports an ID too. This row is
                the backend's answer to that, checked against hbbs, and it is
                shown next to the ID rather than folded into the status so the
                two claims stay separable.
            --}}
            <li class="gesoft-rs-registration-row" @if (!$session->registrationLabel()) style="display:none" @endif>
                <span class="gesoft-rs-key">{{ __('Registration') }}</span>
                <span class="label {{ $session->registrationClass() }} gesoft-rs-registration">{{ $session->registrationLabel() }}</span>
            </li>
            <li class="gesoft-rs-expires-row" @if (!$session->expires_at) style="display:none" @endif>
                <span class="gesoft-rs-key">{{ __('Expires') }}</span>
                <span class="gesoft-rs-val gesoft-rs-expires">{{ $session->expires_at ? $session->expires_at->toDateTimeString() : '' }}</span>
            </li>
            <li>
                <span class="gesoft-rs-key">{{ __('Conversation') }}</span>
                <span class="gesoft-rs-val">#{{ $conversation->number }} <span class="text-help">(id {{ $conversation->id }})</span></span>
            </li>
            <li>
                <span class="gesoft-rs-key">{{ __('Mailbox') }}</span>
                <span class="gesoft-rs-val">{{ $conversation->mailbox->name ?? '—' }}</span>
            </li>
            <li>
                <span class="gesoft-rs-key">{{ __('Agent') }}</span>
                <span class="gesoft-rs-val">{{ $user->getFullName() }} <span class="text-help">(id {{ $user->id }})</span></span>
            </li>
        </ul>

        {{--
            Deliberately not "Connected" or "Online". The backend reports that
            a client has told it an ID, which is not the same as that client
            being registered, reachable, or in a session with anyone.
        --}}
        <div class="gesoft-rs-hint text-help" @if (!$session->isActive()) style="display:none" @endif>
            {{ __('Send the customer link. The RustDesk ID appears here once their client reports it.') }}
        </div>

        <div class="gesoft-rs-actions">
            <button type="button" class="btn btn-primary btn-sm gesoft-rs-start" @if ($session->isActive() || $session->isStarting()) disabled @endif>
                {{ __('Start Remote Support') }}
            </button>
            <button type="button" class="btn btn-default btn-sm gesoft-rs-close" @if (!$session->isActive()) disabled @endif>
                {{ __('Close Session') }}
            </button>
        </div>

        <div class="gesoft-rs-msg text-help"></div>
    </div>
</div>
