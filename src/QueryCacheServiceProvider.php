<?php

namespace webO3\LaravelDbCache;

use Illuminate\Support\ServiceProvider;
use webO3\LaravelDbCache\Console\ClearQueryCacheCommand;
use webO3\LaravelDbCache\Console\PruneQueryCacheCommand;
use webO3\LaravelDbCache\Support\ConfigList;

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
                PruneQueryCacheCommand::class,
            ]);
        }

        // Inject db_cache config into the database connection config
        if (config('db-cache.enabled', false)) {
            $connections = ConfigList::cachedConnections();
            $excludedTables = ConfigList::parse(config('db-cache.excluded_tables', []));

            foreach ($connections as $connection) {
                config([
                    "database.connections.{$connection}.db_cache" => [
                        'enabled' => true,
                        'driver' => config('db-cache.driver', 'array'),
                        'ttl' => config('db-cache.ttl', 180),
                        'max_size' => config('db-cache.max_size', 1000),
                        'max_result_bytes' => config('db-cache.max_result_bytes', 1048576),
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
     * Register hooks that flush the per-request L1 cache (and tenant context)
     * on request / job / Octane request boundaries. Without these, long-running
     * workers (Horizon, FrankenPHP via Octane) accumulate stale L1 entries that
     * outlive their intended TTL — and carry one request's tenant context into
     * the next.
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

        // Requests: flush via the container's terminating callbacks, which the
        // HTTP kernel runs AFTER terminable middleware — so the stats
        // middleware's terminate() still sees this request's counters before
        // they are reset. (A RequestHandled listener would fire before
        // terminate() and zero the stats first.)
        $this->app->terminating($flush);

        $events = $this->app['events'];

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
