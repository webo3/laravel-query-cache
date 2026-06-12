<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Query Cache
    |--------------------------------------------------------------------------
    |
    | When enabled, SELECT queries are automatically cached and invalidated
    | when mutations (INSERT, UPDATE, DELETE, etc.) affect related tables.
    |
    */

    'enabled' => env('DB_QUERY_CACHE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Cache Driver
    |--------------------------------------------------------------------------
    |
    | The driver used to store cached query results.
    |
    | Supported: "array", "redis", "null"
    |
    | - "array"  : In-memory cache (per-request only, no network overhead)
    | - "redis"  : Persistent cache with two-tier architecture (L1 in-memory + L2 Redis)
    | - "null"   : Disables caching (no-op driver)
    |
    */

    'driver' => env('DB_QUERY_CACHE_DRIVER', 'array'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Time-to-live for cached query results in seconds.
    | Keep short to minimize stale data risk.
    |
    */

    'ttl' => env('DB_QUERY_CACHE_TTL', 180),

    /*
    |--------------------------------------------------------------------------
    | Max Cache Size (array driver only)
    |--------------------------------------------------------------------------
    |
    | Maximum number of cached queries for the array driver.
    | When exceeded, the least recently used 10% of entries are evicted.
    |
    */

    'max_size' => env('DB_QUERY_CACHE_MAX_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Max Result Size (redis driver only)
    |--------------------------------------------------------------------------
    |
    | Serialized results larger than this many bytes are not written to Redis
    | (they are still served from the request-level L1 cache). Guards against
    | a single huge result set blowing Redis memory or adding multi-megabyte
    | writes to the request that missed. Set to 0 to disable the guard.
    |
    */

    'max_result_bytes' => env('DB_QUERY_CACHE_MAX_RESULT_BYTES', 1048576),

    /*
    |--------------------------------------------------------------------------
    | Enable Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, cache hits, misses, and invalidations are logged.
    | Useful for debugging and monitoring cache effectiveness.
    |
    */

    'log_enabled' => env('DB_QUERY_CACHE_LOG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Database Connection(s)
    |--------------------------------------------------------------------------
    |
    | The database connection name(s) to apply query caching to.
    | Supports MySQL, PostgreSQL, and SQLite drivers.
    |
    | Use a string for a single connection:
    |   'connection' => 'mysql',
    |
    | Use a comma-separated string for multiple connections (env-friendly):
    |   DB_QUERY_CACHE_CONNECTION=main,org
    |
    | Or use an array in PHP config:
    |   'connection' => ['main', 'org'],
    |
    */

    'connection' => env('DB_QUERY_CACHE_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection (redis driver only)
    |--------------------------------------------------------------------------
    |
    | The Redis connection name to use for the redis cache driver.
    | This should be configured in your config/database.php redis section.
    |
    */

    'redis_connection' => env('DB_QUERY_CACHE_REDIS_CONNECTION', 'db_cache'),

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables / Views
    |--------------------------------------------------------------------------
    |
    | Identifiers (views, materialized views, or tables) that must NEVER be
    | cached. A SELECT touching any of these names is executed and returned
    | without caching, because we can't reliably invalidate it: a view's
    | underlying tables are opaque to the SQL extractor, so updates to the
    | base tables would not invalidate the cached view result.
    |
    | Match is case-insensitive on the bare identifier (no schema qualifier).
    |
    | Configure via env as a comma-separated list:
    |   DB_QUERY_CACHE_EXCLUDED_TABLES=user_summary,order_totals
    |
    | Or as a PHP array:
    |   'excluded_tables' => ['user_summary', 'order_totals'],
    |
    */

    'excluded_tables' => env('DB_QUERY_CACHE_EXCLUDED_TABLES', []),

    /*
    |--------------------------------------------------------------------------
    | Require Tenant Context (multi-tenant safety)
    |--------------------------------------------------------------------------
    |
    | Cache keys are namespaced by tenant only AFTER you call
    | $connection->setTenantContext($tenantId). In a multi-tenant app that
    | shares one database connection across tenants, forgetting that call would
    | let every tenant read the same un-namespaced cache — a cross-tenant data
    | leak.
    |
    | Set this to true to fail safe: while no tenant context has been set on a
    | connection, caching is fully bypassed (no reads, writes, or invalidation),
    | so a missing setTenantContext() degrades to "no caching" instead of
    | leaking. Wire setTenantContext() into your tenancy middleware/bootstrapper
    | so the context is always present before queries run.
    |
    | Leave false for single-tenant apps.
    |
    */

    'tenant_required' => env('DB_QUERY_CACHE_TENANT_REQUIRED', false),

];
