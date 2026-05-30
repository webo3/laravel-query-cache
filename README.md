# Laravel DB Cache

Transparent database query caching for Laravel — zero code changes, smart invalidation, multi-driver.

This package intercepts SQL queries at the connection level, automatically caching `SELECT` results and invalidating them when mutations (`INSERT`, `UPDATE`, `DELETE`, etc.) affect related tables. No changes to your application code required.

## Features

- **Zero-config caching** - Works transparently at the database connection level
- **Smart invalidation** - Automatically invalidates cached queries when related tables are mutated
- **Three cache drivers** - Array (per-request), Redis (persistent with two-tier L1/L2), Null (no-op)
- **Query normalization** - Case-insensitive, whitespace-normalized keys for better hit rates
- **Redis driver highlights**:
  - Two-tier architecture (L1 in-memory + L2 Redis)
  - Redis Hash structures with pipelining for batch operations
  - O(1) table-based invalidation via inverted indexes
  - AWS ElastiCache / Valkey compatible (uses Sets instead of KEYS/SCAN)
  - Automatic igbinary serialization and gzip compression
- **LRU eviction** for the array driver when max size is reached
- **Multi-tenant support** - `setTenantContext()` namespaces cache keys per tenant, preventing cross-tenant data leakage
- **Cursor queries bypassed** - `cursor()` queries are never cached (preserving memory-efficient streaming)
- **Monitoring middleware** included for logging cache statistics

## Requirements

- PHP 8.1+
- Laravel 9, 10, 11, or 12
- MySQL, PostgreSQL, or SQLite database connection
- Redis (optional, for the `redis` driver)

## Installation

```bash
composer require webo3/laravel-db-cache
```

The service provider is auto-discovered. No manual registration needed.

### Publish the configuration

```bash
php artisan vendor:publish --tag=db-cache-config
```

This creates `config/db-cache.php` in your application.

## Configuration

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DB_QUERY_CACHE_ENABLED` | `false` | Enable/disable query caching |
| `DB_QUERY_CACHE_DRIVER` | `array` | Cache driver: `array`, `redis`, or `null` |
| `DB_QUERY_CACHE_TTL` | `180` | Cache time-to-live in seconds |
| `DB_QUERY_CACHE_MAX_SIZE` | `1000` | Max cached queries (array driver only) |
| `DB_QUERY_CACHE_LOG_ENABLED` | `false` | Enable cache hit/miss/invalidation logging |
| `DB_QUERY_CACHE_CONNECTION` | `mysql` | Database connection name(s) to cache |
| `DB_QUERY_CACHE_REDIS_CONNECTION` | `db_cache` | Redis connection name (redis driver only) |
| `DB_QUERY_CACHE_EXCLUDED_TABLES` | _(empty)_ | Comma-separated identifiers (views, etc.) that must never be cached |
| `DB_QUERY_CACHE_TENANT_REQUIRED` | `false` | Bypass caching until `setTenantContext()` is set (multi-tenant fail-safe) |

### Quick Start

Add to your `.env`:

```env
DB_QUERY_CACHE_ENABLED=true
DB_QUERY_CACHE_DRIVER=array
```

## Drivers

### Array Driver

In-memory cache that lives for the duration of a single HTTP request. No external dependencies.

- Best for: development, testing, detecting duplicate queries within a request
- Cache is **not shared** between requests or workers
- Includes LRU eviction when `max_size` is reached (evicts the oldest 10%)

```env
DB_QUERY_CACHE_DRIVER=array
DB_QUERY_CACHE_MAX_SIZE=1000
```

### Redis Driver

Persistent cache shared across all workers and requests. Uses a two-tier architecture:

- **L1 (in-memory)**: Per-request cache to avoid repeated Redis calls for the same query
- **L2 (Redis)**: Persistent shared cache using Redis Hash structures

```env
DB_QUERY_CACHE_DRIVER=redis
DB_QUERY_CACHE_TTL=180
DB_QUERY_CACHE_REDIS_CONNECTION=db_cache
```

#### Redis Connection Setup

Add a dedicated Redis connection in your `config/database.php`:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'predis'),

    // ... your other connections ...

    'db_cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_QUERY_CACHE_DB', '2'),
        'timeout' => 2.0,
        'read_timeout' => 2.0,
    ],
],
```

Using a dedicated database (e.g. `2`) keeps query cache data isolated from your application cache.

#### TLS/SSL (AWS ElastiCache, Valkey)

For remote Redis connections that require TLS (e.g. AWS ElastiCache, Valkey), add `scheme` and `context` options to your `db_cache` connection:

```php
'db_cache' => [
    'scheme' => env('REDIS_SCHEME', 'tcp'), // Use 'tls' for SSL connections
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_QUERY_CACHE_DB', '2'),
    'timeout' => 2.0,
    'read_timeout' => 2.0,
    ...((env('REDIS_SCHEME') === 'tls') ? [
        'context' => [
            'stream' => [
                'verify_peer' => env('REDIS_SSL_VERIFY_PEER', true),
                'verify_peer_name' => env('REDIS_SSL_VERIFY_PEER_NAME', true),
            ],
        ],
    ] : []),
],
```

Then in your `.env`:

```env
REDIS_SCHEME=tls
REDIS_HOST=your-cluster.xxxxx.cache.amazonaws.com
REDIS_PORT=6380
```

#### Redis Client

The package works with both `predis` and `phpredis`:

```bash
# Predis (pure PHP)
composer require predis/predis

# Or use phpredis (C extension, faster)
# Install via pecl: pecl install redis
```

#### Optional: igbinary for faster serialization

```bash
pecl install igbinary
```

When available, the Redis driver automatically uses igbinary for serialization and applies gzip compression for results larger than 10KB.

### Null Driver

Disables caching entirely. Useful for debugging or disabling caching in specific environments without removing the package.

```env
DB_QUERY_CACHE_DRIVER=null
```

## Monitoring Middleware

The package includes a middleware that logs cache statistics at the end of each request.

### Register the middleware

In Laravel 11+ (`bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\webO3\LaravelDbCache\Middleware\QueryCacheStatsMiddleware::class);
})
```

In Laravel 9/10 (`app/Http/Kernel.php`):

```php
protected $middleware = [
    // ...
    \webO3\LaravelDbCache\Middleware\QueryCacheStatsMiddleware::class,
];
```

The middleware only logs when `DB_QUERY_CACHE_LOG_ENABLED=true`. Log entries include the driver, URL, HTTP method, cached query count, total hits, and hit rate.

## Excluding Views and Other Identifiers

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

## Long-Running Workers (Horizon, Octane, FrankenPHP)

In long-running PHP processes — queue workers under Horizon, requests served by Laravel Octane (Swoole, RoadRunner, FrankenPHP) — the same PHP process handles many requests/jobs without restart. The L1 in-memory cache would normally outlive its intended TTL.

The package automatically hooks Laravel's lifecycle events to drop L1 at the correct boundaries:

| Runtime | Event |
|---|---|
| HTTP (FPM/Octane) | `RequestHandled` |
| Queue worker / Horizon | `JobProcessed`, `JobFailed`, `JobExceptionOccurred` |
| Octane (Swoole/RoadRunner/FrankenPHP) | `RequestTerminated`, `TaskTerminated`, `TickTerminated` |

Only L1 (per-process) state is dropped — the shared L2 Redis cache survives, keeping the cache useful across the worker fleet. No manual setup is required; the listeners register automatically when `DB_QUERY_CACHE_ENABLED=true`.

If you need to trigger this manually:

```php
DB::connection('mysql')->flushRequestCache();
```

## Multi-Connection Support

You can enable query caching on multiple database connections simultaneously. Use a comma-separated string in your `.env`:

```env
DB_QUERY_CACHE_CONNECTION=main,org
```

Or use an array in `config/db-cache.php`:

```php
'connection' => ['main', 'org'],
```

Each connection will use the same cache driver and TTL settings. The factory automatically creates the appropriate cached connection class based on the driver (`mysql`, `pgsql`, or `sqlite`).

## Multi-Tenant Support

For multi-tenant applications where multiple tenants share the same database connection, the package provides tenant-aware cache isolation via `setTenantContext()`. This namespaces all cache keys by tenant ID, preventing cross-tenant data leakage.

### Usage

Call `setTenantContext()` on the connection after resolving the tenant — typically in your tenant database resolver or middleware:

```php
$connection = DB::connection('org');

if (method_exists($connection, 'setTenantContext')) {
    $connection->setTenantContext((string) $org->id);
}
```

Once set, all cache operations on that connection are scoped to the tenant:

- **Cache keys** are prefixed with the tenant ID (e.g. `app_database_cache:t:42:abc123`)
- **Tracking sets** are tenant-scoped (e.g. `db_cache:t:42:keys`)
- **Table indexes** are tenant-scoped (e.g. `db_cache:t:42:table:users`)
- **Cache invalidation** only affects the current tenant's cached queries

### How it works per driver

| Driver | Behavior |
|---|---|
| **Redis** | Keys, tracking sets, and table indexes are namespaced by tenant. L1 (in-memory) cache is flushed on tenant switch. Each tenant's data is fully isolated in Redis. |
| **Array** | Cache is flushed when switching between tenants (since the static array is shared). Within a single request serving one tenant, caching works normally. |
| **Null** | No-op (accepts the call, does nothing). |

### Connections without tenant context

Connections that don't call `setTenantContext()` (e.g. a shared `main` connection) work exactly as before — no tenant prefix is applied. This allows you to cache both shared and tenant-specific connections simultaneously:

```env
DB_QUERY_CACHE_CONNECTION=main,org
```

The `main` connection caches globally, while the `org` connection caches per-tenant after `setTenantContext()` is called.

> **Fail-safe for multi-tenant connections.** If a tenant-spanning connection forgets to call `setTenantContext()`, every tenant would share one un-namespaced cache — a cross-tenant leak. Set `DB_QUERY_CACHE_TENANT_REQUIRED=true` (or `'tenant_required' => true` in `config/db-cache.php`) to make this fail safe: caching is fully bypassed (no reads, writes, or invalidation) until a tenant context has been set, so a missing `setTenantContext()` degrades to "no caching" instead of leaking. Leave it `false` for single-tenant apps.

## Artisan Command

Clear the query cache from the command line:

```bash
# Clear all cached connections
php artisan db-cache:clear

# Clear a specific connection
php artisan db-cache:clear --connection=org

# Clear a specific tenant's cache
php artisan db-cache:clear --connection=org --tenant=42

# Clear multiple connections (comma-separated)
php artisan db-cache:clear --connection=main,org
```

| Option | Description |
|---|---|
| `--connection` | Connection name(s) to clear. Defaults to all connections listed in `DB_QUERY_CACHE_CONNECTION`. |
| `--tenant` | Tenant ID to scope the clear to. Sets the tenant context before flushing, so only that tenant's cache keys are removed (redis driver). |

## Programmatic API

Any cached connection (MySQL, PostgreSQL, or SQLite) exposes these methods via the `DB` facade:

```php
use Illuminate\Support\Facades\DB;

// Clear all cached queries
DB::connection('mysql')->clearQueryCache();

// Get cache statistics
$stats = DB::connection('pgsql')->getCacheStats();
// Returns: [
//     'driver' => 'redis',
//     'cached_queries_count' => 42,
//     'total_cache_hits' => 128,
//     'queries' => [...],
// ]

// Temporarily disable caching
DB::connection('mysql')->disableQueryCache();

// Re-enable caching
DB::connection('mysql')->enableQueryCache();
```

You can also use the `CachedConnection` interface for type checking:

```php
use webO3\LaravelDbCache\Contracts\CachedConnection;

$connection = DB::connection();
if ($connection instanceof CachedConnection) {
    $stats = $connection->getCacheStats();
}
```

## How It Works

1. **SELECT queries** are intercepted at the connection level. The query + bindings are normalized and hashed to produce a cache key. If a cached result exists, it's returned immediately without hitting the database.

2. **Mutation queries** (`INSERT`, `UPDATE`, `DELETE`, `TRUNCATE`, `ALTER`, `DROP`, `CREATE`, `REPLACE`) trigger automatic invalidation. The package extracts table names from the SQL and invalidates all cached queries that reference those tables.

3. **Table extraction** uses regex-based SQL parsing to identify which tables a query reads from or writes to. This supports `FROM`, `JOIN`, `INTO`, `UPDATE`, `DELETE FROM`, subqueries, and more.

4. **Query normalization** ensures that queries with different casing or whitespace produce the same cache key (e.g. `SELECT * FROM users` and `select *  from  users` hit the same cache entry).

5. **Cursor queries** (`DB::cursor()`) are never cached, as they are designed for memory-efficient streaming of large result sets.

## Consistency & Caveats

### Eventual consistency

This is a read-through (cache-aside) cache, so reads are **eventually consistent**, not strongly consistent:

- A cached result can be at most **`ttl` seconds** stale. Keep `DB_QUERY_CACHE_TTL` short for write-heavy tables.
- **Transaction-aware:** `SELECT`s executed inside an open transaction are **not** cached (they could observe this connection's own uncommitted rows), and a write's invalidation is **deferred to commit** — it fires after `COMMIT` and is dropped on `ROLLBACK`.
- **Locking reads** (`SELECT … FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE`) are never cached, so they always take their lock against the database.
- Under concurrency, a narrow **lost-invalidation window** remains: if a reader reads the old row, a writer commits + invalidates, and only then does the reader write its (now-stale) entry, that entry lives until its TTL. There is **no single-flight lock** on a cache miss, so a very hot key can briefly stampede the database when it expires — TTL jitter (±10%, applied automatically) spreads synchronized expiries out.
- Queries the table extractor can't attribute to a table (e.g. `CALL` a stored procedure) conservatively **flush the entire cache** for that connection on mutation, which is safe but coarse.

If you require read-your-writes within a request, read through the same connection inside the transaction (bypassed) or call `DB::connection(...)->clearQueryCache()` after the critical write.

### Redis Cluster

The Redis driver's `put()` writes the data hash, the key-tracking set, and the per-table indexes in **one `MULTI/EXEC` transaction that spans multiple keys**. On **Redis Cluster** those keys can hash to different slots, which makes the transaction fail. Point the `db_cache` connection at a **single Redis node/instance** (the recommended setup — a dedicated database keeps it isolated; see [Redis Driver](#redis-driver)). Standalone Redis, AWS ElastiCache / Valkey in non-clustered mode, and a single primary are all fine; clustered mode is not currently supported.

## Custom Cache Drivers

You can create your own cache driver by implementing the `QueryCacheDriver` interface:

```php
use webO3\LaravelDbCache\Contracts\QueryCacheDriver;

class MyCustomDriver implements QueryCacheDriver
{
    public function get(string $key): ?array { /* ... */ }
    public function put(string $key, mixed $result, string $query, float $executedAt): void { /* ... */ }
    public function has(string $key): bool { /* ... */ }
    public function forget(string $key): void { /* ... */ }
    public function invalidateTables(array $tables, string $query): int { /* ... */ }
    public function flush(): void { /* ... */ }
    public function getStats(): array { /* ... */ }
    public function recordHit(string $key): void { /* ... */ }
    public function getAllKeys(): array { /* ... */ }
    public function setTenantContext(string $tenantId): void { /* ... */ }
}
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

Tests require a MySQL database connection. Copy `.env.example` to `.env` and configure your database credentials. Redis tests are automatically skipped if Redis is unavailable. Unit tests for `SqlTableExtractor` run without any database.

## License

MIT
