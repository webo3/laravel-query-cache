<?php

namespace webO3\LaravelQueryCache;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

class QueryCacheServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/query-cache.php', 'query-cache');

        // Always register the factory - it checks the enabled flag per-connection
        // at creation time, falling back to the default connection when disabled.
        $this->app->singleton('db.factory', function ($app) {
            return new CachedConnectionFactory($app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/query-cache.php' => config_path('query-cache.php'),
            ], 'query-cache-config');
        }

        // Inject query_cache config into the database connection config
        if (config('query-cache.enabled', false)) {
            $connections = Arr::wrap(config('query-cache.connection', 'mysql'));

            foreach ($connections as $connection) {
                config([
                    "database.connections.{$connection}.query_cache" => [
                        'enabled' => true,
                        'driver' => config('query-cache.driver', 'array'),
                        'ttl' => config('query-cache.ttl', 180),
                        'max_size' => config('query-cache.max_size', 1000),
                        'log_enabled' => config('query-cache.log_enabled', false),
                        'redis_connection' => config('query-cache.redis_connection', 'query_cache'),
                    ],
                ]);
            }
        }
    }
}
