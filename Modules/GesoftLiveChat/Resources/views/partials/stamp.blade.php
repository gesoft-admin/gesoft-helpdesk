{{--
    Under a chat message: the time it was written and, under an agent's reply,
    whether the visitor has received and seen it.

    The time is for Chat Mode, where messages are drawn as bubbles and core's
    header with "5 minutes ago" is left out; operator.css shows it only there.
    Today's messages show the hour, older ones the day as well, in the agent's
    own time zone and clock.

    The receipt: one tick once the reply is sent, two once the bubble fetched
    it, and "Seen" once it was on the visitor's screen. operator.js repaints it
    from the beat; the words here are what it starts from.
--}}
@php
    $written = $thread->created_at;
    $today = \App\User::dateFormat($written, 'Y-m-d', null, false) === \App\User::dateFormat(\Carbon\Carbon::now(), 'Y-m-d', null, false);
@endphp
<div class="thread-meta gesoft-chat-stamp">
    <span class="gesoft-chat-time" title="{{ \App\User::dateFormat($written) }}">{{ \App\User::dateFormat($written, $today ? 'H:i' : 'd.m H:i') }}</span>
    @if (!empty($receipt))
        <span class="gesoft-chat-receipt" data-thread-id="{{ $thread->id }}" data-state="{{ $receipt['state'] }}">
            <span class="gesoft-receipt-ticks" aria-hidden="true">{{ $receipt['state'] === 'sent' ? '✓' : '✓✓' }}</span>
            <span class="gesoft-receipt-text">{{ $receipt['label'] }}</span>
        </span>
    @endif
</div>
