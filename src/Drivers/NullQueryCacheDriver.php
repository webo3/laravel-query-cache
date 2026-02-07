<?php

namespace webO3\LaravelQueryCache\Drivers;

use webO3\LaravelQueryCache\Contracts\QueryCacheDriver;

/**
 * Null query cache driver (no-op)
 *
 * This driver does nothing - effectively disabling the query cache.
 * Useful for debugging or when you want to completely disable caching.
 */
class NullQueryCacheDriver implements QueryCacheDriver
{
    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $result, string $query, float $executedAt): void
    {
        // No-op
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        // No-op
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateTables(array $tables, string $query): int
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        // No-op
    }

    /**
     * {@inheritDoc}
     */
    public function getStats(): array
    {
        return [
            'driver' => 'null',
            'cached_queries_count' => 0,
            'total_cache_hits' => 0,
            'queries' => []
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function recordHit(string $key): void
    {
        // No-op
    }

    /**
     * {@inheritDoc}
     */
    public function getAllKeys(): array
    {
        return [];
    }
}
