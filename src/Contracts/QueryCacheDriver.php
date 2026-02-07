<?php

namespace webO3\LaravelQueryCache\Contracts;

interface QueryCacheDriver
{
    /**
     * Get a cached query result
     *
     * @param string $key Cache key
     * @return array|null Cached data or null if not found
     */
    public function get(string $key): ?array;

    /**
     * Store a query result in cache
     *
     * @param string $key Cache key
     * @param mixed $result Query result to cache
     * @param string $query Original SQL query
     * @param float $executedAt Timestamp when query was executed
     * @return void
     */
    public function put(string $key, mixed $result, string $query, float $executedAt): void;

    /**
     * Check if a key exists in cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a specific cache entry
     *
     * @param string $key Cache key
     * @return void
     */
    public function forget(string $key): void;

    /**
     * Invalidate all cache entries that reference any of the given tables
     *
     * @param array $tables Table names affected by mutation
     * @param string $query The mutation query (for logging)
     * @return int Number of cache entries invalidated
     */
    public function invalidateTables(array $tables, string $query): int;

    /**
     * Clear all cached queries
     *
     * @return void
     */
    public function flush(): void;

    /**
     * Get cache statistics
     *
     * @return array Statistics including hit count, cached queries count, etc.
     */
    public function getStats(): array;

    /**
     * Record a cache hit for statistics
     *
     * @param string $key Cache key that was hit
     * @return void
     */
    public function recordHit(string $key): void;

    /**
     * Get all cache keys (for debugging/monitoring)
     *
     * @return array
     */
    public function getAllKeys(): array;
}
