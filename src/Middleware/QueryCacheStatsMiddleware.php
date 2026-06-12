<?php

namespace webO3\LaravelDbCache\Middleware;

use webO3\LaravelDbCache\Contracts\CachedConnection;
use webO3\LaravelDbCache\Support\ConfigList;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Middleware for logging query cache statistics
 *
 * Logs cache hit rates and query counts at the end of each request.
 * Useful for monitoring cache effectiveness.
 *
 * Stats are collected in terminate(), after the response has been sent,
 * so the (potentially expensive) Redis sweep never adds request latency.
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
        return $next($request);
    }

    /**
     * Collect and log stats after the response has been sent to the client.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $response
     * @return void
     */
    public function terminate($request, $response): void
    {
        if (config('db-cache.log_enabled', false)) {
            $this->logCacheStats($request);
        }
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
            foreach (ConfigList::cachedConnections() as $connectionName) {
                $connection = DB::connection($connectionName);

                if ($connection instanceof CachedConnection) {
                    $stats = $connection->getCacheStats();

                    if ($stats['cached_queries_count'] > 0 || ($stats['request_hits'] ?? 0) > 0 || ($stats['request_misses'] ?? 0) > 0) {
                        Log::info('Query Cache Statistics', [
                            'connection' => $connectionName,
                            'driver' => $stats['driver'],
                            'url' => $request->fullUrl(),
                            'method' => $request->method(),
                            'cached_queries' => $stats['cached_queries_count'],
                            'total_hits' => $stats['total_cache_hits'],
                            'request_hits' => $stats['request_hits'] ?? 0,
                            'request_misses' => $stats['request_misses'] ?? 0,
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
     * Calculate the hit rate from this request's own hit/miss counters —
     * lifetime hit totals against current key counts said nothing about the
     * request being logged.
     *
     * @param array $stats
     * @return string
     */
    private function calculateHitRate(array $stats): string
    {
        $hits = (int) ($stats['request_hits'] ?? 0);
        $misses = (int) ($stats['request_misses'] ?? 0);

        if ($hits + $misses === 0) {
            return '0%';
        }

        return number_format(($hits / ($hits + $misses)) * 100, 2) . '%';
    }
}
