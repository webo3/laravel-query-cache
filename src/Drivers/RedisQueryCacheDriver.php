<?php

namespace webO3\LaravelQueryCache\Drivers;

use webO3\LaravelQueryCache\Contracts\QueryCacheDriver;
use webO3\LaravelQueryCache\Utils\SqlTableExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-based query cache driver with two-tier caching
 *
 * Features:
 * - L1 Cache: Request-level in-memory cache (instant, no network overhead)
 * - L2 Cache: Redis persistent cache (shared across workers/requests)
 * - Redis Hash structure with pipelining for batch operations
 * - AWS/Valkey compatible (uses Redis Sets instead of KEYS/SCAN)
 * - Automatic serialization with igbinary and compression support
 */
class RedisQueryCacheDriver implements QueryCacheDriver
{
    /**
     * Redis Set key to track all cached query keys
     */
    private const KEYS_SET = 'query_cache:keys';

    /**
     * Redis Set key prefix for table-based index (inverted index)
     * Format: query_cache:table:{table_name} -> Set of query cache keys
     */
    private const TABLE_INDEX_PREFIX = 'query_cache:table:';

    /**
     * Configuration
     */
    private array $config;

    /**
     * Direct Redis connection for Hash operations and pipelining
     */
    private $redis;

    /**
     * L1 cache: Request-level in-memory cache
     * Prevents repeated Redis calls for the same query within a single HTTP request
     */
    private array $requestCache = [];

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'ttl' => 300, // 5 minutes default
            'log_enabled' => false,
            'redis_connection' => 'query_cache',
        ], $config);

        // Get direct Redis connection
        $redisConnectionName = $this->config['redis_connection'];
        $this->redis = Redis::connection($redisConnectionName);
    }

    /**
     * Build full Redis key with Laravel prefix
     *
     * @param string $key
     * @return string
     */
    private function buildFullKey(string $key): string
    {
        $appName = config('app.name', 'laravel');
        $appSlug = \Illuminate\Support\Str::slug($appName, '_');
        $cachePrefix = config('cache.prefix');
        return "{$appSlug}_database_{$cachePrefix}:{$key}";
    }

    /**
     * Serialize result with best available method
     *
     * Uses igbinary if available, with automatic compression for large results.
     */
    private function serializeResult(mixed $result): string
    {
        // Use igbinary if available
        if (function_exists('igbinary_serialize')) {
            $serialized = \igbinary_serialize($result);

            // Compress large results
            if (strlen($serialized) > 10240) {
                return 'i:' . \gzcompress($serialized, 6);
            }

            return $serialized;
        }

        // Fallback to standard serialize
        $serialized = serialize($result);

        if (strlen($serialized) > 10240) {
            return 'c:' . \gzcompress($serialized, 6);
        }

        return $serialized;
    }

    /**
     * Unserialize result with automatic format detection
     */
    private function unserializeResult(string $data): mixed
    {
        if (empty($data)) {
            return null;
        }

        // Check for compression markers
        if (str_starts_with($data, 'i:')) {
            // igbinary + gzcompress
            $decompressed = \gzuncompress(substr($data, 2));
            return \igbinary_unserialize($decompressed);
        }

        if (str_starts_with($data, 'c:')) {
            // serialize + gzcompress
            $decompressed = \gzuncompress(substr($data, 2));
            return unserialize($decompressed);
        }

        // Try igbinary first (check if data starts with igbinary header)
        if (function_exists('igbinary_unserialize') && ord($data[0]) === 0x00) {
            return \igbinary_unserialize($data);
        }

        // Fallback to standard unserialize
        return unserialize($data);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        // L1 Cache: Check request-level cache first (instant)
        if (isset($this->requestCache[$key])) {
            return $this->requestCache[$key];
        }

        try {
            // L2 Cache: Check Redis
            $fullKey = $this->buildFullKey($key);
            $data = $this->redis->hgetall($fullKey);

            if (empty($data)) {
                return null;
            }

            // Reconstruct array from Hash
            $cached = [
                'result' => isset($data['result']) ? $this->unserializeResult($data['result']) : null,
                'query' => $data['query'] ?? '',
                'executed_at' => (float)($data['executed_at'] ?? 0),
                'cached_at' => (float)($data['cached_at'] ?? 0),
                'hits' => (int)($data['hits'] ?? 0),
                'tables' => isset($data['tables']) && $data['tables'] !== '' ? json_decode($data['tables'], true) : null,
                'last_accessed' => isset($data['last_accessed']) ? (float)$data['last_accessed'] : null,
            ];

            // Store in L1 cache for this request
            $this->requestCache[$key] = $cached;

            return $cached;
        } catch (\RedisException $e) {
            // Redis connection/timeout errors - always log these
            Log::error('Query Cache (Redis): Connection/timeout error', [
                'key' => $key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to get from Hash', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $result, string $query, float $executedAt): void
    {
        $now = microtime(true);
        $ttl = $this->config['ttl'];

        // Extract tables upfront for indexing (required for efficient invalidation)
        $tables = SqlTableExtractor::extract($query);

        // Store in L1 cache immediately (no network overhead)
        $this->requestCache[$key] = [
            'result' => $result,
            'query' => $query,
            'executed_at' => $executedAt,
            'cached_at' => $now,
            'hits' => 0,
            'tables' => $tables,
            'last_accessed' => null,
        ];

        try {
            $fullKey = $this->buildFullKey($key);

            // Store as Redis Hash using HMSET
            $this->redis->hmset($fullKey, [
                'result' => $this->serializeResult($result),
                'query' => $query,
                'executed_at' => (string)$executedAt,
                'cached_at' => (string)$now,
                'hits' => '0',
                'tables' => json_encode($tables),
            ]);

            // Set TTL
            $this->redis->expire($fullKey, $ttl);

            // Track this key in our Set for efficient listing (AWS/Valkey compatible)
            $this->addKeyToSet($key);

            // Index this key by each table for O(1) invalidation lookup
            $this->addKeyToTableIndexes($key, $tables);
        } catch (\RedisException $e) {
            // Redis connection/timeout errors - always log these
            Log::error('Query Cache (Redis): Connection/timeout error on put', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to put to Hash', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        try {
            $fullKey = $this->buildFullKey($key);
            return (bool)$this->redis->exists($fullKey);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to check Hash existence', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        // Get tables before removing from L1 cache (needed for index cleanup)
        $tables = $this->requestCache[$key]['tables'] ?? null;

        // Remove from L1 cache
        unset($this->requestCache[$key]);

        try {
            $fullKey = $this->buildFullKey($key);
            $this->redis->del($fullKey);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to delete Hash', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Remove from tracking Set
        $this->removeKeyFromSet($key);

        // Remove from table indexes
        $this->removeKeyFromTableIndexes($key, $tables);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateTables(array $tables, string $query): int
    {
        if (empty($tables)) {
            // Clear all query cache
            $this->clearAllTrackedKeys();

            if ($this->config['log_enabled']) {
                Log::debug('Query Cache (Redis): Cleared entire cache (could not determine affected tables)', [
                    'query' => $query,
                ]);
            }

            return -1; // Unknown count
        }

        // Check tracked keys and invalidate matching ones
        return $this->invalidateByTablesScan($tables, $query);
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        // Clear L1 cache
        $this->requestCache = [];

        // Clear L2 cache (Redis)
        $this->clearAllTrackedKeys();
    }

    /**
     * {@inheritDoc}
     */
    public function getStats(): array
    {
        // Get all tracked keys from our Set (AWS/Valkey compatible)
        $keys = $this->getAllTrackedKeys();

        if (empty($keys)) {
            return [
                'driver' => 'redis',
                'cached_queries_count' => 0,
                'total_cache_hits' => 0,
                'queries' => [],
            ];
        }

        $cacheData = $this->pipelineGet($keys);

        $queries = [];
        $totalHits = 0;

        foreach ($keys as $i => $key) {
            $cached = $cacheData[$i] ?? null;

            if ($cached) {
                // Lazy-load tables if not already extracted
                if (!isset($cached['tables']) || $cached['tables'] === null) {
                    $cached['tables'] = SqlTableExtractor::extract($cached['query']);
                }

                $queries[] = [
                    'query' => $cached['query'],
                    'tables' => $cached['tables'],
                    'hits' => $cached['hits'] ?? 0,
                    'cached_at' => $cached['cached_at'] ?? 0
                ];

                $totalHits += $cached['hits'] ?? 0;
            }
        }

        return [
            'driver' => 'redis',
            'cached_queries_count' => count($queries),
            'total_cache_hits' => $totalHits,
            'queries' => $queries,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function recordHit(string $key): void
    {
        // Invalidate L1 cache so next get() fetches updated hits from Redis
        unset($this->requestCache[$key]);

        try {
            $fullKey = $this->buildFullKey($key);
            $now = microtime(true);

            // Atomic increment hits counter
            $this->redis->hincrby($fullKey, 'hits', 1);

            // Update last_accessed timestamp
            $this->redis->hset($fullKey, 'last_accessed', (string)$now);

            // Refresh TTL
            $ttl = $this->config['ttl'];
            $this->redis->expire($fullKey, $ttl);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to record hit', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAllKeys(): array
    {
        // Return all tracked keys from our Set
        return $this->getAllTrackedKeys();
    }

    /**
     * Add a key to the tracking Set
     *
     * @param string $key
     * @return void
     */
    private function addKeyToSet(string $key): void
    {
        try {
            // Add to Set using SADD (AWS/Valkey compatible)
            $this->redis->sadd(self::KEYS_SET, $key);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to add key to tracking set', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Remove a key from the tracking Set
     *
     * @param string $key
     * @return void
     */
    private function removeKeyFromSet(string $key): void
    {
        try {
            // Remove from Set using SREM (AWS/Valkey compatible)
            $this->redis->srem(self::KEYS_SET, $key);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to remove key from tracking set', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Add a key to table-based indexes for O(1) invalidation lookup
     *
     * @param string $key
     * @param array $tables
     * @return void
     */
    private function addKeyToTableIndexes(string $key, array $tables): void
    {
        if (empty($tables)) {
            return;
        }

        try {
            // Use pipeline to add key to all table indexes in one roundtrip
            $this->redis->pipeline(function ($pipe) use ($key, $tables) {
                foreach ($tables as $table) {
                    $indexKey = self::TABLE_INDEX_PREFIX . $table;
                    $pipe->sadd($indexKey, $key);
                }
            });
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to add key to table indexes', [
                    'key' => $key,
                    'tables' => $tables,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get all cache keys that reference any of the given tables
     *
     * @param array $tables
     * @return array
     */
    private function getKeysFromTableIndexes(array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        try {
            // Build array of table index keys
            $indexKeys = array_map(
                fn($table) => self::TABLE_INDEX_PREFIX . $table,
                $tables
            );

            // Use SUNION to get all keys from all table indexes in one call
            if (count($indexKeys) === 1) {
                $keys = $this->redis->smembers($indexKeys[0]);
            } else {
                $keys = $this->redis->sunion(...$indexKeys);
            }

            return $keys ?: [];
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to get keys from table indexes', [
                    'tables' => $tables,
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }

    /**
     * Remove a key from all its table indexes
     *
     * @param string $key
     * @param array|null $tables Tables to remove from (if null, will be looked up from cache)
     * @return void
     */
    private function removeKeyFromTableIndexes(string $key, ?array $tables = null): void
    {
        // If tables not provided, try to get them from the cached entry
        if ($tables === null) {
            $cached = $this->requestCache[$key] ?? null;
            if ($cached && isset($cached['tables'])) {
                $tables = $cached['tables'];
            } else {
                // Try to get from Redis
                try {
                    $fullKey = $this->buildFullKey($key);
                    $tablesJson = $this->redis->hget($fullKey, 'tables');
                    $tables = $tablesJson ? json_decode($tablesJson, true) : [];
                } catch (\Exception $e) {
                    $tables = [];
                }
            }
        }

        if (empty($tables)) {
            return;
        }

        try {
            // Use pipeline to remove key from all table indexes
            $this->redis->pipeline(function ($pipe) use ($key, $tables) {
                foreach ($tables as $table) {
                    $indexKey = self::TABLE_INDEX_PREFIX . $table;
                    $pipe->srem($indexKey, $key);
                }
            });
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to remove key from table indexes', [
                    'key' => $key,
                    'tables' => $tables,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Delete keys and clean up their table indexes
     *
     * @param array $keys
     * @param array $affectedTables Tables that triggered the invalidation
     * @return void
     */
    private function pipelineDeleteWithIndexCleanup(array $keys, array $affectedTables): void
    {
        // Clear L1 cache for these keys first
        foreach ($keys as $key) {
            unset($this->requestCache[$key]);
        }

        try {
            // Pipeline all deletions: cache entries, tracking set removal, and table index cleanup
            $this->redis->pipeline(function ($pipe) use ($keys, $affectedTables) {
                foreach ($keys as $key) {
                    $fullKey = $this->buildFullKey($key);
                    $pipe->del($fullKey);
                    $pipe->srem(self::KEYS_SET, $key);

                    // Remove from all affected table indexes
                    foreach ($affectedTables as $table) {
                        $indexKey = self::TABLE_INDEX_PREFIX . $table;
                        $pipe->srem($indexKey, $key);
                    }
                }
            });
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to pipeline delete with index cleanup', [
                    'keys_count' => count($keys),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get all tracked keys from the Set
     *
     * @return array
     */
    private function getAllTrackedKeys(): array
    {
        try {
            // Get all members from Set using SMEMBERS (AWS/Valkey compatible)
            $keys = $this->redis->smembers(self::KEYS_SET);
            return $keys ?: [];
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to get tracked keys', [
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }

    /**
     * Clear all tracked keys
     *
     * @return void
     */
    private function clearAllTrackedKeys(): void
    {
        $keys = $this->getAllTrackedKeys();

        if (empty($keys)) {
            // Still need to clear table indexes even if no keys tracked
            $this->clearAllTableIndexes();
            return;
        }

        $this->pipelineDelete($keys);

        // Clear the tracking Set itself
        try {
            $this->redis->del(self::KEYS_SET);
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to clear tracking set', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Clear all table indexes
        $this->clearAllTableIndexes();
    }

    /**
     * Clear all table-based indexes
     *
     * Uses SCAN to find and delete all table index keys matching the prefix.
     *
     * @return void
     */
    private function clearAllTableIndexes(): void
    {
        try {
            // Use SCAN to find all table index keys (AWS/Valkey compatible)
            $cursor = '0';
            $pattern = self::TABLE_INDEX_PREFIX . '*';
            $keysToDelete = [];

            do {
                $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => 100]);

                if ($result === false) {
                    break;
                }

                $cursor = $result[0];
                $foundKeys = $result[1] ?? [];

                if (!empty($foundKeys)) {
                    $keysToDelete = array_merge($keysToDelete, $foundKeys);
                }
            } while ($cursor !== '0');

            // Delete all found table index keys
            if (!empty($keysToDelete)) {
                $this->redis->del(...$keysToDelete);
            }
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to clear table indexes', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Invalidate using table-based index (O(1) lookup per table)
     *
     * Instead of scanning all cached queries, we look up the inverted index
     * to find only the keys that reference the affected tables.
     *
     * @param array $tables
     * @param string $query
     * @return int
     */
    private function invalidateByTablesScan(array $tables, string $query): int
    {
        if (empty($tables)) {
            return 0;
        }

        // Collect all keys from table indexes using SUNION (single Redis call)
        $keysToDelete = $this->getKeysFromTableIndexes($tables);

        if (empty($keysToDelete)) {
            return 0;
        }

        // Remove duplicates (a query might reference multiple affected tables)
        $keysToDelete = array_unique($keysToDelete);
        $invalidatedCount = count($keysToDelete);

        // Delete the cached queries and clean up indexes
        $this->pipelineDeleteWithIndexCleanup($keysToDelete, $tables);

        if ($this->config['log_enabled']) {
            Log::debug('Query Cache (Redis): Invalidated cached queries by table index', [
                'affected_tables' => $tables,
                'invalidated_count' => $invalidatedCount,
                'query' => $query
            ]);
        }

        return $invalidatedCount;
    }

    /**
     * Use Redis pipelining to GET multiple keys in one roundtrip
     *
     * @param array $keys
     * @return array
     */
    private function pipelineGet(array $keys): array
    {
        try {
            // Use Redis pipeline for batch HGETALL operations
            // pipeline() returns an array of results
            $results = $this->redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $fullKey = $this->buildFullKey($key);
                    $pipe->hgetall($fullKey);
                }
            });

            // Reconstruct arrays from Hashes
            $reconstructed = [];
            foreach ($results as $data) {
                if ($data && is_array($data) && !empty($data)) {
                    $reconstructed[] = [
                        'result' => isset($data['result']) ? $this->unserializeResult($data['result']) : null,
                        'query' => $data['query'] ?? '',
                        'executed_at' => (float)($data['executed_at'] ?? 0),
                        'cached_at' => (float)($data['cached_at'] ?? 0),
                        'hits' => (int)($data['hits'] ?? 0),
                        'tables' => isset($data['tables']) && $data['tables'] !== '' ? json_decode($data['tables'], true) : null,
                        'last_accessed' => isset($data['last_accessed']) ? (float)$data['last_accessed'] : null,
                    ];
                } else {
                    $reconstructed[] = null;
                }
            }

            return $reconstructed;
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to pipeline GET', [
                    'error' => $e->getMessage()
                ]);
            }
            return array_fill(0, count($keys), null);
        }
    }

    /**
     * Use Redis pipelining to DELETE multiple keys in one roundtrip
     *
     * @param array $keys
     * @return void
     */
    private function pipelineDelete(array $keys): void
    {
        // Clear L1 cache for these keys first (critical for same-request consistency)
        foreach ($keys as $key) {
            unset($this->requestCache[$key]);
        }

        try {
            // Pipeline DELETE operations and tracking Set removal in one pipeline
            $this->redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $fullKey = $this->buildFullKey($key);
                    $pipe->del($fullKey);
                    $pipe->srem(self::KEYS_SET, $key);
                }
            });
        } catch (\Exception $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to pipeline DELETE', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
