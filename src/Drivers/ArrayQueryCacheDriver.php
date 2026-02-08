<?php

namespace webO3\LaravelDbCache\Drivers;

use webO3\LaravelDbCache\Contracts\QueryCacheDriver;
use webO3\LaravelDbCache\Utils\SqlTableExtractor;
use Illuminate\Support\Facades\Log;

/**
 * In-memory array-based query cache driver
 *
 * This driver stores cache in a static array, meaning:
 * - Cache is lost between HTTP requests
 * - Cache is NOT shared between PHP workers
 * - Useful for: development, testing, detecting duplicate queries within a single request
 */
class ArrayQueryCacheDriver implements QueryCacheDriver
{
    /**
     * Request-level query cache storage
     */
    private static array $cache = [];

    /**
     * Inverted table index: table_name => [key1, key2, ...]
     * Enables O(1) lookup of cache keys by table name during invalidation
     */
    private static array $tableIndex = [];

    /**
     * Configuration
     */
    private array $config;

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_size' => 1000,
            'log_enabled' => false,
        ], $config);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        return self::$cache[$key] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $result, string $query, float $executedAt): void
    {
        // Evict if needed before adding new entry
        $this->evictIfNeeded();

        // Extract tables eagerly for O(1) invalidation via inverted index
        $tables = SqlTableExtractor::extract($query);

        $now = microtime(true);
        self::$cache[$key] = [
            'result' => $result,
            'tables' => $tables,
            'query' => $query,
            'executed_at' => $executedAt,
            'last_accessed' => $now,
            'hits' => 0
        ];

        // Add to inverted table index
        foreach ($tables as $table) {
            self::$tableIndex[$table][$key] = true;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return isset(self::$cache[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        $this->removeFromTableIndex($key);
        unset(self::$cache[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateTables(array $tables, string $query): int
    {
        if (empty($tables)) {
            // If we can't determine tables, clear all cache to be safe
            $clearedCount = count(self::$cache);
            self::$cache = [];
            self::$tableIndex = [];

            if ($clearedCount > 0 && $this->config['log_enabled']) {
                Log::debug('Query Cache: Cleared entire cache (could not determine affected tables)', [
                    'query' => $query,
                    'cleared_count' => $clearedCount
                ]);
            }
            return $clearedCount;
        }

        // O(1) lookup per table using inverted index instead of scanning all cache entries
        $keysToInvalidate = [];
        foreach ($tables as $table) {
            if (isset(self::$tableIndex[$table])) {
                foreach (self::$tableIndex[$table] as $key => $_) {
                    $keysToInvalidate[$key] = true;
                }
            }
        }

        // Remove matched entries and clean up indexes
        foreach ($keysToInvalidate as $key => $_) {
            $this->removeFromTableIndex($key);
            unset(self::$cache[$key]);
        }

        $invalidatedCount = count($keysToInvalidate);

        if ($invalidatedCount > 0 && $this->config['log_enabled']) {
            Log::debug('Query Cache: Invalidated cached queries', [
                'affected_tables' => $tables,
                'invalidated_count' => $invalidatedCount,
                'query' => $query
            ]);
        }

        return $invalidatedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        self::$cache = [];
        self::$tableIndex = [];
    }

    /**
     * {@inheritDoc}
     */
    public function getStats(): array
    {
        $totalHits = 0;
        $queries = [];

        foreach (self::$cache as $key => $cached) {
            $totalHits += $cached['hits'];

            // Lazy-load tables if not already extracted (for stats display)
            $tables = $cached['tables'];
            if ($tables === null) {
                $tables = SqlTableExtractor::extract($cached['query']);
            }

            $queries[] = [
                'query' => $cached['query'],
                'tables' => $tables,
                'hits' => $cached['hits'],
                'cached_at' => $cached['executed_at']
            ];
        }

        return [
            'driver' => 'array',
            'cached_queries_count' => count(self::$cache),
            'total_cache_hits' => $totalHits,
            'queries' => $queries
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function recordHit(string $key): void
    {
        if (isset(self::$cache[$key])) {
            self::$cache[$key]['hits']++;
            self::$cache[$key]['last_accessed'] = microtime(true);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAllKeys(): array
    {
        return array_keys(self::$cache);
    }

    /**
     * Evict least recently used cache entries if cache size exceeds limit
     *
     * @return void
     */
    private function evictIfNeeded(): void
    {
        $maxCacheSize = $this->config['max_size'];

        if (count(self::$cache) >= $maxCacheSize) {
            // Sort by last_accessed time and remove the oldest 10%
            uasort(self::$cache, function ($a, $b) {
                return $a['last_accessed'] <=> $b['last_accessed'];
            });

            $toRemove = (int) ceil($maxCacheSize * 0.1);
            $removed = 0;

            foreach (array_keys(self::$cache) as $key) {
                if ($removed >= $toRemove) {
                    break;
                }
                $this->removeFromTableIndex($key);
                unset(self::$cache[$key]);
                $removed++;
            }

            if ($removed > 0 && $this->config['log_enabled']) {
                Log::debug('Query Cache: Evicted LRU entries', [
                    'evicted_count' => $removed,
                    'remaining_count' => count(self::$cache)
                ]);
            }
        }
    }

    /**
     * Remove a cache key from all its table indexes
     */
    private function removeFromTableIndex(string $key): void
    {
        $tables = self::$cache[$key]['tables'] ?? [];
        foreach ($tables as $table) {
            unset(self::$tableIndex[$table][$key]);
            if (empty(self::$tableIndex[$table])) {
                unset(self::$tableIndex[$table]);
            }
        }
    }
}
