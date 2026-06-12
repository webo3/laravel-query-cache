# Configuration

Publish the config file to customize defaults:

```bash
php artisan vendor:publish --tag=db-cache-config
```

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `DB_QUERY_CACHE_ENABLED` | `false` | Enable/disable query caching |
| `DB_QUERY_CACHE_DRIVER` | `array` | Cache driver: `array`, `redis`, or `null` |
| `DB_QUERY_CACHE_TTL` | `180` | Cache time-to-live in seconds |
| `DB_QUERY_CACHE_MAX_SIZE` | `1000` | Max cached queries (array driver only) |
| `DB_QUERY_CACHE_MAX_RESULT_BYTES` | `1048576` | Serialized results larger than this are not written to Redis (`0` disables the guard) |
| `DB_QUERY_CACHE_LOG_ENABLED` | `false` | Enable cache hit/miss/invalidation logging |
| `DB_QUERY_CACHE_CONNECTION` | `mysql` | Database connection name(s) to cache |
| `DB_QUERY_CACHE_REDIS_CONNECTION` | `db_cache` | Redis connection name (redis driver only) |
| `DB_QUERY_CACHE_EXCLUDED_TABLES` | _(empty)_ | Comma-separated identifiers (views, etc.) that must never be cached |
| `DB_QUERY_CACHE_TENANT_REQUIRED` | `false` | Bypass caching until `setTenantContext()` is set (multi-tenant fail-safe) |

## Drivers

### Array driver

In-memory cache that lives for the duration of a single HTTP request. No external dependencies.

- Best for: development, testing, detecting duplicate queries within a request
- Cache is **not shared** between requests or workers
- Entries past `ttl` expire on read
- LRU eviction when `max_size` is reached (evicts the oldest 10%)

```env
DB_QUERY_CACHE_DRIVER=array
DB_QUERY_CACHE_MAX_SIZE=1000
```

### Redis driver

Persistent cache shared across all workers and requests, with a two-tier architecture: a per-request L1 in-memory cache in front of the shared L2 Redis store. Setup, TLS, client choice and operational notes are covered in [redis.md](redis.md).

```env
DB_QUERY_CACHE_DRIVER=redis
DB_QUERY_CACHE_TTL=180
DB_QUERY_CACHE_REDIS_CONNECTION=db_cache
```

### Null driver

Disables caching entirely. Useful for debugging or disabling caching in specific environments without removing the package.

```env
DB_QUERY_CACHE_DRIVER=null
```

## Multiple connections

Enable caching on several connections with a comma-separated string in `.env`:

```env
DB_QUERY_CACHE_CONNECTION=main,org
```

Or an array in `config/db-cache.php`:

```php
'connection' => ['main', 'org'],
```

Each connection uses the same driver and TTL settings. The factory creates the appropriate cached connection class based on the database driver (`mysql`, `pgsql`, or `sqlite`). Cache keys are namespaced by connection name, so identical SQL on different connections never collides.

## Excluding views and other identifiers

The cache invalidator works on the table names parsed out of each query. For a SQL **view**, that name *is* the view — not the underlying tables — so a mutation to a base table cannot be matched against the cached view query and would serve stale data until the TTL expires.

Exclude any identifier that should never be cached:

```env
DB_QUERY_CACHE_EXCLUDED_TABLES=user_summary,order_totals,reporting_view
```

Or in `config/db-cache.php`:

```php
'excluded_tables' => ['user_summary', 'order_totals', 'reporting_view'],
```

Any `SELECT` referencing one of these identifiers (case-insensitive, bare name only — no schema qualifier) bypasses the cache entirely. You can also set the list at runtime per-connection:

```php
DB::connection('mysql')->setExcludedTables(['user_summary']);
```

## Long-running workers (Horizon, Octane, FrankenPHP)

In long-running PHP processes — queue workers under Horizon, requests served by Laravel Octane (Swoole, RoadRunner, FrankenPHP) — the same PHP process handles many requests/jobs without restart. The L1 in-memory cache would normally outlive its intended TTL, and a tenant context set for one request would leak into the next.

The package automatically hooks Laravel's lifecycle to reset per-request state (L1 cache, tenant context, stats counters) at the correct boundaries:

| Runtime | Hook |
|---|---|
| HTTP (FPM/Octane) | container `terminating()` callback (runs after terminable middleware) |
| Queue worker / Horizon | `JobProcessed`, `JobFailed`, `JobExceptionOccurred` |
| Octane (Swoole/RoadRunner/FrankenPHP) | `RequestTerminated`, `TaskTerminated`, `TickTerminated` |

Only per-process state is dropped — the shared L2 Redis cache survives, keeping the cache useful across the worker fleet. No manual setup is required; the hooks register automatically when `DB_QUERY_CACHE_ENABLED=true`.

To trigger it manually (e.g. in a custom daemon loop):

```php
DB::connection('mysql')->flushRequestCache();
```
