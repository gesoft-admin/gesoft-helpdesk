{{--
    Chat actions in the conversation's More Actions menu: "are you still there?"
    and blocking the visitor.

    Chat conversations only: on an email conversation neither makes sense, and
    the hook fires on every conversation there is. The hook renders inside a
    <ul>, so this emits <li> elements.

    The block dialog is built by operator.js; its labels travel here, already
    translated, so the words live in one place.
--}}
<li>
    <a href="#" class="gesoft-chat-nudge" data-conversation-id="{{ $conversation->id }}">
        <i class="glyphicon glyphicon-question-sign"></i> {{ __('Ask if the customer is still there') }}
    </a>
</li>
<li>
    <a href="#" class="gesoft-chat-block-open" data-conversation-id="{{ $conversation->id }}"
       data-labels="{{ json_encode([
           'title'   => __('Block this visitor'),
           'help'    => __('The visitor cannot start a new chat while the block lasts, and this chat ends for them now.'),
           'ip'      => __('Block the IP address'),
           'email'   => __('Block the email address'),
           'for'     => __('For'),
           'd1'      => __('1 day'),
           'd7'      => __('7 days'),
           'd30'     => __('30 days'),
           'forever' => __('Permanently'),
           'reason'  => __('Reason (optional)'),
           'submit'  => __('Block'),
           'cancel'  => __('Cancel'),
       ]) }}">
        <i class="glyphicon glyphicon-ban-circle"></i> {{ __('Block visitor…') }}
    </a>
</li>
