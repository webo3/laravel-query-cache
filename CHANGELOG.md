# Changelog

All notable changes to this project will be documented in this file.

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
