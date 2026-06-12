# Usage & Monitoring

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
//     'request_hits' => 7,        // this request only
//     'request_misses' => 3,      // this request only
//     'queries' => [...],
// ]

// Temporarily disable caching
DB::connection('mysql')->disableQueryCache();

// Re-enable caching
DB::connection('mysql')->enableQueryCache();

// Reset per-request state (L1 cache, tenant context, counters)
DB::connection('mysql')->flushRequestCache();

// Remove stale tracking/index references (redis driver)
DB::connection('mysql')->pruneQueryCache();
```

Use the `CachedConnection` interface for type checking:

```php
use webO3\LaravelDbCache\Contracts\CachedConnection;

$connection = DB::connection();
if ($connection instanceof CachedConnection) {
    $stats = $connection->getCacheStats();
}
```

## Stats middleware

The package includes a middleware that logs cache statistics for each request. Stats are collected in `terminate()`, after the response has been sent, so the collection never adds request latency.

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

The middleware only logs when `DB_QUERY_CACHE_LOG_ENABLED=true`. Log entries include the driver, URL, HTTP method, cached query count, total hits, this request's hit/miss counts, and the per-request hit rate.

## Artisan commands

### `db-cache:clear`

```bash
# Clear all cached connections — clears the default namespace AND every
# tenant namespace in the tenant registry
php artisan db-cache:clear

# Clear a specific connection
php artisan db-cache:clear --connection=org

# Clear ONLY a specific tenant's cache
php artisan db-cache:clear --connection=org --tenant=42

# Clear multiple connections (comma-separated)
php artisan db-cache:clear --connection=main,org
```

| Option | Description |
|---|---|
| `--connection` | Connection name(s) to clear. Defaults to all connections listed in `DB_QUERY_CACHE_CONNECTION`. |
| `--tenant` | Tenant ID to scope the clear to. Sets the tenant context before flushing, so only that tenant's cache keys are removed (redis driver). Without it, all namespaces are cleared. |

### `db-cache:prune`

Removes tracking-set and table-index members whose data hash has expired (redis driver). See [redis.md](redis.md#housekeeping-db-cacheprune) for why and how to schedule it:

```bash
php artisan db-cache:prune
```

## Custom cache drivers

Create your own driver by implementing the `QueryCacheDriver` interface:

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
    public function flushRequestCache(): void { /* ... */ }
    public function pruneExpired(): int { /* ... */ }
}
```
