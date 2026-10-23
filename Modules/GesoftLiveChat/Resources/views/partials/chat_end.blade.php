{{--
    "End chat" in the conversation toolbar, on an open chat conversation.

    The same markup as core's toolbar actions, so it sits and behaves like
    Reply and Note beside it. operator.js asks before it ends anything, in the
    words carried here.
--}}
<span class="conv-action glyphicon glyphicon-off gesoft-chat-end"
      data-conversation-id="{{ $conversation->id }}"
      data-confirm="{{ __('End this chat? The customer is told it has ended.') }}"
      data-toggle="tooltip"
      data-placement="bottom"
      title="{{ __('End chat') }}"
      aria-label="{{ __('End chat') }}"
      role="button"></span>
