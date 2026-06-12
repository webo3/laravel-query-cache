# Redis Driver

Persistent cache shared across all workers and requests, using a two-tier architecture:

- **L1 (in-memory)**: per-request cache to avoid repeated Redis calls for the same query
- **L2 (Redis)**: persistent shared cache using Redis Hash structures

```env
DB_QUERY_CACHE_DRIVER=redis
DB_QUERY_CACHE_TTL=180
DB_QUERY_CACHE_REDIS_CONNECTION=db_cache
```

## Connection setup

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

The connection is resolved lazily: a missing or unreachable Redis configuration degrades to "no caching" (via the circuit breaker) instead of breaking database queries.

## TLS/SSL (AWS ElastiCache, Valkey)

For remote Redis connections that require TLS, add `scheme` and `context` options to the `db_cache` connection:

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

## Redis client

The package works with both `predis` and `phpredis`:

```bash
# Predis (pure PHP)
composer require predis/predis

# Or use phpredis (C extension, faster)
# Install via pecl: pecl install redis
```

## Optional: igbinary for faster serialization

```bash
pecl install igbinary
```

When available, the driver automatically uses igbinary for serialization and applies gzip compression for results larger than 10 KB.

## Payload authentication

Cached payloads are HMAC-SHA256-signed with `APP_KEY` and authenticated **before** unserialization — a payload that fails the check is treated as a cache miss, so unauthenticated data is never unserialized. Two practical consequences:

- Rotating `APP_KEY` invalidates the whole cache once (self-healing misses).
- Upgrading from a pre-HMAC version of this package does the same.

## Resilience

- **Circuit breaker:** after a connection/timeout error, Redis is skipped entirely for 5 seconds (reads, writes and L2 invalidation), so an outage can't turn every query into a socket timeout. The L1 cache keeps working — including table-based invalidation — during the outage.
- **TTL jitter:** entry TTLs get up to +10% random jitter so entries created together don't expire on the same tick and stampede the database.
- **Size guard:** results whose serialized form exceeds `DB_QUERY_CACHE_MAX_RESULT_BYTES` (default 1 MiB) are served from L1 for the request but never written to Redis.

## Housekeeping: `db-cache:prune`

The driver tracks cached keys in Redis Sets (a global tracking set plus one inverted index per table) for O(1) invalidation. These sets carry their own TTL, but tables that are read constantly refresh it forever while their members' data hashes expire — leaving dead references behind. Schedule a periodic prune:

```bash
php artisan db-cache:prune
```

```php
// routes/console.php or your scheduler
Schedule::command('db-cache:prune')->hourly();
```

## Redis Cluster (not supported)

The driver's `put()` writes the data hash, the key-tracking set, and the per-table indexes in **one `MULTI/EXEC` transaction that spans multiple keys**. On **Redis Cluster** those keys can hash to different slots, which makes the transaction fail. Point the `db_cache` connection at a **single Redis node/instance** — standalone Redis, AWS ElastiCache / Valkey in non-clustered mode, and a single primary are all fine; clustered mode is not.
