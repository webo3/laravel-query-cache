# Laravel Query Cache

Transparent query-level database caching for Laravel with smart table-based invalidation.

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
- **Cursor queries bypassed** - `cursor()` queries are never cached (preserving memory-efficient streaming)
- **Monitoring middleware** included for logging cache statistics

## Requirements

- PHP 8.1+
- Laravel 9, 10, 11, or 12
- MySQL, PostgreSQL, or SQLite database connection
- Redis (optional, for the `redis` driver)

## Installation

```bash
composer require webo3/laravel-query-cache
```

The service provider is auto-discovered. No manual registration needed.

### Publish the configuration

```bash
php artisan vendor:publish --tag=query-cache-config
```

This creates `config/query-cache.php` in your application.

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
| `DB_QUERY_CACHE_REDIS_CONNECTION` | `query_cache` | Redis connection name (redis driver only) |

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
DB_QUERY_CACHE_REDIS_CONNECTION=query_cache
```

#### Redis Connection Setup

Add a dedicated Redis connection in your `config/database.php`:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'predis'),

    // ... your other connections ...

    'query_cache' => [
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

For remote Redis connections that require TLS (e.g. AWS ElastiCache, Valkey), add `scheme` and `context` options to your `query_cache` connection:

```php
'query_cache' => [
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
    $middleware->append(\webO3\LaravelQueryCache\Middleware\QueryCacheStatsMiddleware::class);
})
```

In Laravel 9/10 (`app/Http/Kernel.php`):

```php
protected $middleware = [
    // ...
    \webO3\LaravelQueryCache\Middleware\QueryCacheStatsMiddleware::class,
];
```

The middleware only logs when `DB_QUERY_CACHE_LOG_ENABLED=true`. Log entries include the driver, URL, HTTP method, cached query count, total hits, and hit rate.

## Multi-Connection Support

You can enable query caching on multiple database connections simultaneously. In your `config/query-cache.php` (or via environment), pass an array of connection names:

```php
// config/query-cache.php
'connection' => ['mysql', 'pgsql'],
```

Each connection will use the same cache driver and TTL settings. The factory automatically creates the appropriate cached connection class based on the driver (`mysql`, `pgsql`, or `sqlite`).

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
use webO3\LaravelQueryCache\Contracts\CachedConnection;

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

## Custom Cache Drivers

You can create your own cache driver by implementing the `QueryCacheDriver` interface:

```php
use webO3\LaravelQueryCache\Contracts\QueryCacheDriver;

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
