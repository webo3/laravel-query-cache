<?php

namespace webO3\LaravelDbCache;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use webO3\LaravelDbCache\Console\ClearQueryCacheCommand;

class QueryCacheServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/db-cache.php', 'db-cache');

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
                __DIR__ . '/../config/db-cache.php' => config_path('db-cache.php'),
            ], 'db-cache-config');

            $this->commands([
                ClearQueryCacheCommand::class,
            ]);
        }

        // Inject db_cache config into the database connection config
        if (config('db-cache.enabled', false)) {
            $raw = config('db-cache.connection', 'mysql');
            $connections = is_string($raw) ? array_map('trim', explode(',', $raw)) : Arr::wrap($raw);

            $excludedRaw = config('db-cache.excluded_tables', []);
            $excludedTables = is_string($excludedRaw)
                ? array_filter(array_map('trim', explode(',', $excludedRaw)))
                : Arr::wrap($excludedRaw);

            foreach ($connections as $connection) {
                config([
                    "database.connections.{$connection}.db_cache" => [
                        'enabled' => true,
                        'driver' => config('db-cache.driver', 'array'),
                        'ttl' => config('db-cache.ttl', 180),
                        'max_size' => config('db-cache.max_size', 1000),
                        'log_enabled' => config('db-cache.log_enabled', false),
                        'redis_connection' => config('db-cache.redis_connection', 'db_cache'),
                        'excluded_tables' => $excludedTables,
                        'tenant_required' => config('db-cache.tenant_required', false),
                    ],
                ]);
            }

            $this->registerLifecycleListeners($connections);
        }
    }

    /**
     * Register listeners that flush the per-request L1 cache on request /
     * job / Octane request boundaries. Without these, long-running workers
     * (Horizon, FrankenPHP via Octane) accumulate stale L1 entries that
     * outlive their intended TTL.
     */
    private function registerLifecycleListeners(array $connections): void
    {
        $flush = function () use ($connections) {
            foreach ($connections as $name) {
                try {
                    $connection = $this->app['db']->connection($name);
                    if ($connection instanceof \webO3\LaravelDbCache\Contracts\CachedConnection) {
                        $connection->flushRequestCache();
                    }
                } catch (\Throwable $e) {
                    // Never let cleanup break the request/job lifecycle
                }
            }
        };

        $events = $this->app['events'];

        $events->listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, $flush);

        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, $flush);
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, $flush);
        $events->listen(\Illuminate\Queue\Events\JobExceptionOccurred::class, $flush);

        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $events->listen(\Laravel\Octane\Events\RequestTerminated::class, $flush);
        }
        if (class_exists(\Laravel\Octane\Events\TaskTerminated::class)) {
            $events->listen(\Laravel\Octane\Events\TaskTerminated::class, $flush);
        }
        if (class_exists(\Laravel\Octane\Events\TickTerminated::class)) {
            $events->listen(\Laravel\Octane\Events\TickTerminated::class, $flush);
        }
    }
}
