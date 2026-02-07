<?php

namespace webO3\LaravelQueryCache\Middleware;

use webO3\LaravelQueryCache\Contracts\CachedConnection;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Middleware for logging query cache statistics
 *
 * Logs cache hit rates and query counts at the end of each request.
 * Useful for monitoring cache effectiveness.
 */
class QueryCacheStatsMiddleware
{
    /**
     * Handle an incoming request
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // Log statistics if enabled in config
        if (config('query-cache.log_enabled', false)) {
            $this->logCacheStats($request);
        }

        return $response;
    }

    /**
     * Log cache statistics
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function logCacheStats($request): void
    {
        try {
            $connections = Arr::wrap(config('query-cache.connection', 'mysql'));

            foreach ($connections as $connectionName) {
                $connection = DB::connection($connectionName);

                if ($connection instanceof CachedConnection) {
                    $stats = $connection->getCacheStats();

                    if ($stats['cached_queries_count'] > 0) {
                        Log::info('Query Cache Statistics', [
                            'connection' => $connectionName,
                            'driver' => $stats['driver'],
                            'url' => $request->fullUrl(),
                            'method' => $request->method(),
                            'cached_queries' => $stats['cached_queries_count'],
                            'total_hits' => $stats['total_cache_hits'],
                            'hit_rate' => $this->calculateHitRate($stats),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail - don't break the app if stats logging fails
            Log::warning('Failed to log query cache stats', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate cache hit rate
     *
     * @param array $stats
     * @return string
     */
    private function calculateHitRate(array $stats): string
    {
        $cachedQueries = $stats['cached_queries_count'];
        $totalHits = $stats['total_cache_hits'];

        if ($cachedQueries === 0) {
            return '0%';
        }

        $hitRate = ($totalHits / ($cachedQueries + $totalHits)) * 100;

        return number_format($hitRate, 2) . '%';
    }
}
