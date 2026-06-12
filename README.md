# Laravel DB Cache

Transparent database query caching for Laravel — zero code changes, smart invalidation, multi-driver.

The package intercepts queries at the connection level: `SELECT` results are cached, and any mutation (`INSERT`, `UPDATE`, `DELETE`, …) automatically invalidates the cached queries that reference the same tables. Your application code doesn't change.

## Features

- **Zero-config caching** — works transparently at the database connection level
- **Smart invalidation** — mutations invalidate exactly the cached queries that touch the affected tables
- **Three drivers** — `array` (per-request), `redis` (persistent, two-tier L1/L2), `null` (no-op)
- **Multi-tenant isolation** — `setTenantContext()` namespaces cache keys per tenant, with an opt-in fail-safe
- **Safe by default** — never caches transactions, locking reads, nondeterministic queries (`NOW()`, `RAND()`, `nextval()`, …) or cursors; Redis payloads are HMAC-signed with `APP_KEY`
- **Production-ready Redis driver** — circuit breaker on outages, TTL jitter, O(1) table-based invalidation, AWS ElastiCache / Valkey compatible

## Requirements

PHP 8.1+ · Laravel 9–12 · MySQL, PostgreSQL or SQLite · Redis (optional, for the `redis` driver)

## Installation

```bash
composer require webo3/laravel-db-cache
```

The service provider is auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=db-cache-config
```

## Quick start

Add to your `.env`:

```env
DB_QUERY_CACHE_ENABLED=true
DB_QUERY_CACHE_DRIVER=array
```

That's it — `SELECT`s on your default `mysql` connection are now cached for the duration of each request. For a persistent cache shared across workers, switch to the [Redis driver](https://github.com/webo3/laravel-db-cache/blob/main/docs/redis.md).

## Configuration

| Variable | Default | Description |
|---|---|---|
| `DB_QUERY_CACHE_ENABLED` | `false` | Enable/disable query caching |
| `DB_QUERY_CACHE_DRIVER` | `array` | Cache driver: `array`, `redis`, or `null` |
| `DB_QUERY_CACHE_TTL` | `180` | Cache time-to-live in seconds |
| `DB_QUERY_CACHE_CONNECTION` | `mysql` | Connection name(s) to cache (comma-separated for several) |
| `DB_QUERY_CACHE_EXCLUDED_TABLES` | _(empty)_ | Identifiers (views, etc.) that must never be cached |
| `DB_QUERY_CACHE_LOG_ENABLED` | `false` | Cache hit/miss/invalidation logging + stats middleware |
| `DB_QUERY_CACHE_TENANT_REQUIRED` | `false` | Bypass caching until `setTenantContext()` is called (multi-tenant fail-safe) |

The full reference (Redis connection, size limits, runtime API) lives in [docs/configuration.md](https://github.com/webo3/laravel-db-cache/blob/main/docs/configuration.md).

## Artisan commands

```bash
php artisan db-cache:clear   # clear the cache (all tenant namespaces)
php artisan db-cache:prune   # remove stale index references (redis); schedule hourly
```

## Good to know

- Reads are **eventually consistent**: a cached result can be at most `ttl` seconds stale. Keep the TTL short for write-heavy tables.
- The cache is **transaction-aware**: `SELECT`s inside a transaction are never cached, and invalidation defers to `COMMIT` (dropped on `ROLLBACK`).
- SQL **views can't be auto-invalidated** — list them in `DB_QUERY_CACHE_EXCLUDED_TABLES`.
- **Redis Cluster is not supported**; point the driver at a single Redis node/database.

Details in [docs/how-it-works.md](https://github.com/webo3/laravel-db-cache/blob/main/docs/how-it-works.md).

## Documentation

| Guide | Contents |
|---|---|
| [Configuration](https://github.com/webo3/laravel-db-cache/blob/main/docs/configuration.md) | Full config reference, drivers, multiple connections, excluded views, long-running workers (Octane/Horizon) |
| [Redis driver](https://github.com/webo3/laravel-db-cache/blob/main/docs/redis.md) | Connection setup, TLS/ElastiCache, predis vs phpredis, igbinary, pruning, cluster caveat |
| [Multi-tenancy](https://github.com/webo3/laravel-db-cache/blob/main/docs/multi-tenancy.md) | Tenant-scoped caching, per-driver behavior, the `tenant_required` fail-safe |
| [Usage & monitoring](https://github.com/webo3/laravel-db-cache/blob/main/docs/usage.md) | Programmatic API, stats middleware, artisan commands, custom drivers |
| [How it works](https://github.com/webo3/laravel-db-cache/blob/main/docs/how-it-works.md) | Caching pipeline, query normalization, consistency model and caveats |

## Testing

```bash
composer install
vendor/bin/phpunit
```

Tests need a MySQL connection (copy `.env.example` to `.env`); Redis tests skip automatically when Redis is unavailable.

## License

MIT
