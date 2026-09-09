{{--
    "Are you still there?" in the conversation's More Actions menu.

    Chat conversations only: on an email conversation the question makes no
    sense, and the hook fires on every conversation there is.

    The hook renders inside a <ul>, so this emits an <li>.
--}}
<li>
    <a href="#" class="gesoft-chat-nudge" data-conversation-id="{{ $conversation->id }}">
        {{ __('Ask if the customer is still there') }}
    </a>
</li>
