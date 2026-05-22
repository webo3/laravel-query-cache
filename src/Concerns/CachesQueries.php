<?php

namespace webO3\LaravelDbCache\Concerns;

use webO3\LaravelDbCache\Contracts\QueryCacheDriver;
use webO3\LaravelDbCache\Drivers\ArrayQueryCacheDriver;
use webO3\LaravelDbCache\Drivers\NullQueryCacheDriver;
use webO3\LaravelDbCache\Drivers\RedisQueryCacheDriver;
use webO3\LaravelDbCache\Utils\SqlTableExtractor;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Trait that adds query-level caching to any Laravel database connection.
 *
 * Use this trait in a class that extends Illuminate\Database\Connection
 * (or any subclass like MySqlConnection, PostgresConnection, etc.).
 *
 * The using class MUST call $this->bootCachesQueries($config) at the
 * end of its constructor.
 */
trait CachesQueries
{
    /**
     * Query cache driver instance
     */
    private QueryCacheDriver $cacheDriver;

    /**
     * Flag to indicate if we're in a cursor query (to disable caching)
     */
    private bool $inCursorQuery = false;

    /**
     * Per-request cache for normalized queries to avoid redundant normalization
     */
    private static array $normalizedQueryCache = [];

    /**
     * Initialize the caching subsystem. Must be called from the
     * connection class constructor after parent::__construct().
     */
    protected function bootCachesQueries(array $config): void
    {
        $this->cacheDriver = $this->createCacheDriver($config);
    }

    /**
     * Run a select statement and return a generator (for cursor queries)
     *
     * IMPORTANT: cursor() queries are NEVER cached because they are designed
     * to stream results without loading everything into memory. Caching would
     * defeat their purpose by requiring all results to be loaded into memory.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return \Generator
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->inCursorQuery = true;

        try {
            yield from parent::cursor($query, $bindings, $useReadPdo, $fetchUsing);
        } finally {
            $this->inCursorQuery = false;
        }
    }

    /**
     * Run a SQL statement with caching support
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  \Closure  $callback
     * @return mixed
     *
     * @throws \Illuminate\Database\QueryException
     */
    protected function run($query, $bindings, Closure $callback)
    {
        // Never cache cursor queries - they're designed for memory-efficient streaming
        if ($this->inCursorQuery) {
            return parent::run($query, $bindings, $callback);
        }

        // Check if caching is enabled
        if (!$this->isCachingEnabled()) {
            return parent::run($query, $bindings, $callback);
        }

        $queryType = $this->getQueryType($query);

        // Handle SELECT queries with caching
        if ($queryType === 'SELECT') {
            // Never cache queries against system/metadata tables
            if ($this->isSchemaQuery($query)) {
                return parent::run($query, $bindings, $callback);
            }

            // Never cache SELECTs touching excluded identifiers (typically
            // views): the extractor sees the view name, not its underlying
            // tables, so we cannot reliably invalidate when a base table
            // mutates.
            if ($this->touchesExcludedTable($query)) {
                return parent::run($query, $bindings, $callback);
            }

            return $this->runSelectWithCache($query, $bindings, $callback);
        }

        // Handle mutation queries (INSERT, UPDATE, DELETE, etc.)
        if ($this->isMutationQuery($query)) {
            $this->invalidateCache($query);
        }

        // Execute the query normally
        return parent::run($query, $bindings, $callback);
    }

    /**
     * Check if the query references any identifier marked as excluded
     * (typically a view). Match is case-insensitive on the bare name.
     */
    private function touchesExcludedTable(string $query): bool
    {
        $excluded = $this->config['db_cache']['excluded_tables'] ?? [];

        if (empty($excluded)) {
            return false;
        }

        $excludedLower = array_map('strtolower', $excluded);

        foreach (SqlTableExtractor::extract($query) as $table) {
            if (in_array(strtolower($table), $excludedLower, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run a SELECT query with caching
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  \Closure  $callback
     * @return mixed
     */
    protected function runSelectWithCache($query, $bindings, Closure $callback)
    {
        $cacheKey = $this->generateCacheKey($query, $bindings);

        // Check if we have cached results
        $cached = $this->cacheDriver->get($cacheKey);

        if ($cached !== null) {
            $this->cacheDriver->recordHit($cacheKey);
            $this->logCacheHit($query, $bindings);
            return $cached['result'];
        }

        // Execute query and cache result
        $result = parent::run($query, $bindings, $callback);

        // Cache the result
        $executedAt = microtime(true);
        $this->cacheDriver->put($cacheKey, $result, $query, $executedAt);

        $this->logCacheMiss($query, $bindings);

        return $result;
    }

    /**
     * Invalidate cache for affected tables
     *
     * @param string $query
     * @return void
     */
    private function invalidateCache(string $query): void
    {
        $affectedTables = SqlTableExtractor::extract($query);
        $this->cacheDriver->invalidateTables($affectedTables, $query);
    }

    /**
     * Generate cache key from query and bindings
     *
     * @param string $query
     * @param array $bindings
     * @return string
     */
    private function generateCacheKey(string $query, array $bindings): string
    {
        $normalizedQuery = $this->normalizeQuery($query);
        $raw = $normalizedQuery . json_encode($bindings);

        // Use xxh128 (PHP 8.1+) for ~5-10x faster hashing than md5
        if (function_exists('hash')) {
            return hash('xxh128', $raw);
        }

        return md5($raw);
    }

    /**
     * Normalize SQL query for consistent caching
     *
     * @param string $query
     * @return string
     */
    private function normalizeQuery(string $query): string
    {
        // Check per-request cache first to avoid redundant normalization
        if (isset(self::$normalizedQueryCache[$query])) {
            return self::$normalizedQueryCache[$query];
        }

        $normalized = preg_replace('/\s+/', ' ', strtoupper(trim($query)));

        self::$normalizedQueryCache[$query] = $normalized;

        return $normalized;
    }

    /**
     * Extract query type from SQL
     *
     * @param string $sql
     * @return string
     */
    private function getQueryType(string $sql): string
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|TRUNCATE|REPLACE|ALTER|DROP|CREATE|RENAME|SHOW|DESCRIBE|EXPLAIN)\b/i', trim($sql), $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    /**
     * Check if query is a mutation
     *
     * @param string $sql
     * @return bool
     */
    private function isMutationQuery(string $sql): bool
    {
        return preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP|CREATE|RENAME|REPLACE)\b/i', trim($sql)) === 1;
    }

    /**
     * Check if query targets system/metadata tables that should never be cached
     *
     * @param string $sql
     * @return bool
     */
    private function isSchemaQuery(string $sql): bool
    {
        return preg_match('/\binformation_schema\b/i', $sql) === 1;
    }

    /**
     * Create cache driver based on configuration
     *
     * @param array $config
     * @return QueryCacheDriver
     */
    private function createCacheDriver(array $config): QueryCacheDriver
    {
        $cacheConfig = $config['db_cache'] ?? [];
        $driver = $cacheConfig['driver'] ?? 'array';

        return match ($driver) {
            'redis' => new RedisQueryCacheDriver($cacheConfig),
            'array' => new ArrayQueryCacheDriver($cacheConfig),
            'null' => new NullQueryCacheDriver(),
            default => new NullQueryCacheDriver(),
        };
    }

    /**
     * Set the tenant context for cache isolation.
     *
     * Delegates to the cache driver to namespace all cache keys by tenant,
     * preventing cross-tenant data leakage.
     *
     * @param string $tenantId
     * @return void
     */
    public function setTenantContext(string $tenantId): void
    {
        $this->cacheDriver->setTenantContext($tenantId);
    }

    /**
     * Check if caching is enabled
     *
     * @return bool
     */
    private function isCachingEnabled(): bool
    {
        return (bool)($this->config['db_cache']['enabled'] ?? false);
    }

    /**
     * Log cache hit
     *
     * @param string $query
     * @param array $bindings
     * @return void
     */
    private function logCacheHit(string $query, array $bindings): void
    {
        if ($this->config['db_cache']['log_enabled'] ?? false) {
            Log::debug('Query Cache: HIT', [
                'caller' => $this->getCallerInfo(),
                'query' => $query,
                'bindings' => $bindings
            ]);
        }
    }

    /**
     * Log cache miss
     *
     * @param string $query
     * @param array $bindings
     * @return void
     */
    private function logCacheMiss(string $query, array $bindings): void
    {
        if ($this->config['db_cache']['log_enabled'] ?? false) {
            Log::debug('Query Cache: MISS', [
                'caller' => $this->getCallerInfo(),
                'query' => $query,
                'bindings' => $bindings
            ]);
        }
    }

    /**
     * Get caller information for logging
     *
     * @return string
     */
    private function getCallerInfo(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';

            if (empty($file) ||
                strpos($file, '/vendor/') !== false ||
                strpos($file, '/database/') !== false ||
                $file == __FILE__) {
                continue;
            }

            $relativePath = str_replace(base_path() . '/', '', $file);
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'unknown';

            return "{$relativePath}:{$line} ({$function})";
        }

        return 'unknown';
    }

    /**
     * Clear all query cache (public method for manual clearing)
     *
     * @return void
     */
    public function clearQueryCache(): void
    {
        $this->cacheDriver->flush();
    }

    /**
     * Flush per-request state at request/job/Octane request boundaries.
     *
     * Drops the driver's L1 cache and resets process-static caches in
     * this trait so long-running workers (Horizon, FrankenPHP) don't
     * accumulate stale entries that outlive their intended TTL. Does
     * NOT touch the shared L2 (Redis) cache.
     */
    public function flushRequestCache(): void
    {
        $this->cacheDriver->flushRequestCache();
        self::$normalizedQueryCache = [];
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getCacheStats(): array
    {
        return $this->cacheDriver->getStats();
    }

    /**
     * Enable query caching on this connection.
     */
    public function enableQueryCache(): void
    {
        $this->config['db_cache']['enabled'] = true;
    }

    /**
     * Disable query caching on this connection.
     */
    public function disableQueryCache(): void
    {
        $this->config['db_cache']['enabled'] = false;
    }

    /**
     * Replace the list of excluded identifiers at runtime.
     * Useful when view names are discovered after boot.
     */
    public function setExcludedTables(array $tables): void
    {
        $this->config['db_cache']['excluded_tables'] = $tables;
    }
}
