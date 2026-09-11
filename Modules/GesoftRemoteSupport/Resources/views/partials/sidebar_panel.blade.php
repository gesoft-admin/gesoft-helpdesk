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
     data-url-status="{{ route('gesoftremotesupport.status', ['conversation_id' => $conversation->id]) }}"
     data-url-technician="{{ route('gesoftremotesupport.technician', ['conversation_id' => $conversation->id]) }}"
     data-url-access="{{ route('gesoftremotesupport.access', ['conversation_id' => $conversation->id]) }}">

    <div class="sidebar-block-header2">
        <strong>{{ __('Gesoft Remote Support') }}</strong>
    </div>

    <div class="gesoft-rs-body">
        {{--
            Whether the agent's own machine can reach our RustDesk server. The
            firewall opens per address, so an agent whose address was never
            admitted cannot connect to anyone, and nothing else on screen would
            say so. Filled in by module.js; Start renews it.
        --}}
        <div class="gesoft-rs-access" style="display:none">
            <div class="gesoft-rs-access-msg"></div>
            <div class="gesoft-rs-access-other text-help" style="display:none">{{ __('RustDesk on another computer or network? Use "My RustDesk client" below, from that computer.') }}</div>
            <button type="button" class="btn btn-default btn-xs gesoft-rs-access-grant" style="display:none"
                    data-label-open="{{ __('Open access from this computer') }}"
                    data-label-extend="{{ __('Extend access') }}"></button>
        </div>

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

        {{--
            The agent's own RustDesk client. It has to be pointed at our server,
            and our firewall has to let the agent's machine in; a link used from
            that machine does both. The links are filled in by module.js from
            the technician route, and expire in minutes.
        --}}
        <div class="gesoft-rs-tech">
            <button type="button" class="btn btn-link btn-xs gesoft-rs-tech-get">{{ __('My RustDesk client') }}</button>
            <div class="gesoft-rs-tech-links" style="display:none">
                <div><span class="gesoft-rs-key">Windows</span> <a href="#" class="gesoft-rs-tech-windows">{{ __('Download (.exe)') }}</a></div>
                <div class="gesoft-rs-tech-linux-row">
                    <span class="gesoft-rs-key">Linux</span> <button type="button" class="btn btn-link btn-xs gesoft-rs-tech-copy">{{ __('Copy command') }}</button>
                    <code class="gesoft-rs-tech-linux"></code>
                </div>
                <div><span class="gesoft-rs-key">{{ __('Access only') }}</span> <a href="#" class="gesoft-rs-tech-open" target="_blank" rel="noopener noreferrer">{{ __('Open from this machine') }}</a></div>
                <div class="text-help gesoft-rs-tech-note"></div>
            </div>
        </div>

        <div class="gesoft-rs-msg text-help"></div>
    </div>
</div>
