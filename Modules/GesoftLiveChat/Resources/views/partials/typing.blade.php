{{--
    "The customer is typing…", above the conversation's messages.

    Chat conversations only. Hidden until operator.js hears that the visitor is
    typing; the sentence is written there, in the agent's language, so it
    never rests on the dots alone.
--}}
<div class="gesoft-chat-typing" data-conversation-id="{{ $conversation->id }}" aria-live="polite" hidden>
    <span class="gesoft-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
    <span class="gesoft-typing-text"></span>
</div>
