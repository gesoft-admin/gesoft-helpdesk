<?php

namespace Modules\GesoftLiveChat\Providers;

use Illuminate\Support\ServiceProvider;

if (!defined('GESOFT_LIVE_CHAT_MODULE')) {
    define('GESOFT_LIVE_CHAT_MODULE', 'gesoftlivechat');
}

/**
 * Turns on FreeScout's own chat machinery by registering a channel.
 *
 * FreeScout 1.8.239 already contains the operator half of live chat — the
 * Chats folder, Chat Mode, the chat list, the realtime refresh and the audio
 * notification, and the rule that a chat conversation is never answered by
 * email. All of it is inert, gated behind one call:
 *
 *     Helper::isChatModeAvailable() === count(CustomerChannel::getChannels())
 *     CustomerChannel::getChannels() === Eventy::filter('channels.list', [])
 *
 * So a module that answers `channels.list` switches the whole of it on. That
 * is the entire mechanism, and this phase deliberately does nothing else: no
 * route, no widget, no table of our own, nothing a customer can reach.
 *
 * See `docs/live-chat-core-contract.md` for what this depends on and what
 * breaks if an upgrade moves it.
 */
class GesoftLiveChatServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->registerConfig();
        $this->hooks();
        $this->registerCommands();
    }

    public function register()
    {
        //
    }

    /**
     * Everything this module adds to FreeScout.
     *
     * Two filters and one action, all documented extension points. No core
     * file is touched and nothing is injected from JavaScript.
     */
    public function hooks()
    {
        $channel = (int) config('gesoftlivechat.channel');

        // The switch. Core only ever counts what comes back — nothing iterates
        // it — so the entry is the bare code, and `channel.name` below is what
        // actually decides how it reads on screen.
        \Eventy::addFilter('channels.list', function ($channels) use ($channel) {
            $channels[] = $channel;

            return $channels;
        });

        // How the channel is named wherever core prints one: the customer
        // profile tag, and the conversation's own channel label in Chat Mode.
        \Eventy::addFilter('channel.name', function ($name, $code) use ($channel) {
            return (int) $code === $channel ? __('Web Chat') : $name;
        }, 20, 2);

        // Where an agent's reply to a chat conversation comes out.
        //
        // Core refuses to email a chat conversation and hands the reply here
        // instead (`Listeners/SendReplyToCustomer.php`). In this phase we only
        // record that it arrived and with what — building the transport to the
        // customer's browser is the next phase, and doing it now would mean
        // shipping a customer-facing surface nobody has reviewed.
        //
        // Note the arrival is not immediate: core schedules this through the
        // queue with `Conversation::UNDO_TIMOUT` (15 s) of delay, so the log
        // line lands about fifteen seconds after the agent presses send, and
        // only if a queue worker is running.
        \Eventy::addAction('chat_conversation.send_reply', function ($conversation, $replies, $customer) {
            // Newest first. `Conversation::getThreads()` orders by `created_at`
            // descending, so the reply that was just sent is the *first*
            // element, not the last — taking the last one hands you the
            // customer's opening message and looks plausible while being
            // wrong. Measured on the F2A instance before this was fixed.
            $newest = null;
            foreach ($replies as $reply) {
                $newest = $reply;
                break;
            }

            \Log::info('GesoftLiveChat: agent reply ready for delivery', [
                'conversation_id' => $conversation->id ?? null,
                'thread_id'       => $newest->id ?? null,
                'customer_id'     => $customer->id ?? null,
                'replies'         => is_countable($replies) ? count($replies) : null,
                'at'              => now()->toRfc3339String(),
            ]);
        }, 20, 3);
    }

    /**
     * The development-only conversation maker.
     *
     * Registered behind an explicit configuration flag rather than behind
     * `app()->environment()`: a FreeScout instance runs as `production` even
     * when it is a test box, so an environment gate would hide the tool
     * exactly where it is wanted. It is an artisan command, so no route
     * reaches it in any case.
     */
    protected function registerCommands()
    {
        if (!$this->app->runningInConsole() || !config('gesoftlivechat.dev_tools')) {
            return;
        }

        $this->commands([
            \Modules\GesoftLiveChat\Console\MakeChatConversation::class,
        ]);
    }

    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('gesoftlivechat.php'),
        ], 'config');

        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'gesoftlivechat');
    }

    public function provides()
    {
        return [];
    }
}
