{{--
    Whether the visitor has received and seen an agent's chat reply.

    One tick once it is sent, two once the bubble fetched it, and "Seen" once
    it was on the visitor's screen. operator.js repaints this from the beat;
    the words here are what it starts from.
--}}
<div class="thread-meta gesoft-chat-receipt" data-thread-id="{{ $thread->id }}" data-state="{{ $state }}">
    <span class="gesoft-receipt-ticks" aria-hidden="true">{{ $state === 'sent' ? '✓' : '✓✓' }}</span>
    <span class="gesoft-receipt-text">{{ $label }}</span>
</div>
