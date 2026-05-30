<?php

namespace webO3\LaravelDbCache\Drivers;

use webO3\LaravelDbCache\Contracts\QueryCacheDriver;
use webO3\LaravelDbCache\Utils\SqlTableExtractor;
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
     * Dynamically prefixed with tenant context when set
     */
    private string $keysSet = 'db_cache:keys';

    /**
     * Redis Set key prefix for table-based index (inverted index)
     * Format: db_cache:table:{table_name} -> Set of query cache keys
     * Dynamically prefixed with tenant context when set
     */
    private string $tableIndexPrefix = 'db_cache:table:';

    /**
     * Redis Set tracking every table that has an index, so flush can iterate
     * without SCAN. SCAN returns raw (client-prefixed) keys which can't be
     * round-tripped back through the Redis client without double-prefixing.
     * Dynamically prefixed with tenant context when set.
     */
    private string $tablesSet = 'db_cache:tables';

    /**
     * Current tenant ID for cache isolation
     */
    private ?string $tenantId = null;

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
     * Circuit breaker: timestamp (microtime) until which Redis is considered
     * down and skipped, after a connection/timeout error. Prevents a Redis
     * outage from turning every query into a per-call socket-timeout against a
     * dead node (DB stampede) and flooding the log with one error per query.
     */
    private ?float $circuitOpenUntil = null;

    /**
     * Seconds to skip Redis after a connection/timeout error.
     */
    private const CIRCUIT_COOLDOWN_SECONDS = 5;

    /**
     * Cached data-key prefix ("{appSlug}_database_{cachePrefix}:"), computed
     * once instead of resolving config + slugifying on every key build (which
     * happened per-key inside the pipelined stats/delete loops).
     */
    private string $keyPrefix;

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'ttl' => 300, // 5 minutes default
            'log_enabled' => false,
            'redis_connection' => 'db_cache',
        ], $config);

        $appSlug = \Illuminate\Support\Str::slug(config('app.name', 'laravel'), '_');
        $cachePrefix = config('cache.prefix');
        $this->keyPrefix = "{$appSlug}_database_{$cachePrefix}:";

        // Get direct Redis connection
        $redisConnectionName = $this->config['redis_connection'];
        $this->redis = Redis::connection($redisConnectionName);
    }

    /**
     * {@inheritDoc}
     */
    public function setTenantContext(string $tenantId): void
    {
        if ($this->tenantId === $tenantId) {
            return;
        }

        $this->tenantId = $tenantId;
        $this->keysSet = "db_cache:t:{$tenantId}:keys";
        $this->tableIndexPrefix = "db_cache:t:{$tenantId}:table:";
        $this->tablesSet = "db_cache:t:{$tenantId}:tables";

        // Flush L1 cache on tenant switch to prevent cross-tenant leakage
        $this->requestCache = [];
    }

    /**
     * Build full Redis key with Laravel prefix
     *
     * @param string $key
     * @return string
     */
    private function buildFullKey(string $key): string
    {
        if ($this->tenantId !== null) {
            return "{$this->keyPrefix}t:{$this->tenantId}:{$key}";
        }

        return "{$this->keyPrefix}{$key}";
    }

    /**
     * Uncompressed payload size (bytes) above which gzip is applied.
     */
    private const COMPRESS_THRESHOLD = 10240;

    /**
     * Serialize a result for storage.
     *
     * Every payload carries an explicit 2-char format marker (IB/IZ/PS/PZ) so
     * the reader decodes by marker instead of guessing — guessing broke when
     * igbinary availability differed between the writer and a reader, and the
     * old 'i:'/'c:' markers could fatally call an undefined function.
     *
     *   IB = igbinary, raw          IZ = igbinary + gzip
     *   PS = php serialize, raw     PZ = php serialize + gzip
     */
    private function serializeResult(mixed $result): string
    {
        if (function_exists('igbinary_serialize')) {
            $serialized = \igbinary_serialize($result);

            return strlen($serialized) > self::COMPRESS_THRESHOLD
                ? 'IZ' . \gzcompress($serialized, 6)
                : 'IB' . $serialized;
        }

        $serialized = serialize($result);

        return strlen($serialized) > self::COMPRESS_THRESHOLD
            ? 'PZ' . \gzcompress($serialized, 6)
            : 'PS' . $serialized;
    }

    /**
     * Decode a stored payload strictly by its format marker.
     *
     * Throws on any unreadable payload (unknown/legacy marker, missing igbinary
     * extension, corrupt gzip, corrupt serialization) so the caller treats it
     * as a cache miss and re-populates, rather than returning a wrong value or
     * fatally erroring on an undefined function. PHP-serialize payloads are
     * restricted to stdClass (query rows) to deny object-injection gadgets.
     *
     * @throws \RuntimeException
     */
    private function unserializeResult(string $data): mixed
    {
        $marker = substr($data, 0, 2);
        $payload = substr($data, 2);

        switch ($marker) {
            case 'IB':
            case 'IZ':
                if (!function_exists('igbinary_unserialize')) {
                    throw new \RuntimeException('Query Cache (Redis): igbinary extension unavailable for cached payload');
                }
                if ($marker === 'IZ') {
                    $payload = $this->gunzip($payload);
                }
                return \igbinary_unserialize($payload);

            case 'PS':
            case 'PZ':
                if ($marker === 'PZ') {
                    $payload = $this->gunzip($payload);
                }
                $value = @unserialize($payload, ['allowed_classes' => ['stdClass']]);
                if ($value === false && $payload !== 'b:0;') {
                    throw new \RuntimeException('Query Cache (Redis): corrupt serialized cache payload');
                }
                return $value;

            default:
                // Unknown or legacy (pre-marker) format — treat as a miss.
                throw new \RuntimeException('Query Cache (Redis): unknown cache payload format');
        }
    }

    /**
     * gzuncompress with failure converted to an exception.
     *
     * @throws \RuntimeException
     */
    private function gunzip(string $data): string
    {
        $raw = @gzuncompress($data);
        if ($raw === false) {
            throw new \RuntimeException('Query Cache (Redis): corrupt compressed cache payload');
        }
        return $raw;
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

        // Skip Redis entirely while the circuit breaker is open (recent outage).
        if ($this->circuitOpen()) {
            return null;
        }

        // L2 Cache: read from Redis. Any I/O failure (phpredis \RedisException
        // or a predis connection exception) trips the breaker — catch \Throwable
        // so it is client-agnostic — and reports a miss.
        try {
            $fullKey = $this->buildFullKey($key);
            $data = $this->redis->hgetall($fullKey);
            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'get');
            return null;
        }

        // Treat a hash that exists but has no 'result' field as a miss.
        // recordHit()'s HINCRBY can resurrect a just-expired key into a
        // TTL-less hash holding only {hits, last_accessed} and no 'result'.
        // Returning it would hand the caller ['result' => null] — a wrong,
        // sticky null. Missing instead makes the query re-run and put()
        // rewrite a complete entry with a fresh TTL (self-healing).
        if (empty($data) || !isset($data['result'])) {
            return null;
        }

        // Decode separately: a corrupt/unreadable payload is a data problem,
        // NOT a Redis outage, so it must report a miss (self-healing) without
        // tripping the breaker.
        try {
            $cached = [
                'result' => $this->unserializeResult($data['result']),
                'query' => $data['query'] ?? '',
                'executed_at' => (float)($data['executed_at'] ?? 0),
                'cached_at' => (float)($data['cached_at'] ?? 0),
                'hits' => (int)($data['hits'] ?? 0),
                'tables' => isset($data['tables']) && $data['tables'] !== '' ? json_decode($data['tables'], true) : null,
                'last_accessed' => isset($data['last_accessed']) ? (float)$data['last_accessed'] : null,
            ];
        } catch (\Throwable $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): undecodable cache payload, treating as miss', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }

        // Store in L1 cache for this request
        $this->requestCache[$key] = $cached;

        return $cached;
    }

    /**
     * Whether the Redis circuit breaker is currently open.
     */
    private function circuitOpen(): bool
    {
        return $this->circuitOpenUntil !== null && microtime(true) < $this->circuitOpenUntil;
    }

    /**
     * Open the circuit breaker after a Redis failure. Logs once per window
     * (not once per query) so an outage doesn't flood the log.
     */
    private function tripCircuit(\Throwable $e, string $op): void
    {
        $alreadyOpen = $this->circuitOpen();
        $this->circuitOpenUntil = microtime(true) + self::CIRCUIT_COOLDOWN_SECONDS;

        if (!$alreadyOpen) {
            Log::error('Query Cache (Redis): connection/timeout error — skipping cache for ' . self::CIRCUIT_COOLDOWN_SECONDS . 's', [
                'op' => $op,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Close the breaker after a successful Redis call.
     */
    private function closeCircuit(): void
    {
        if ($this->circuitOpenUntil !== null) {
            $this->circuitOpenUntil = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $result, string $query, float $executedAt): void
    {
        $now = microtime(true);

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

        // Keep the L1 entry but skip L2 while the breaker is open.
        if ($this->circuitOpen()) {
            return;
        }

        try {
            $fullKey = $this->buildFullKey($key);
            $serialized = $this->serializeResult($result);
            $tablesJson = json_encode($tables);
            $ttl = $this->ttlWithJitter();
            $keysSet = $this->keysSet;
            $tablesSet = $this->tablesSet;
            $tableIndexPrefix = $this->tableIndexPrefix;

            // One MULTI/EXEC writes the data hash, its TTL, the key-tracking Set
            // membership and every table index together. Doing the index writes
            // inside the same transaction (not afterward) means a crash can
            // never leave a live-but-unindexed key that nothing can invalidate.
            // (Multi-key MULTI assumes a single Redis node — see README for the
            // Redis Cluster caveat.)
            $this->redis->transaction(function ($tx) use ($fullKey, $serialized, $query, $executedAt, $now, $tablesJson, $ttl, $key, $keysSet, $tablesSet, $tableIndexPrefix, $tables) {
                $tx->hmset($fullKey, [
                    'result' => $serialized,
                    'query' => $query,
                    'executed_at' => (string)$executedAt,
                    'cached_at' => (string)$now,
                    'hits' => '0',
                    'tables' => $tablesJson,
                ]);
                $tx->expire($fullKey, $ttl);
                $tx->sadd($keysSet, $key);
                foreach ($tables as $table) {
                    $tx->sadd($tableIndexPrefix . $table, $key);
                    $tx->sadd($tablesSet, $table);
                }
            });

            $this->closeCircuit();
        } catch (\Throwable $e) {
            // Client-agnostic: trip the breaker on any Redis I/O failure. The
            // L1 entry written above still serves this request.
            $this->tripCircuit($e, 'put');
        }
    }

    /**
     * TTL with up to +10% jitter so a burst of entries created together don't
     * all expire on the same tick and stampede the database.
     */
    private function ttlWithJitter(): int
    {
        $ttl = (int) $this->config['ttl'];
        if ($ttl <= 1) {
            return $ttl;
        }

        return $ttl + random_int(0, (int) ceil($ttl * 0.1));
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
        $empty = [
            'driver' => 'redis',
            'cached_queries_count' => 0,
            'total_cache_hits' => 0,
            'queries' => [],
        ];

        // Get all tracked keys from our Set (AWS/Valkey compatible)
        $keys = $this->getAllTrackedKeys();

        if (empty($keys)) {
            return $empty;
        }

        try {
            // Metadata only via HMGET — never pull the (potentially large)
            // 'result' blob just to count/list cached queries.
            $rows = $this->redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $pipe->hmget($this->buildFullKey($key), ['query', 'tables', 'hits', 'cached_at']);
                }
            });
            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'getStats');
            return $empty;
        }

        $queries = [];
        $totalHits = 0;
        $deadKeys = [];

        foreach ($keys as $i => $key) {
            $row = $rows[$i] ?? null;
            if (!is_array($row)) {
                $deadKeys[] = $key;
                continue;
            }

            // Normalize phpredis (assoc, keyed by field) vs predis (positional).
            $queryStr = $row['query'] ?? ($row[0] ?? null);
            $tablesRaw = $row['tables'] ?? ($row[1] ?? null);
            $hits = $row['hits'] ?? ($row[2] ?? null);
            $cachedAt = $row['cached_at'] ?? ($row[3] ?? null);

            if ($queryStr === null || $queryStr === false) {
                // Hash expired (TTL) but the key lingered in the tracking Set.
                $deadKeys[] = $key;
                continue;
            }

            $tables = (is_string($tablesRaw) && $tablesRaw !== '')
                ? json_decode($tablesRaw, true)
                : null;
            if ($tables === null) {
                $tables = SqlTableExtractor::extract($queryStr);
            }

            $hits = (int) $hits;
            $queries[] = [
                'query' => $queryStr,
                'tables' => $tables,
                'hits' => $hits,
                'cached_at' => (float) $cachedAt,
            ];
            $totalHits += $hits;
        }

        // Reconcile: drop tracking-Set members whose hash has expired, so the
        // Set can't grow unbounded with dead references over time.
        if (!empty($deadKeys)) {
            $this->pruneTrackedKeys($deadKeys);
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
        // The hits / last_accessed fields exist only to feed getStats(), which
        // is read solely by the stats middleware when log_enabled is set. With
        // stats off (the default) skip the two Redis round-trips entirely: they
        // add latency to every cache hit and are the only path by which a
        // just-expired key gets resurrected (via HINCRBY) into a TTL-less,
        // result-less hash. get() guards against such zombies, but not
        // creating them in the first place is cheaper and cleaner.
        if (!$this->config['log_enabled']) {
            return;
        }

        if ($this->circuitOpen()) {
            return;
        }

        // Invalidate L1 cache so next get() fetches updated hits from Redis
        unset($this->requestCache[$key]);

        try {
            $fullKey = $this->buildFullKey($key);
            $now = microtime(true);

            // Both writes in one pipeline (one round-trip). TTL is intentionally
            // NOT refreshed: it bounds staleness since put(), not idle time —
            // refreshing on every hit let hot keys live forever and serve stale
            // data. HINCRBY can resurrect a just-expired key into a result-less
            // hash; get() guards against that zombie.
            $this->redis->pipeline(function ($pipe) use ($fullKey, $now) {
                $pipe->hincrby($fullKey, 'hits', 1);
                $pipe->hset($fullKey, 'last_accessed', (string)$now);
            });

            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'recordHit');
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
     * {@inheritDoc}
     */
    public function flushRequestCache(): void
    {
        $this->requestCache = [];
        SqlTableExtractor::resetCache();
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
            $this->redis->srem($this->keysSet, $key);
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
                fn($table) => $this->tableIndexPrefix . $table,
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
                    $indexKey = $this->tableIndexPrefix . $table;
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
            // Read each key's own table list so we can remove it from ALL of its
            // table indexes — not just the tables that triggered this
            // invalidation. Otherwise a key referencing [users, posts] that is
            // invalidated via `users` lingers forever in the `posts` index.
            $tablesByKey = $this->redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $pipe->hget($this->buildFullKey($key), 'tables');
                }
            });

            $this->redis->pipeline(function ($pipe) use ($keys, $affectedTables, $tablesByKey) {
                foreach ($keys as $i => $key) {
                    $pipe->del($this->buildFullKey($key));
                    $pipe->srem($this->keysSet, $key);

                    $raw = $tablesByKey[$i] ?? null;
                    $ownTables = (is_string($raw) && $raw !== '') ? (json_decode($raw, true) ?: []) : [];

                    // Union of the key's own tables and the invalidation's
                    // tables (the latter covers already-expired keys whose
                    // 'tables' field is gone).
                    foreach (array_unique(array_merge($ownTables, $affectedTables)) as $table) {
                        $pipe->srem($this->tableIndexPrefix . $table, $key);
                    }
                }
            });

            $this->closeCircuit();
        } catch (\Throwable $e) {
            if ($this->config['log_enabled']) {
                Log::warning('Query Cache (Redis): Failed to pipeline delete with index cleanup', [
                    'keys_count' => count($keys),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Remove leaked members from the key-tracking Set (keys whose hash has
     * already expired via TTL). Best-effort housekeeping; failures are ignored.
     *
     * @param array $keys
     * @return void
     */
    private function pruneTrackedKeys(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        try {
            $this->redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $pipe->srem($this->keysSet, $key);
                }
            });
        } catch (\Throwable $e) {
            // Housekeeping only — ignore.
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
            $keys = $this->redis->smembers($this->keysSet);
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
            $this->redis->del($this->keysSet);
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
     * Iterates the tracking Set of table names and deletes each index key
     * as a logical key so the Redis client applies its prefix exactly once.
     * SCAN cannot be used here: it returns raw (already-prefixed) keys, which
     * the client would prefix again on DEL, producing double-prefixed names
     * that never match.
     *
     * @return void
     */
    private function clearAllTableIndexes(): void
    {
        try {
            $tables = $this->redis->smembers($this->tablesSet);

            if (empty($tables)) {
                return;
            }

            $this->redis->pipeline(function ($pipe) use ($tables) {
                foreach ($tables as $table) {
                    $pipe->del($this->tableIndexPrefix . $table);
                }
                $pipe->del($this->tablesSet);
            });
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
                    $pipe->srem($this->keysSet, $key);
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
