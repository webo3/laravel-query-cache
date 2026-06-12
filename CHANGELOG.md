# Changelog

All notable changes to this project will be documented in this file.

## [1.3.0]

### Security

- **Octane/queue-worker tenant isolation:** `flushRequestCache()` (wired to request/job boundaries) now resets the tenant context, so the `tenant_required` fail-safe holds on every request of a long-running worker — previously a request that forgot `setTenantContext()` silently read the previous request's tenant cache
- **HMAC-authenticated Redis payloads:** cached results are signed with `APP_KEY` (HMAC-SHA256) and authenticated *before* unserialization, closing the object-injection gap left by igbinary (which has no `allowed_classes` filter). Pre-HMAC entries decode as misses (one-time cold-cache wave on upgrade)
- Cache keys now use `sha256` instead of the non-collision-resistant `xxh128` — bindings are user-influenced, and a crafted collision on a shared cache could serve one query's rows to another
- Tenant IDs are validated (`[A-Za-z0-9._-]+`) before being embedded in Redis key namespaces; IDs containing `:` could overlap another tenant's namespace structure

### Fixed

- **Mutations executed while iterating `cursor()` now invalidate the cache** — the in-cursor flag is scoped to the cursor's own SELECT instead of the whole consuming loop (which also means unrelated SELECTs inside the loop are cached normally again)
- **`pretend()` no longer poisons the cache:** pretended SELECTs return `[]` without executing; caching that handed real callers an empty result until TTL
- **L1 cache is invalidated even when Redis is unreachable:** the request-level cache keeps its own inverted table index, so a same-request mutation purges matching L1 entries while the circuit breaker is open (previously they served stale rows)
- `forget()` reads the entry's table list *before* deleting the hash — the index cleanup silently no-oped and leaked index members
- `SELECT … INTO` (Postgres table creation, MySQL OUTFILE/@var) and nondeterministic SELECTs (`NOW()`, `RAND()`, `UUID()`, `nextval()`, `LAST_INSERT_ID()`, …) are never cached — a cache hit would skip the side effect or freeze the value
- `LOAD DATA` / `LOAD XML` (MySQL) and `COPY` (Postgres) are classified as mutations and their target tables extracted, so bulk loads invalidate
- Identifier case is no longer folded into one cache key (`FROM Users` vs `FROM users` are different tables on case-sensitive MySQL); whitespace normalization is unchanged
- The stats middleware parses comma-separated `DB_QUERY_CACHE_CONNECTION` lists (it previously threw on every request for multi-connection setups), collects stats in `terminate()` after the response is sent, and reports an honest per-request hit rate from new request hit/miss counters
- `db-cache:clear` without `--tenant` now clears every tenant namespace via a tenant registry set (previously tenant-namespaced entries silently survived)
- CTE aliases are filtered from extracted tables case-insensitively; quoted identifiers containing spaces/hyphens/dots are extracted correctly
- Invalidation failures are always logged (warning), not only when debug logging is enabled
- Array driver enforces `ttl` on read (it was silently ignored) and resets its tenant context at request boundaries

### Added

- `db-cache:prune` command (+ `pruneQueryCache()` / `pruneExpired()` API) to reconcile tracking sets and table indexes with expired entries — schedule it hourly on busy apps
- Tracking/index sets carry their own TTL (2× data TTL, refreshed on put) so read-mostly tables can't accumulate dead references forever
- `max_result_bytes` (`DB_QUERY_CACHE_MAX_RESULT_BYTES`, default 1 MiB): oversized results are served from L1 but never written to Redis
- All tracking/index set keys now carry the same app/cache prefix as data keys, so two apps sharing one Redis database can no longer destroy each other's indexes

### Changed

- The Redis connection is resolved lazily on first use — a missing/unreachable Redis config no longer throws while the database connection is being constructed (it degrades to "no caching" via the circuit breaker)
- The circuit breaker now covers every Redis code path (invalidation and maintenance included) with client-agnostic `\Throwable` handling; large pipelines are chunked (500 commands)
- With stats logging enabled, `recordHit()` updates the L1 entry in place instead of evicting it (evicting forced every repeat query back to Redis, doubling traffic in logging mode)
- Invalidations inside a transaction are aggregated per distinct table — a 10k-statement bulk import schedules one commit-time invalidation per table, not one per statement; pending state is reset on rollback (worst case: a duplicate invalidation, never a missed one)
- The per-request L1 flush for HTTP runs via the container's `terminating()` callback (after terminable middleware) instead of `RequestHandled`
- **Upgrade notes:** the key-hash (sha256), HMAC payload format, and prefixed set names invalidate existing Redis entries and orphan old (unprefixed) tracking sets on deploy — a one-time cold-cache wave; delete old `db_cache:*` sets manually if you want the memory back immediately. `QueryCacheDriver` gained `pruneExpired(): int` and `CachedConnection` gained `pruneQueryCache(): int` (implement them if you have a custom driver). `setTenantContext()` now throws `InvalidArgumentException` for IDs with characters outside `[A-Za-z0-9._-]`.

## [1.2.0]

### Security

- Multi-tenant fail-safe `tenant_required` (`DB_QUERY_CACHE_TENANT_REQUIRED`, default `false`): while no tenant context has been set, caching is fully bypassed (no reads, writes, or invalidation), so a forgotten `setTenantContext()` degrades to "no caching" instead of leaking one tenant's cache to another
- Cache keys are now namespaced by connection name — identical SQL + bindings on different connections/databases can no longer collide and return each other's rows
- Redis payloads are decoded strictly by an explicit format marker and unserialized with `allowed_classes => ['stdClass']` (object-injection hardening); binding **values** are redacted from debug logs (only count and types are logged)

### Fixed

- Transaction-aware caching: `SELECT`s executed inside an open transaction are no longer cached (they could observe uncommitted rows that a rollback discards); mutation invalidation is deferred to the commit boundary and dropped on rollback
- Locking reads (`SELECT … FOR UPDATE` / `FOR SHARE` / `LOCK IN SHARE MODE`) are never cached, so they always acquire their lock against the database
- Mutation detection now handles comment-prefixed statements (`/* … */ UPDATE …`), data-modifying / top-level-DML CTEs (`WITH … UPDATE/DELETE/INSERT`), and `CALL` / `EXEC` / `MERGE`
- Binary / non-UTF-8 bindings no longer collide to one key (binding material now uses `serialize()` instead of `json_encode()`, which returned `false` for invalid UTF-8); query normalization no longer conflates string-literal case or internal whitespace
- `SqlTableExtractor` no longer drops tables in comma-joined `FROM` lists whose first comma falls in the SELECT projection; extracts `MERGE INTO` targets
- Redis: `put()` writes the data hash, tracking set, and table indexes in one `MULTI/EXEC` (no live-but-unindexed keys); invalidation removes a key from **all** of its table indexes, not just the invalidated one; tracking-set members whose hash has expired are reconciled (pruned) on `getStats()`
- A resurrected, result-less hash (from `HINCRBY` on a just-expired key) is treated as a cache miss; hit recording is skipped entirely when stats logging is disabled, so it can't create such zombies

### Changed

- Redis driver: client-agnostic circuit breaker on connection/timeout errors (skips Redis for a short window to avoid a DB stampede and per-query log flooding), ±10% TTL jitter to spread synchronized expiries, metadata-only `getStats()` (no result-blob transfer), key prefix computed once, pipelined `recordHit`
- Broadened system-catalog detection beyond `information_schema` (`pg_catalog`, `sqlite_master`/`sqlite_schema`, `mysql.*`, `performance_schema`, `sys.*`)
- Removed the unreachable `md5` cache-key fallback
- **Upgrade notes:** the cache-key and serializer-marker changes invalidate existing Redis entries on deploy — a one-time cold-cache wave (safe). The Redis driver targets a single Redis node; Redis Cluster is not supported (the `put()` transaction spans multiple keys/slots).

## [1.1.5]

### Fixed

- Redis driver tracks every indexed table in a dedicated Set so the cache can be flushed without `SCAN`, and fixes a double-prefixing bug that prevented table-index keys from being deleted

## [1.1.4]

### Added

- `excluded_tables` configuration (`DB_QUERY_CACHE_EXCLUDED_TABLES`) to never cache `SELECT`s touching given identifiers (typically views, whose base-table mutations can't be detected)
- `flushRequestCache()` plus automatic lifecycle listeners that drop the per-request L1 cache at request / queue-job / Octane boundaries, so long-running workers (Horizon, Octane, FrankenPHP) don't serve L1 entries past their TTL

### Fixed

- Cache entries are written atomically via `MULTI/EXEC` so the hash and its `EXPIRE` always land together (no more TTL-less, permanently-persistent keys)
- TTL is no longer refreshed on cache hits, so frequently-read keys can't live indefinitely and serve stale data

## [1.1.3]

### Added

- Laravel 13 compatibility

## [1.1.2]

### Fixed

- `SELECT`s against `information_schema` (and other metadata queries) are no longer cached

## [1.1.1]

### Changed

- `RENAME TABLE` is now recognised as a mutation and invalidates both the source and target tables

## [1.1.0]

### Added

- Multi-tenant cache isolation via `setTenantContext()` — namespaces cache keys, tracking sets, and table indexes by tenant to prevent cross-tenant data leakage
- `db-cache:clear` Artisan command to clear cached connections (optionally scoped to a connection and/or tenant)

## [1.0.1]

### Added

- **PHPBench benchmark suite** (`benchmarks/`) for measuring cache key generation, table extraction, invalidation, eviction, and full request simulation overhead. Run with `composer bench`
- **Inverted table index** on the Array driver for O(k) invalidation (where k = affected entries) instead of O(n) full-cache scan
- **Per-request normalization cache** in `CachesQueries` to avoid redundant `strtoupper`/`preg_replace` on repeated query patterns
- **Per-request result cache** in `SqlTableExtractor` to avoid redundant regex parsing of the same SQL string

### Changed

- Cache key hashing switched from `md5()` to `xxh128` (via `hash()`, PHP 8.1+) for ~2x faster key generation
- Array driver now extracts tables eagerly on `put()` to support the inverted index (negligible +125ns per put)

## [1.0.0]

### Added

- Initial release
- Transparent database query caching at the connection level
- Smart table-based cache invalidation on mutations (INSERT, UPDATE, DELETE, TRUNCATE, ALTER, DROP, CREATE, REPLACE)
- Three cache drivers: Array (per-request), Redis (persistent L1/L2), Null (no-op)
- Query normalization (case-insensitive, whitespace-normalized) for consistent cache keys
- Redis driver with two-tier architecture (L1 in-memory + L2 Redis Hash)
- Redis inverted table indexes for O(1) invalidation
- AWS ElastiCache / Valkey compatibility (Sets instead of KEYS/SCAN)
- Automatic igbinary serialization and gzip compression (Redis driver)
- LRU eviction for the Array driver
- Cursor query bypass (never cached)
- Monitoring middleware for per-request cache statistics logging
- MySQL, PostgreSQL, and SQLite support
- Multi-connection support
- Programmatic API (clearQueryCache, getCacheStats, enableQueryCache, disableQueryCache)
- Comprehensive test suite (200+ tests)
