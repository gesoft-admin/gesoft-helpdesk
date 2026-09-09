<?php

namespace Modules\GesoftRemoteSupport\Providers;

use Illuminate\Database\Eloquent\Factory;
use Illuminate\Support\ServiceProvider;
use Modules\GesoftRemoteSupport\Entities\RemoteSession;

// Module alias, as recommended by the FreeScout module guide.
if (!defined('GESOFT_REMOTE_SUPPORT_MODULE')) {
    define('GESOFT_REMOTE_SUPPORT_MODULE', 'gesoftremotesupport');
}

class GesoftRemoteSupportServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Module hooks.
     *
     * Everything this module adds to the interface is attached here. No core
     * file is touched and no markup is injected from JavaScript: each hook is
     * a documented extension point that hands us the objects we need.
     */
    public function hooks()
    {
        // The single entry point: the "More Actions" dropdown. This hook fires
        // inside a <ul>, so the partial emits an <li>.
        \Eventy::addAction('conversation.append_action_buttons', function ($conversation, $mailbox) {
            echo \View::make('gesoftremotesupport::partials/action_button_dropdown')->render();
        }, 20, 2);

        // The sidebar panel. $conversation arrives from core; the agent comes
        // from the session; the state comes from this module's own table.
        \Eventy::addAction('conversation.after_customer_sidebar', function ($conversation) {
            echo \View::make('gesoftremotesupport::partials/sidebar_panel', [
                'conversation' => $conversation,
                'session'      => RemoteSession::forConversation($conversation->id),
                'user'         => auth()->user(),
            ])->render();
        }, 20, 1);

        // Assets, served from the module's own public path.
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(GESOFT_REMOTE_SUPPORT_MODULE).'/js/module.js';

            return $javascripts;
        });

        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(GESOFT_REMOTE_SUPPORT_MODULE).'/css/module.css';

            return $styles;
        });

        // Until this ran, the module only learned anything while an agent had
        // the conversation open: the panel's poll was the only thing that ever
        // asked the backend. An agent who sent the link and moved on never
        // found out the customer had started the tool.
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('gesoftremotesupport:poll-sessions')
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();

            return $schedule;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\GesoftRemoteSupport\Console\PollSessions::class,
            ]);
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('gesoftremotesupport.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'gesoftremotesupport'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/gesoftremotesupport');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/gesoftremotesupport';
        }, \Config::get('view.paths')), [$sourcePath]), 'gesoftremotesupport');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ .'/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     */
    public function registerFactories()
    {
        if (! app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
