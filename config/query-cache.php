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
    | Use an array for multiple connections:
    |   'connection' => ['mysql', 'pgsql'],
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

    'redis_connection' => env('DB_QUERY_CACHE_REDIS_CONNECTION', 'query_cache'),

];
