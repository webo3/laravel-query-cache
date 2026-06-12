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
 * - HMAC-authenticated payloads (no unserialization of unauthenticated data)
 */
class RedisQueryCacheDriver implements QueryCacheDriver
{
    /**
     * Redis Set key to track all cached query keys.
     * Built by applyTenantNamespace() — carries the app/cache prefix and the
     * tenant namespace when one is set.
     */
    private string $keysSet;

    /**
     * Redis Set key prefix for table-based index (inverted index)
     * Format: {prefix}db_cache:table:{table_name} -> Set of query cache keys
     */
    private string $tableIndexPrefix;

    /**
     * Redis Set tracking every table that has an index, so flush can iterate
     * without SCAN. SCAN returns raw (client-prefixed) keys which can't be
     * round-tripped back through the Redis client without double-prefixing.
     */
    private string $tablesSet;

    /**
     * Redis Set registering every tenant ID that has ever had a namespace on
     * this cache, so flush-all and prune can iterate tenant namespaces.
     * Never tenant-prefixed itself.
     */
    private string $tenantsSet;

    /**
     * Current tenant ID for cache isolation
     */
    private ?string $tenantId = null;

    /**
     * Tenant IDs already registered in the tenant Set this process, so a
     * per-request setTenantContext() doesn't cost a Redis round-trip each time.
     */
    private static array $registeredTenants = [];

    /**
     * Configuration
     */
    private array $config;

    /**
     * Direct Redis connection for Hash operations and pipelining.
     * Resolved lazily so a missing/unreachable Redis configuration degrades to
     * "no caching" (via the circuit breaker) instead of throwing while the
     * database connection itself is being constructed.
     */
    private $redis = null;

    /**
     * L1 cache: Request-level in-memory cache
     * Prevents repeated Redis calls for the same query within a single HTTP request
     */
    private array $requestCache = [];

    /**
     * Inverted table index for the L1 cache (table => [key => true]).
     * Invalidation must be able to purge L1 entries even when Redis (and its
     * authoritative index) is unreachable — otherwise an entry cached while
     * the circuit breaker is open would serve stale rows after a same-request
     * mutation.
     */
    private array $l1TableIndex = [];

    /**
     * Per-request hit/miss counters, reset at request boundaries. These feed
     * getStats() so the stats middleware can report an honest per-request hit
     * rate instead of mixing lifetime hit counters with key counts.
     */
    private int $requestHits = 0;

    private int $requestMisses = 0;

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
     * Maximum number of commands per pipeline so a large invalidation can't
     * buffer an unbounded burst against Redis in one shot.
     */
    private const PIPELINE_CHUNK = 500;

    /**
     * Cached data-key prefix ("{appSlug}_database_{cachePrefix}:"), computed
     * once instead of resolving config + slugifying on every key build (which
     * happened per-key inside the pipelined stats/delete loops).
     */
    private string $keyPrefix;

    /**
     * HMAC key for payload authentication, derived from app.key.
     */
    private string $hmacKey;

    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'ttl' => 300, // 5 minutes default
            'log_enabled' => false,
            'redis_connection' => 'db_cache',
            'max_result_bytes' => 1048576, // 1 MiB — skip L2 for larger results
        ], $config);

        $appSlug = \Illuminate\Support\Str::slug(config('app.name', 'laravel'), '_');
        $cachePrefix = config('cache.prefix');
        $this->keyPrefix = "{$appSlug}_database_{$cachePrefix}:";

        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }
        $this->hmacKey = $appKey;

        $this->tenantsSet = $this->keyPrefix . 'db_cache:tenants';
        $this->applyTenantNamespace(null);
    }

    /**
     * Resolve the Redis connection lazily. Failures surface as exceptions in
     * the calling op's try/catch and trip the circuit breaker.
     */
    private function redis()
    {
        return $this->redis ??= Redis::connection($this->config['redis_connection']);
    }

    /**
     * Point the tracking-set names at the given tenant namespace (or the
     * default namespace for null). All set keys carry the same app/cache
     * prefix as the data keys, so two apps sharing a Redis database can never
     * share (and mutually destroy) each other's indexes.
     */
    private function applyTenantNamespace(?string $tenantId): void
    {
        $this->tenantId = $tenantId;

        if ($tenantId === null) {
            $this->keysSet = $this->keyPrefix . 'db_cache:keys';
            $this->tableIndexPrefix = $this->keyPrefix . 'db_cache:table:';
            $this->tablesSet = $this->keyPrefix . 'db_cache:tables';

            return;
        }

        $this->keysSet = $this->keyPrefix . "db_cache:t:{$tenantId}:keys";
        $this->tableIndexPrefix = $this->keyPrefix . "db_cache:t:{$tenantId}:table:";
        $this->tablesSet = $this->keyPrefix . "db_cache:t:{$tenantId}:tables";
    }

    /**
     * Run a callback against another tenant's namespace, restoring the
     * current one afterwards.
     */
    private function withTenantNamespace(?string $tenantId, callable $fn): mixed
    {
        $saved = [$this->tenantId, $this->keysSet, $this->tableIndexPrefix, $this->tablesSet];

        $this->applyTenantNamespace($tenantId);

        try {
            return $fn();
        } finally {
            [$this->tenantId, $this->keysSet, $this->tableIndexPrefix, $this->tablesSet] = $saved;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException When the tenant ID contains characters
     *         that could collide with the Redis key namespace structure.
     */
    public function setTenantContext(string $tenantId): void
    {
        // The ID is embedded verbatim in Redis key names; reject separators
        // (':' in particular) that would let one crafted ID overlap another
        // tenant's namespace.
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $tenantId)) {
            throw new \InvalidArgumentException(
                "Query Cache: invalid tenant ID [{$tenantId}] — only letters, digits, '.', '_' and '-' are allowed."
            );
        }

        if ($this->tenantId === $tenantId) {
            return;
        }

        $this->applyTenantNamespace($tenantId);

        // Flush L1 cache on tenant switch to prevent cross-tenant leakage
        $this->l1Flush();

        $this->registerTenant($tenantId);
    }

    /**
     * Record the tenant ID in the tenant registry Set (best-effort) so
     * flush-all / prune can enumerate tenant namespaces. Memoized per process.
     */
    private function registerTenant(string $tenantId): void
    {
        if (isset(self::$registeredTenants[$tenantId]) || $this->circuitOpen()) {
            return;
        }

        try {
            $this->redis()->sadd($this->tenantsSet, $tenantId);
            self::$registeredTenants[$tenantId] = true;
            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'registerTenant');
        }
    }

    /**
     * Tenant IDs known to the registry Set.
     */
    private function getRegisteredTenants(): array
    {
        if ($this->circuitOpen()) {
            return [];
        }

        try {
            return $this->redis()->smembers($this->tenantsSet) ?: [];
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'getRegisteredTenants');

            return [];
        }
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
     * The body carries an explicit 2-char format marker (IB/IZ/PS/PZ) so the
     * reader decodes by marker instead of guessing:
     *
     *   IB = igbinary, raw          IZ = igbinary + gzip
     *   PS = php serialize, raw     PZ = php serialize + gzip
     *
     * The stored payload is 'S1' + HMAC-SHA256(body) + body: any payload that
     * fails authentication is rejected *before* unserialization, so an
     * attacker with Redis write access can never reach PHP unserialization.
     * This matters most for igbinary, which (unlike unserialize()) has no
     * allowed_classes filter to deny object-injection gadgets.
     */
    private function serializeResult(mixed $result): string
    {
        if (function_exists('igbinary_serialize')) {
            $serialized = \igbinary_serialize($result);

            $body = strlen($serialized) > self::COMPRESS_THRESHOLD
                ? 'IZ' . \gzcompress($serialized, 6)
                : 'IB' . $serialized;
        } else {
            $serialized = serialize($result);

            $body = strlen($serialized) > self::COMPRESS_THRESHOLD
                ? 'PZ' . \gzcompress($serialized, 6)
                : 'PS' . $serialized;
        }

        return 'S1' . hash_hmac('sha256', $body, $this->hmacKey, true) . $body;
    }

    /**
     * Decode a stored payload: authenticate the HMAC, then decode strictly by
     * the format marker.
     *
     * Throws on any unreadable payload (failed authentication, unknown/legacy
     * marker, missing igbinary extension, corrupt gzip, corrupt serialization)
     * so the caller treats it as a cache miss and re-populates, rather than
     * returning a wrong value or fatally erroring. PHP-serialize payloads are
     * additionally restricted to stdClass (query rows) as defense in depth.
     *
     * @throws \RuntimeException
     */
    private function unserializeResult(string $data): mixed
    {
        // 'S1' + 32 raw HMAC bytes + 2-char marker minimum.
        if (!str_starts_with($data, 'S1') || strlen($data) < 36) {
            // Unknown or legacy (pre-HMAC) format — treat as a miss.
            throw new \RuntimeException('Query Cache (Redis): unknown cache payload format');
        }

        $mac = substr($data, 2, 32);
        $body = substr($data, 34);

        if (!hash_equals(hash_hmac('sha256', $body, $this->hmacKey, true), $mac)) {
            throw new \RuntimeException('Query Cache (Redis): cache payload failed authentication');
        }

        $marker = substr($body, 0, 2);
        $payload = substr($body, 2);

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
            $this->requestHits++;

            return $this->requestCache[$key];
        }

        // Skip Redis entirely while the circuit breaker is open (recent outage).
        if ($this->circuitOpen()) {
            $this->requestMisses++;

            return null;
        }

        // L2 Cache: read from Redis. Any I/O failure (phpredis \RedisException
        // or a predis connection exception) trips the breaker — catch \Throwable
        // so it is client-agnostic — and reports a miss.
        try {
            $fullKey = $this->buildFullKey($key);
            $data = $this->redis()->hgetall($fullKey);
            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'get');
            $this->requestMisses++;

            return null;
        }

        // Treat a hash that exists but has no 'result' field as a miss.
        // recordHit()'s HINCRBY can resurrect a just-expired key into a
        // TTL-less hash holding only {hits, last_accessed} and no 'result'.
        // Returning it would hand the caller ['result' => null] — a wrong,
        // sticky null. Missing instead makes the query re-run and put()
        // rewrite a complete entry with a fresh TTL (self-healing).
        if (empty($data) || !isset($data['result'])) {
            $this->requestMisses++;

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
            $this->requestMisses++;

            return null;
        }

        // The L1 inverted index needs the table list; reconstruct it from the
        // query when the stored field is missing so local invalidation works.
        if (!is_array($cached['tables'])) {
            $cached['tables'] = $cached['query'] !== ''
                ? SqlTableExtractor::extract($cached['query'])
                : [];
        }

        // Store in L1 cache for this request
        $this->l1Put($key, $cached);

        $this->requestHits++;

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
            Log::error('Query Cache (Redis): connection/timeout error — skipping cache reads, writes and L2 invalidation for ' . self::CIRCUIT_COOLDOWN_SECONDS . 's', [
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
     * Store an entry in the L1 cache and its inverted table index.
     */
    private function l1Put(string $key, array $entry): void
    {
        $this->requestCache[$key] = $entry;

        foreach (($entry['tables'] ?? []) ?: [] as $table) {
            $this->l1TableIndex[$table][$key] = true;
        }
    }

    /**
     * Remove an entry from the L1 cache and its inverted table index.
     */
    private function l1Forget(string $key): void
    {
        $tables = $this->requestCache[$key]['tables'] ?? [];

        unset($this->requestCache[$key]);

        foreach ((array) $tables as $table) {
            unset($this->l1TableIndex[$table][$key]);
            if (empty($this->l1TableIndex[$table])) {
                unset($this->l1TableIndex[$table]);
            }
        }
    }

    /**
     * Purge every L1 entry referencing any of the given tables. Runs on plain
     * PHP arrays so it works even while Redis is unreachable.
     */
    private function l1InvalidateTables(array $tables): void
    {
        foreach ($tables as $table) {
            foreach (array_keys($this->l1TableIndex[$table] ?? []) as $key) {
                $this->l1Forget($key);
            }
        }
    }

    /**
     * Drop the whole L1 cache and its index.
     */
    private function l1Flush(): void
    {
        $this->requestCache = [];
        $this->l1TableIndex = [];
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, mixed $result, string $query, float $executedAt): void
    {
        $now = microtime(true);

        // Extract tables upfront for indexing (required for efficient invalidation)
        $tables = SqlTableExtractor::extract($query);

        // Store in L1 cache immediately (no network overhead — the entry holds
        // a reference to a result that is already in memory this request).
        $this->l1Put($key, [
            'result' => $result,
            'query' => $query,
            'executed_at' => $executedAt,
            'cached_at' => $now,
            'hits' => 0,
            'tables' => $tables,
            'last_accessed' => null,
        ]);

        // Keep the L1 entry but skip L2 while the breaker is open.
        if ($this->circuitOpen()) {
            return;
        }

        $serialized = $this->serializeResult($result);

        // Oversized results are served from L1 for this request but never
        // shipped to Redis: one giant result set must not blow Redis memory or
        // add multi-MB writes to the request that missed.
        $maxBytes = (int) $this->config['max_result_bytes'];
        if ($maxBytes > 0 && strlen($serialized) > $maxBytes) {
            if ($this->config['log_enabled']) {
                Log::debug('Query Cache (Redis): result exceeds max_result_bytes, skipping L2 cache', [
                    'key' => $key,
                    'bytes' => strlen($serialized),
                    'max_result_bytes' => $maxBytes,
                ]);
            }

            return;
        }

        try {
            $fullKey = $this->buildFullKey($key);
            $tablesJson = json_encode($tables);
            $ttl = $this->ttlWithJitter();
            $indexTtl = $this->indexTtl();
            $keysSet = $this->keysSet;
            $tablesSet = $this->tablesSet;
            $tableIndexPrefix = $this->tableIndexPrefix;

            // One MULTI/EXEC writes the data hash, its TTL, the key-tracking Set
            // membership and every table index together. Doing the index writes
            // inside the same transaction (not afterward) means a crash can
            // never leave a live-but-unindexed key that nothing can invalidate.
            // The tracking/index sets get their own (longer) TTL, refreshed on
            // every put: without one, read-mostly tables would accumulate dead
            // members forever as data hashes expire.
            // (Multi-key MULTI assumes a single Redis node — see README for the
            // Redis Cluster caveat.)
            $this->redis()->transaction(function ($tx) use ($fullKey, $serialized, $query, $executedAt, $now, $tablesJson, $ttl, $indexTtl, $key, $keysSet, $tablesSet, $tableIndexPrefix, $tables) {
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
                $tx->expire($keysSet, $indexTtl);
                foreach ($tables as $table) {
                    $tx->sadd($tableIndexPrefix . $table, $key);
                    $tx->expire($tableIndexPrefix . $table, $indexTtl);
                    $tx->sadd($tablesSet, $table);
                }
                if (!empty($tables)) {
                    $tx->expire($tablesSet, $indexTtl);
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
     * TTL for the tracking/index sets. Refreshed on every put, and always
     * comfortably longer than the longest possible data-entry TTL (base + 10%
     * jitter) so an index can never expire while a member's data hash is still
     * alive — that would orphan a live key and break its invalidation.
     */
    private function indexTtl(): int
    {
        return max(120, 2 * (int) $this->config['ttl']);
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        if (isset($this->requestCache[$key])) {
            return true;
        }

        if ($this->circuitOpen()) {
            return false;
        }

        try {
            $fullKey = $this->buildFullKey($key);
            $exists = (bool) $this->redis()->exists($fullKey);
            $this->closeCircuit();

            return $exists;
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'has');

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        // Resolve the entry's table list BEFORE deleting anything: once the
        // hash is gone its 'tables' field is unreadable and the index cleanup
        // would silently no-op, leaking index members.
        $tables = $this->requestCache[$key]['tables'] ?? null;

        $this->l1Forget($key);

        if ($this->circuitOpen()) {
            return;
        }

        try {
            $fullKey = $this->buildFullKey($key);

            if ($tables === null) {
                $tablesJson = $this->redis()->hget($fullKey, 'tables');
                $tables = (is_string($tablesJson) && $tablesJson !== '')
                    ? (json_decode($tablesJson, true) ?: [])
                    : [];
            }

            $this->redis()->pipeline(function ($pipe) use ($fullKey, $key, $tables) {
                $pipe->del($fullKey);
                $pipe->srem($this->keysSet, $key);
                foreach ($tables as $table) {
                    $pipe->srem($this->tableIndexPrefix . $table, $key);
                }
            });

            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'forget');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateTables(array $tables, string $query): int
    {
        // Purge matching L1 entries unconditionally, before any Redis I/O:
        // local consistency must survive a Redis outage.
        if (empty($tables)) {
            $this->l1Flush();
        } else {
            $this->l1InvalidateTables($tables);
        }

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
     *
     * Without a tenant context this clears the default namespace AND every
     * namespace in the tenant registry — "clear the cache" must not silently
     * leave tenant-namespaced entries alive. With a tenant context set it
     * clears only that tenant's namespace.
     */
    public function flush(): void
    {
        // Clear L1 cache
        $this->l1Flush();

        // Clear L2 cache (Redis) for the current namespace
        $this->clearAllTrackedKeys();

        if ($this->tenantId !== null) {
            return;
        }

        foreach ($this->getRegisteredTenants() as $tenant) {
            $this->withTenantNamespace($tenant, fn () => $this->clearAllTrackedKeys());
        }

        try {
            $this->redis()->del($this->tenantsSet);
            self::$registeredTenants = [];
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'flush');
        }
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
            'request_hits' => $this->requestHits,
            'request_misses' => $this->requestMisses,
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
            $rows = [];
            foreach (array_chunk($keys, self::PIPELINE_CHUNK) as $chunk) {
                $chunkRows = $this->redis()->pipeline(function ($pipe) use ($chunk) {
                    foreach ($chunk as $key) {
                        $pipe->hmget($this->buildFullKey($key), ['query', 'tables', 'hits', 'cached_at']);
                    }
                });
                foreach ($chunkRows as $row) {
                    $rows[] = $row;
                }
            }
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
            'request_hits' => $this->requestHits,
            'request_misses' => $this->requestMisses,
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

        // Track the hit on the L1 entry locally instead of evicting it:
        // evicting forced the next identical query back to Redis, effectively
        // disabling L1 (and doubling Redis traffic) whenever stats logging was
        // on. The authoritative counter lives in Redis via HINCRBY below.
        if (isset($this->requestCache[$key])) {
            $this->requestCache[$key]['hits']++;
            $this->requestCache[$key]['last_accessed'] = microtime(true);
        }

        if ($this->circuitOpen()) {
            return;
        }

        try {
            $fullKey = $this->buildFullKey($key);
            $now = microtime(true);

            // Both writes in one pipeline (one round-trip). TTL is intentionally
            // NOT refreshed: it bounds staleness since put(), not idle time —
            // refreshing on every hit let hot keys live forever and serve stale
            // data. HINCRBY can resurrect a just-expired key into a result-less
            // hash; get() guards against that zombie.
            $this->redis()->pipeline(function ($pipe) use ($fullKey, $now) {
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
        $this->l1Flush();
        $this->requestHits = 0;
        $this->requestMisses = 0;

        // Drop the tenant namespace: connection (and driver) instances persist
        // across requests under Octane/queue workers, and a stale tenant
        // context would defeat the tenant_required fail-safe for the next
        // request.
        $this->applyTenantNamespace(null);

        SqlTableExtractor::resetCache();
    }

    /**
     * {@inheritDoc}
     *
     * Walks the tracking Set and every table index, removing members whose
     * data hash has expired. Without a tenant context, also reconciles every
     * registered tenant namespace.
     */
    public function pruneExpired(): int
    {
        $removed = $this->pruneNamespace();

        if ($this->tenantId === null) {
            foreach ($this->getRegisteredTenants() as $tenant) {
                $removed += $this->withTenantNamespace($tenant, fn () => $this->pruneNamespace());
            }
        }

        return $removed;
    }

    /**
     * Reconcile the current namespace's tracking Set and table indexes against
     * the keys that still exist. Best-effort: failures trip the breaker and
     * report 0.
     */
    private function pruneNamespace(): int
    {
        if ($this->circuitOpen()) {
            return 0;
        }

        try {
            $removed = 0;

            // Tracking Set: drop members whose data hash has expired.
            $keys = $this->redis()->smembers($this->keysSet) ?: [];
            foreach (array_chunk($keys, self::PIPELINE_CHUNK) as $chunk) {
                $dead = $this->missingKeys($chunk);
                if (!empty($dead)) {
                    $this->pruneTrackedKeys($dead);
                    $removed += count($dead);
                }
            }

            // Table indexes: drop members whose data hash has expired; drop
            // the table from the tables Set when its index empties out.
            $tables = $this->redis()->smembers($this->tablesSet) ?: [];
            foreach ($tables as $table) {
                $indexKey = $this->tableIndexPrefix . $table;
                $members = $this->redis()->smembers($indexKey) ?: [];
                $deadCount = 0;

                foreach (array_chunk($members, self::PIPELINE_CHUNK) as $chunk) {
                    $dead = $this->missingKeys($chunk);
                    if (empty($dead)) {
                        continue;
                    }
                    $this->redis()->pipeline(function ($pipe) use ($indexKey, $dead) {
                        foreach ($dead as $key) {
                            $pipe->srem($indexKey, $key);
                        }
                    });
                    $deadCount += count($dead);
                }

                $removed += $deadCount;

                if ($deadCount > 0 && $deadCount >= count($members)) {
                    // Redis removes empty sets automatically; just unregister.
                    $this->redis()->srem($this->tablesSet, $table);
                }
            }

            $this->closeCircuit();

            return $removed;
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'prune');

            return 0;
        }
    }

    /**
     * Of the given cache keys, return those whose data hash no longer exists.
     */
    private function missingKeys(array $keys): array
    {
        $exists = $this->redis()->pipeline(function ($pipe) use ($keys) {
            foreach ($keys as $key) {
                $pipe->exists($this->buildFullKey($key));
            }
        });

        $dead = [];
        foreach ($keys as $i => $key) {
            if (empty($exists[$i])) {
                $dead[] = $key;
            }
        }

        return $dead;
    }

    /**
     * Get all cache keys that reference any of the given tables
     *
     * @param array $tables
     * @return array
     */
    private function getKeysFromTableIndexes(array $tables): array
    {
        if (empty($tables) || $this->circuitOpen()) {
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
                $keys = $this->redis()->smembers($indexKeys[0]);
            } else {
                $keys = $this->redis()->sunion(...$indexKeys);
            }

            $this->closeCircuit();

            return $keys ?: [];
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'getKeysFromTableIndexes');

            // Invalidation could not see the index — always log: this is a
            // correctness event (stale entries survive until TTL), not debug
            // chatter. tripCircuit() above already logged the outage itself
            // once per window.
            Log::warning('Query Cache (Redis): could not read table indexes during invalidation; L2 entries may stay stale until TTL', [
                'tables' => $tables,
            ]);

            return [];
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
            $this->l1Forget($key);
        }

        if ($this->circuitOpen()) {
            return;
        }

        try {
            foreach (array_chunk($keys, self::PIPELINE_CHUNK) as $chunk) {
                // Read each chunk's table lists so we can remove each key from
                // ALL of its table indexes — not just the tables that triggered
                // this invalidation. Otherwise a key referencing [users, posts]
                // that is invalidated via `users` lingers forever in the
                // `posts` index.
                $tablesByKey = $this->redis()->pipeline(function ($pipe) use ($chunk) {
                    foreach ($chunk as $key) {
                        $pipe->hget($this->buildFullKey($key), 'tables');
                    }
                });

                $this->redis()->pipeline(function ($pipe) use ($chunk, $affectedTables, $tablesByKey) {
                    foreach ($chunk as $i => $key) {
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
            }

            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'pipelineDeleteWithIndexCleanup');

            // A failed delete means stale data persists until TTL — always
            // log it, not only when debug logging is enabled.
            Log::warning('Query Cache (Redis): failed to delete invalidated keys; stale entries may persist until TTL', [
                'keys_count' => count($keys),
                'error' => $e->getMessage(),
            ]);
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
            foreach (array_chunk($keys, self::PIPELINE_CHUNK) as $chunk) {
                $this->redis()->pipeline(function ($pipe) use ($chunk) {
                    foreach ($chunk as $key) {
                        $pipe->srem($this->keysSet, $key);
                    }
                });
            }
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
        if ($this->circuitOpen()) {
            return [];
        }

        try {
            // Get all members from Set using SMEMBERS (AWS/Valkey compatible)
            $keys = $this->redis()->smembers($this->keysSet);
            $this->closeCircuit();

            return $keys ?: [];
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'getAllTrackedKeys');

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
        if (!$this->circuitOpen()) {
            try {
                $this->redis()->del($this->keysSet);
                $this->closeCircuit();
            } catch (\Throwable $e) {
                $this->tripCircuit($e, 'clearAllTrackedKeys');
                Log::warning('Query Cache (Redis): failed to clear tracking set during flush', [
                    'error' => $e->getMessage(),
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
        if ($this->circuitOpen()) {
            return;
        }

        try {
            $tables = $this->redis()->smembers($this->tablesSet);

            if (empty($tables)) {
                return;
            }

            foreach (array_chunk($tables, self::PIPELINE_CHUNK) as $chunk) {
                $this->redis()->pipeline(function ($pipe) use ($chunk) {
                    foreach ($chunk as $table) {
                        $pipe->del($this->tableIndexPrefix . $table);
                    }
                });
            }
            $this->redis()->del($this->tablesSet);

            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'clearAllTableIndexes');
            Log::warning('Query Cache (Redis): failed to clear table indexes during flush', [
                'error' => $e->getMessage(),
            ]);
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
            $this->l1Forget($key);
        }

        if ($this->circuitOpen()) {
            return;
        }

        try {
            // Pipeline DELETE operations and tracking Set removal, chunked so a
            // huge flush can't buffer an unbounded command burst.
            foreach (array_chunk($keys, self::PIPELINE_CHUNK) as $chunk) {
                $this->redis()->pipeline(function ($pipe) use ($chunk) {
                    foreach ($chunk as $key) {
                        $fullKey = $this->buildFullKey($key);
                        $pipe->del($fullKey);
                        $pipe->srem($this->keysSet, $key);
                    }
                });
            }

            $this->closeCircuit();
        } catch (\Throwable $e) {
            $this->tripCircuit($e, 'pipelineDelete');
            Log::warning('Query Cache (Redis): failed to pipeline DELETE; stale entries may persist until TTL', [
                'keys_count' => count($keys),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
