# Changelog

All notable changes to this project will be documented in this file.

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
