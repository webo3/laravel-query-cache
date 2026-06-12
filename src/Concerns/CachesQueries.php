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
     * Whether a tenant context has been set on this connection. Used by the
     * `tenant_required` fail-safe to bypass caching until the context exists.
     */
    private bool $tenantContextSet = false;

    /**
     * Per-request cache for normalized queries to avoid redundant normalization
     */
    private static array $normalizedQueryCache = [];

    /**
     * Tables with an invalidation already scheduled for the current
     * transaction's commit (table name => true). Lets a bulk import of N
     * statements schedule one invalidation per distinct table instead of one
     * per statement.
     */
    private array $txPendingTables = [];

    /**
     * Whether a full-cache purge is already scheduled for the current
     * transaction's commit (a mutation whose tables could not be determined).
     */
    private bool $txPendingFlushAll = false;

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
        $inner = parent::cursor($query, $bindings, $useReadPdo, $fetchUsing);

        // The inner generator's first advance executes parent::run() for the
        // cursor's own SELECT; hold the flag up only for that window.
        $this->inCursorQuery = true;
        try {
            $valid = $inner->valid();
        } finally {
            $this->inCursorQuery = false;
        }

        // Stream rows with the flag down: queries issued from inside the
        // consuming loop must hit the normal cache/invalidation paths. Holding
        // the flag for the generator's whole lifetime (the old behavior)
        // silently skipped invalidation for mutations executed while iterating.
        while ($valid) {
            yield $inner->key() => $inner->current();

            $inner->next();
            $valid = $inner->valid();
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
        // Never cache cursor queries - they're designed for memory-efficient streaming.
        // Never touch the cache while pretending: pretended SELECT callbacks
        // return [] without executing (caching that would poison the key for
        // real callers), and pretended mutations must not invalidate anything.
        if ($this->inCursorQuery || $this->pretending()) {
            return parent::run($query, $bindings, $callback);
        }

        // Check if caching is enabled
        if (!$this->isCachingEnabled()) {
            return parent::run($query, $bindings, $callback);
        }

        $queryType = $this->classifyQuery($query);

        // Handle SELECT queries with caching
        if ($queryType === 'SELECT') {
            // Inside an open transaction a SELECT can observe this connection's
            // own uncommitted writes; caching them would leak dirty reads that
            // survive a rollback. Bypass caching until the transaction resolves.
            if ($this->transactionLevel() > 0) {
                return parent::run($query, $bindings, $callback);
            }

            // Never cache queries against system/metadata tables.
            if ($this->isSchemaQuery($query)) {
                return parent::run($query, $bindings, $callback);
            }

            // Never cache locking reads (FOR UPDATE / FOR SHARE / LOCK IN SHARE
            // MODE): a cache hit would skip the row lock the statement exists to
            // acquire and could return stale rows.
            if ($this->isLockingRead($query)) {
                return parent::run($query, $bindings, $callback);
            }

            // Never cache SELECT ... INTO: on Postgres it creates a table, on
            // MySQL it writes a file/variable — a cache hit would silently skip
            // the side effect.
            if ($this->isSelectInto($query)) {
                return parent::run($query, $bindings, $callback);
            }

            // Never cache queries calling nondeterministic or side-effecting
            // functions (NOW(), RAND(), UUID(), nextval(), ...): serving a
            // cached value changes their semantics — nextval() in particular
            // would hand the same sequence value to multiple callers.
            if ($this->isNonDeterministicQuery($query)) {
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

        // Handle mutation queries (INSERT, UPDATE, DELETE, CTE-wrapped DML, ...)
        if ($queryType === 'MUTATION') {
            // Run the write FIRST, then invalidate, so a concurrent reader can
            // only re-cache the post-write state. scheduleInvalidation() defers
            // to the commit boundary when inside a transaction.
            $result = parent::run($query, $bindings, $callback);
            $this->scheduleInvalidation($query);

            return $result;
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

        // Namespace the key by connection so identical SQL + bindings against a
        // different database/connection can never collide. Bindings are encoded
        // with serialize() (not json_encode): it is binary-safe and
        // type-preserving, so binary/non-UTF-8 or differently-typed parameter
        // values yield distinct keys. json_encode returns false for invalid
        // UTF-8, which would collapse the bindings and alias unrelated queries.
        $raw = $this->getName() . "\0" . $normalizedQuery . "\0" . serialize($bindings);

        // sha256, not a fast non-cryptographic hash: bindings are
        // user-influenced, and a crafted collision on a shared cache would
        // serve one query's rows to another. The hash cost is noise next to
        // the network round-trip it guards.
        return hash('sha256', $raw);
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

        // Cap the memo so long-running CLI jobs emitting many distinct SQL
        // shapes can't grow it without bound.
        if (count(self::$normalizedQueryCache) >= 5000) {
            self::$normalizedQueryCache = [];
        }

        return self::$normalizedQueryCache[$query] = $this->normalizeStructure($query);
    }

    /**
     * Collapse runs of whitespace outside quoted regions, leaving the contents
     * of string literals and quoted identifiers untouched. Identifier/keyword
     * case is intentionally preserved: on case-sensitive systems (MySQL with
     * lower_case_table_names=0) `FROM Users` and `FROM users` are different
     * tables, and folding case would alias them to one cache key — a collision
     * serving the wrong table's rows. Whitespace-only normalization keeps the
     * key stable across formatting differences without that risk.
     *
     * @param string $sql
     * @return string
     */
    private function normalizeStructure(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $quote = null;       // active quote char (' " `) or null
        $pendingSpace = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    // Backslash escape inside a string literal: copy next char verbatim.
                    $out .= $sql[++$i];
                } elseif ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        // Doubled quote = escaped quote, still inside the literal.
                        $out .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                if ($pendingSpace && $out !== '') {
                    $out .= ' ';
                }
                $pendingSpace = false;
                $quote = $ch;
                $out .= $ch;
                continue;
            }

            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\f" || $ch === "\v") {
                $pendingSpace = true;
                continue;
            }

            if ($pendingSpace && $out !== '') {
                $out .= ' ';
            }
            $pendingSpace = false;
            $out .= $ch;
        }

        return $out;
    }

    /**
     * Classify a SQL statement for caching purposes.
     *
     * Returns 'SELECT' (a cacheable read, including read-only CTEs),
     * 'MUTATION' (a write whose effect must invalidate the cache), or 'OTHER'
     * (SHOW / DESCRIBE / EXPLAIN / PRAGMA / ...). Leading comments are stripped
     * first so an instrumented query whose verb is preceded by a comment is
     * still recognised.
     *
     * @param string $sql
     * @return string
     */
    private function classifyQuery(string $sql): string
    {
        $sql = $this->stripLeadingComments($sql);

        if (preg_match('/^\s*SELECT\b/i', $sql)) {
            return 'SELECT';
        }

        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE|REPLACE|ALTER|DROP|CREATE|RENAME|MERGE|CALL|EXEC|EXECUTE|LOAD|COPY)\b/i', $sql)) {
            return 'MUTATION';
        }

        // Common Table Expressions: classify by the statement they wrap. A
        // data-modifying CTE (Postgres) or a top-level UPDATE/DELETE/INSERT
        // after the CTE list (MySQL) is a mutation; a plain WITH ... SELECT is
        // a cacheable read (SqlTableExtractor strips the CTE aliases).
        if (preg_match('/^\s*WITH\b/i', $sql)) {
            if (preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|MERGE\s+INTO|REPLACE\s+INTO)\b/i', $sql)) {
                return 'MUTATION';
            }

            return 'SELECT';
        }

        return 'OTHER';
    }

    /**
     * Strip leading line ("-- ..."), hash ("# ...") and C-style block comments
     * plus surrounding whitespace so the leading keyword can be read.
     *
     * @param string $sql
     * @return string
     */
    private function stripLeadingComments(string $sql): string
    {
        $sql = ltrim($sql);

        while ($sql !== '') {
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                if ($end === false) {
                    return ''; // unterminated comment — nothing classifiable
                }
                $sql = ltrim(substr($sql, $end + 2));
                continue;
            }

            if (str_starts_with($sql, '--') || str_starts_with($sql, '#')) {
                $nl = strpos($sql, "\n");
                if ($nl === false) {
                    return '';
                }
                $sql = ltrim(substr($sql, $nl + 1));
                continue;
            }

            break;
        }

        return $sql;
    }

    /**
     * Detect locking reads that must never be cached.
     *
     * @param string $sql
     * @return bool
     */
    private function isLockingRead(string $sql): bool
    {
        return preg_match('/\bFOR\s+UPDATE\b|\bFOR\s+SHARE\b|\bFOR\s+NO\s+KEY\s+UPDATE\b|\bFOR\s+KEY\s+SHARE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $sql) === 1;
    }

    /**
     * Detect SELECT ... INTO (table / OUTFILE / DUMPFILE / @variable) — a
     * SELECT with a side effect that a cache hit would skip. A false positive
     * (the word INTO inside a string literal) only bypasses caching, which is
     * the safe direction.
     *
     * @param string $sql
     * @return bool
     */
    private function isSelectInto(string $sql): bool
    {
        return preg_match('/\bINTO\b/i', $sql) === 1;
    }

    /**
     * Detect calls to nondeterministic or side-effecting SQL functions whose
     * results must never be served from cache. Covers MySQL and Postgres
     * time/random/uuid/sequence/session functions. A false positive (a match
     * inside a string literal) only bypasses caching — the safe direction.
     *
     * @param string $sql
     * @return bool
     */
    private function isNonDeterministicQuery(string $sql): bool
    {
        return preg_match(
            '/\b(?:NOW|SYSDATE|CURDATE|CURTIME|UNIX_TIMESTAMP|UTC_DATE|UTC_TIME|UTC_TIMESTAMP'
            . '|RAND|RANDOM|UUID|UUID_SHORT|GEN_RANDOM_UUID'
            . '|NEXTVAL|CURRVAL|LASTVAL|SETVAL|LAST_INSERT_ID'
            . '|ROW_COUNT|FOUND_ROWS|CONNECTION_ID|PG_BACKEND_PID'
            . '|CLOCK_TIMESTAMP|STATEMENT_TIMESTAMP|TRANSACTION_TIMESTAMP|TIMEOFDAY)\s*\(/i',
            $sql
        ) === 1
            || preg_match('/\bCURRENT_(?:DATE|TIME|TIMESTAMP)\b|\bLOCALTIME(?:STAMP)?\b/i', $sql) === 1;
    }

    /**
     * Invalidate the cache for a mutation, deferring to the commit boundary
     * when inside a transaction so the purge can't be repopulated with
     * pre-commit data and is discarded if the transaction rolls back.
     *
     * Invalidations are aggregated per distinct table for the duration of the
     * transaction: a bulk import of 10k statements against one table schedules
     * a single commit-time invalidation, not 10k.
     *
     * @param string $query
     * @return void
     */
    private function scheduleInvalidation(string $query): void
    {
        if ($this->transactionLevel() === 0) {
            $this->invalidateCache($query);

            return;
        }

        // A full-cache purge already scheduled for this commit supersedes
        // anything more granular.
        if ($this->txPendingFlushAll) {
            return;
        }

        $tables = SqlTableExtractor::extract($query);

        if (empty($tables)) {
            try {
                $this->afterCommit(function () use ($query) {
                    $this->txPendingFlushAll = false;
                    $this->txPendingTables = [];
                    $this->cacheDriver->invalidateTables([], $query);
                });
            } catch (\RuntimeException $e) {
                // No transactions manager bound (e.g. a bare connection):
                // fall back to immediate invalidation.
                $this->invalidateCache($query);

                return;
            }

            $this->txPendingFlushAll = true;

            return;
        }

        $newTables = array_values(array_filter(
            $tables,
            fn ($table) => !isset($this->txPendingTables[$table])
        ));

        if (empty($newTables)) {
            return; // every affected table already has an invalidation scheduled
        }

        try {
            $this->afterCommit(function () use ($newTables, $query) {
                foreach ($newTables as $table) {
                    unset($this->txPendingTables[$table]);
                }
                $this->cacheDriver->invalidateTables($newTables, $query);
            });
        } catch (\RuntimeException $e) {
            $this->invalidateCache($query);

            return;
        }

        foreach ($newTables as $table) {
            $this->txPendingTables[$table] = true;
        }
    }

    /**
     * Roll back the active transaction, then drop the pending-invalidation
     * bookkeeping. The transactions manager discards afterCommit callbacks
     * registered inside the rolled-back scope; without this reset a later
     * mutation on the same table would be skipped as "already scheduled" and
     * its invalidation lost. Worst case after the reset is a duplicate
     * invalidation — never a missed one.
     *
     * @param  int|null  $toLevel
     * @return void
     */
    public function rollBack($toLevel = null)
    {
        try {
            parent::rollBack($toLevel);
        } finally {
            $this->txPendingTables = [];
            $this->txPendingFlushAll = false;
        }
    }

    /**
     * Check if query targets system/metadata catalogs that should never be
     * cached (they are not tracked for invalidation).
     *
     * @param string $sql
     * @return bool
     */
    private function isSchemaQuery(string $sql): bool
    {
        return preg_match(
            '/\b(information_schema|performance_schema|pg_catalog|pg_class|pg_attribute|pg_namespace|pg_index|sqlite_master|sqlite_schema|sqlite_temp_master)\b|\b(?:mysql|sys)\s*\.\s*\w/i',
            $sql
        ) === 1;
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
        $this->tenantContextSet = true;
    }

    /**
     * Check if caching is enabled
     *
     * @return bool
     */
    private function isCachingEnabled(): bool
    {
        if (!($this->config['db_cache']['enabled'] ?? false)) {
            return false;
        }

        // Fail-safe multi-tenant guard: when tenant_required is on, bypass
        // caching entirely until setTenantContext() has namespaced the keys,
        // so a forgotten context degrades to "no caching" rather than leaking
        // one tenant's cached rows to another.
        if (($this->config['db_cache']['tenant_required'] ?? false) && !$this->tenantContextSet) {
            return false;
        }

        return true;
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
                'bindings' => $this->redactBindings($bindings)
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
                'bindings' => $this->redactBindings($bindings)
            ]);
        }
    }

    /**
     * Redact binding values before logging. Raw bindings routinely contain PII,
     * tokens or password hashes; log only the count and types for debugging.
     *
     * @param array $bindings
     * @return array
     */
    private function redactBindings(array $bindings): array
    {
        return [
            'count' => count($bindings),
            'types' => array_map(fn ($b) => get_debug_type($b), array_values($bindings)),
        ];
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

        // Tenant context must not leak across request/job boundaries in
        // long-running runtimes (Octane, queue workers): without this reset
        // the tenant_required fail-safe only protects a worker's first
        // request, and a forgotten setTenantContext() on a later request
        // would silently read the previous tenant's cache.
        $this->tenantContextSet = false;
    }

    /**
     * Reconcile the cache's bookkeeping with expired entries (Redis driver:
     * remove tracking-set/index members whose data hash has expired).
     *
     * @return int Number of stale references removed
     */
    public function pruneQueryCache(): int
    {
        return $this->cacheDriver->pruneExpired();
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
