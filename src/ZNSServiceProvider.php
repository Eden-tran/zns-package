<?php

namespace CPP\ZNSPackage;

use Illuminate\Support\ServiceProvider;
use CPP\ZNSPackage\Services\ZNSService;
use CPP\ZNSPackage\Console\Commands\RefreshZaloTokens;
use CPP\ZNSPackage\Console\Commands\SyncZnsTemplates;

class ZNSServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/zns.php' => config_path('zns.php'),
        ], 'zns-config');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Publish migrations (optional - users can run migrations directly from package)
        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations')
        ], 'zns-migrations');

        // Register commands if running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshZaloTokens::class,
                SyncZnsTemplates::class,
            ]);
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/zns.php', 'zns'
        );

        // Register singleton service
        $this->app->singleton('zns', function ($app) {
            return new ZNSService(
                config('zns.app_id'),
                config('zns.app_secret'),
                config('zns')
            );
        });
    }
}