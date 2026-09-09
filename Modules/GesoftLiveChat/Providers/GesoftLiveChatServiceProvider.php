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
        $this->registerViews();
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

        // When an agent starts remote support from a chat, put the link in the
        // chat. Without this the agent has a link in a sidebar and a customer
        // who cannot see sidebars — the two halves of the flow sit a copy-paste
        // apart, which is exactly where a support call goes wrong.
        //
        // Chat conversations only. On an email conversation this would post a
        // reply, which means sending mail, and that is a decision belonging to
        // whoever wants it rather than a side effect of installing a chat
        // module.
        \Eventy::addAction('gesoft.remote_support.started', function ($conversation, $session, $user) {
            if (!$conversation || !$conversation->isChat()) {
                return;
            }

            $url = method_exists($session, 'customerUrl') ? $session->customerUrl() : null;
            if (!$url) {
                \Log::warning('GesoftLiveChat: remote support started with no customer link', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // Plain text, the same shape the visitor's own messages take, so
            // the bubble renders it with the code path already in use rather
            // than a second one that has to be kept safe separately.
            $body = __('To let us connect to your computer, open this link and run the tool it gives you:')
                .' '.$url;

            \App\Thread::createExtended(
                [
                    'type'               => \App\Thread::TYPE_MESSAGE,
                    'body'               => htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'state'              => \App\Thread::STATE_PUBLISHED,
                    'created_by_user_id' => $user->id ?? null,
                ],
                $conversation,
                $conversation->customer
            );

            \Log::info('GesoftLiveChat: remote support link posted into the chat', [
                'conversation_id' => $conversation->id,
                'session'         => $session->helpdesk_session_id ?? null,
            ]);
        }, 20, 3);

        // The customer ran the tool. Say so in the chat, so the agent sees it
        // wherever they are and the customer sees that we noticed — they have
        // just done something and been met with silence otherwise.
        \Eventy::addAction('gesoft.remote_support.ready', function ($conversation, $session) {
            if (!$conversation || !$conversation->isChat()) {
                return;
            }

            $body = __('Thank you — we can see your computer now. Please leave the support window open.');

            \App\Thread::createExtended(
                [
                    'type'               => \App\Thread::TYPE_MESSAGE,
                    'body'               => htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'state'              => \App\Thread::STATE_PUBLISHED,
                    'created_by_user_id' => $session->started_by_user_id ?? null,
                ],
                $conversation,
                $conversation->customer
            );

            \Log::info('GesoftLiveChat: remote client reported an ID', [
                'conversation_id' => $conversation->id,
                'remote_id'       => $session->remote_id ?? null,
            ]);
        }, 20, 2);

        // "Are you still there?" in the conversation's More Actions menu, on
        // chat conversations only — the hook fires on every conversation, and
        // the question is meaningless on an email one.
        \Eventy::addAction('conversation.append_action_buttons', function ($conversation, $mailbox) {
            if (!$conversation || !$conversation->isChat()) {
                return;
            }

            echo \View::make('gesoftlivechat::partials/nudge_button', [
                'conversation' => $conversation,
            ])->render();
        }, 30, 2);

        // The agent-side indicator. Core never puts a count on its own Chats
        // link and only subscribes to the chat realtime channel when the chat
        // list is already on screen, so an agent reading a ticket cannot tell
        // that somebody is waiting. These two files add that and patch nothing.
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(GESOFT_LIVE_CHAT_MODULE).'/js/operator.js';

            return $javascripts;
        });

        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(GESOFT_LIVE_CHAT_MODULE).'/css/operator.css';

            return $styles;
        });

        // The idle sweep. Core exposes its schedule as a filter, so a module
        // can add work to the same cron the rest of FreeScout runs on rather
        // than needing one of its own.
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('gesoftlivechat:sweep-chats')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();

            return $schedule;
        });
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
        if (!$this->app->runningInConsole()) {
            return;
        }

        // The sweep runs in production; it is the module working, not a tool.
        $this->commands([
            \Modules\GesoftLiveChat\Console\SweepChats::class,
        ]);

        if (!config('gesoftlivechat.dev_tools')) {
            return;
        }

        $this->commands([
            \Modules\GesoftLiveChat\Console\MakeChatConversation::class,
        ]);
    }

    public function registerViews()
    {
        $view_path = resource_path('views/modules/gesoftlivechat');
        $source = __DIR__.'/../Resources/views';

        $this->publishes([$source => $view_path], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path.'/modules/gesoftlivechat';
        }, \Config::get('view.paths')), [$source]), 'gesoftlivechat');
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
