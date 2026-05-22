<?php

namespace webO3\LaravelDbCache\Contracts;

/**
 * Interface for database connections with query caching.
 *
 * All cached connection implementations (MySQL, PostgreSQL, SQLite)
 * implement this interface. Use it for instanceof checks instead
 * of checking for a specific driver class.
 */
interface CachedConnection
{
    public function clearQueryCache(): void;

    public function getCacheStats(): array;

    public function enableQueryCache(): void;

    public function disableQueryCache(): void;

    public function setTenantContext(string $tenantId): void;

    public function flushRequestCache(): void;

    public function setExcludedTables(array $tables): void;
}
