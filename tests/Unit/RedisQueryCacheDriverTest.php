<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use webO3\LaravelDbCache\Drivers\RedisQueryCacheDriver;
use Illuminate\Support\Facades\Redis;
use webO3\LaravelDbCache\Tests\TestCase;

/**
 * Test Redis Query Cache Driver
 *
 * Validates:
 * - Redis Hash structure (HMSET/HGETALL)
 * - HINCRBY atomic operations
 * - Redis pipelining
 * - Lazy-loading tables
 * - AWS/Valkey compatibility (Redis Sets)
 * - Manual serialization (igbinary + gzcompress) - REQUIRED for HMSET compatibility
 *
 * Note: These tests will be automatically skipped if Redis is not available
 * (useful for CI/CD pipelines without Redis).
 *
 * IMPORTANT: PhpRedis serializer (SERIALIZER_IGBINARY) does NOT work with HMSET/HGETALL.
 * It only works with simple GET/SET commands. Manual serialization is required.
 */
class RedisQueryCacheDriverTest extends TestCase
{
    private RedisQueryCacheDriver $driver;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip tests if Redis is not available
        try {
            $this->redis = Redis::connection('db_cache');
            $this->redis->ping();

            // Create driver only after verifying Redis connection
            $this->driver = new RedisQueryCacheDriver([
                'ttl' => 300,
                'log_enabled' => false,
                'redis_connection' => 'db_cache',
            ]);

            // Clear cache before each test
            $this->driver->flush();
        } catch (\Exception|\Error $e) {
            $this->markTestSkipped('Redis connection not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        if (isset($this->driver)) {
            $this->driver->flush();
        }
        parent::tearDown();
    }

    #[Test]
    public function it_stores_data_as_redis_hash()
    {
        $key = 'test_hash_' . time();
        $result = ['user1', 'user2'];
        $query = 'SELECT * FROM users LIMIT 2';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);

        // Verify Hash structure
        $fullKey = $this->buildFullKey($key);
        $type = $this->redis->type($fullKey);

        // Type should be Hash (4 or 5 depending on Redis client, or "hash" for predis)
        // 4 = REDIS_HASH in phpredis, 5 in some versions, "hash" for predis
        $this->assertTrue(in_array($type, [4, 5, 'hash']), "Expected Hash type (4, 5 or 'hash'), got {$type}");

        // Verify Hash fields
        $hashData = $this->redis->hgetall($fullKey);
        $this->assertArrayHasKey('result', $hashData);
        $this->assertArrayHasKey('query', $hashData);
        $this->assertArrayHasKey('hits', $hashData);
        $this->assertArrayHasKey('executed_at', $hashData);
        $this->assertArrayHasKey('cached_at', $hashData);
        $this->assertArrayHasKey('tables', $hashData);
    }

    #[Test]
    public function it_retrieves_data_from_hash()
    {
        $key = 'test_get_' . time();
        $result = ['data1', 'data2', 'data3'];
        $query = 'SELECT * FROM test';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);
        $cached = $this->driver->get($key);

        $this->assertNotNull($cached);
        $this->assertEquals($result, $cached['result']);
        $this->assertEquals($query, $cached['query']);
        $this->assertEquals(0, $cached['hits']);
    }

    #[Test]
    public function it_uses_hincrby_for_atomic_hit_counting()
    {
        // Hit counting only runs when stats logging is enabled.
        $driver = new RedisQueryCacheDriver([
            'ttl' => 300,
            'log_enabled' => true,
            'redis_connection' => 'db_cache',
        ]);

        $key = 'test_hincrby_' . time();
        $result = ['item'];
        $query = 'SELECT * FROM items';
        $executedAt = microtime(true);

        $driver->put($key, $result, $query, $executedAt);

        // Record multiple hits
        $driver->recordHit($key);
        $driver->recordHit($key);
        $driver->recordHit($key);

        // Verify hits were incremented atomically
        $cached = $driver->get($key);
        $this->assertEquals(3, $cached['hits']);

        // Verify using direct Redis HGET
        $fullKey = $this->buildFullKey($key);
        $hitsFromRedis = (int)$this->redis->hget($fullKey, 'hits');
        $this->assertEquals(3, $hitsFromRedis);

        $driver->flush();
    }

    #[Test]
    public function it_extracts_and_indexes_tables_on_put()
    {
        $key = 'test_tables_' . time();
        $result = ['user'];
        $query = 'SELECT * FROM users WHERE id = 1';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);

        // Verify tables field is populated immediately (for index-based invalidation)
        $fullKey = $this->buildFullKey($key);
        $tablesField = $this->redis->hget($fullKey, 'tables');
        $this->assertNotEmpty($tablesField);
        $this->assertEquals(['users'], json_decode($tablesField, true));

        // Tables should be available when retrieved
        $cached = $this->driver->get($key);
        $this->assertEquals(['users'], $cached['tables']);

        // Verify key is indexed in table-specific set
        $tableIndexKey = 'db_cache:table:users';
        $keysInTableIndex = $this->redis->smembers($tableIndexKey);
        $this->assertContains($key, $keysInTableIndex);
    }

    #[Test]
    public function it_tracks_keys_in_redis_set()
    {
        $key1 = 'test_set_1_' . time();
        $key2 = 'test_set_2_' . time();
        $result = ['data'];
        $query = 'SELECT 1';
        $executedAt = microtime(true);

        $this->driver->put($key1, $result, $query, $executedAt);
        $this->driver->put($key2, $result, $query, $executedAt);

        // Verify keys are in tracking Set
        $allKeys = $this->driver->getAllKeys();
        $this->assertContains($key1, $allKeys);
        $this->assertContains($key2, $allKeys);

        // Verify using direct Redis SMEMBERS
        $keysInSet = $this->redis->smembers('db_cache:keys');
        $this->assertContains($key1, $keysInSet);
        $this->assertContains($key2, $keysInSet);
    }

    #[Test]
    public function it_removes_key_from_set_on_forget()
    {
        $key = 'test_forget_' . time();
        $result = ['data'];
        $query = 'SELECT 1';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);
        $this->assertTrue($this->driver->has($key));

        $this->driver->forget($key);
        $this->assertFalse($this->driver->has($key));

        // Verify removed from tracking Set
        $keysInSet = $this->redis->smembers('db_cache:keys');
        $this->assertNotContains($key, $keysInSet);
    }

    #[Test]
    public function it_uses_pipelining_for_batch_operations()
    {
        // Create multiple cache entries
        for ($i = 1; $i <= 5; $i++) {
            $this->driver->put(
                "test_pipeline_{$i}_" . time(),
                ["data{$i}"],
                "SELECT {$i}",
                microtime(true)
            );
        }

        // getStats() should use pipelining (verified by performance)
        $start = microtime(true);
        $stats = $this->driver->getStats();
        $duration = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(5, $stats['cached_queries_count']);

        // Pipelining should be fast (< 50ms for 5 queries)
        // This is a loose assertion as it depends on Redis performance
        $this->assertLessThan(100, $duration);
    }

    #[Test]
    public function it_invalidates_all_keys_with_pipelining()
    {
        // Create multiple entries
        for ($i = 1; $i <= 3; $i++) {
            $this->driver->put(
                "test_invalidate_{$i}_" . time(),
                ["data"],
                "SELECT * FROM users",
                microtime(true)
            );
        }

        $statsBefore = $this->driver->getStats();
        $this->assertGreaterThanOrEqual(3, $statsBefore['cached_queries_count']);

        // Flush should use pipelined deletes
        $this->driver->flush();

        $statsAfter = $this->driver->getStats();
        $this->assertEquals(0, $statsAfter['cached_queries_count']);

        // Verify tracking Set is empty
        $keysInSet = $this->redis->smembers('db_cache:keys');
        $this->assertEmpty($keysInSet);
    }

    #[Test]
    public function it_does_not_refresh_ttl_on_record_hit()
    {
        // TTL must be absolute from put() time — refreshing on every hit
        // caused frequently-accessed keys to live forever and serve stale data.
        // Use a short TTL so the test can observe an actual decrement.
        // log_enabled so recordHit() actually touches Redis — otherwise the
        // no-refresh assertion below would pass vacuously (recordHit is a no-op
        // when stats are disabled).
        $shortTtlDriver = new RedisQueryCacheDriver([
            'ttl' => 10,
            'log_enabled' => true,
            'redis_connection' => 'db_cache',
        ]);

        $key = 'test_ttl_absolute_' . time();
        $result = ['data'];
        $query = 'SELECT 1';
        $executedAt = microtime(true);

        $shortTtlDriver->put($key, $result, $query, $executedAt);

        $fullKey = $this->buildFullKey($key);
        $ttlBefore = $this->redis->ttl($fullKey);

        // Wait long enough that TTL must have decremented at least 1s
        sleep(2);

        $shortTtlDriver->recordHit($key);

        $ttlAfter = $this->redis->ttl($fullKey);

        // recordHit() must not bump the TTL back up
        $this->assertLessThan($ttlBefore, $ttlAfter, 'recordHit() must not refresh TTL');
        $this->assertGreaterThan(0, $ttlAfter, 'Key should still be alive');

        $shortTtlDriver->flush();
    }

    #[Test]
    public function put_writes_hash_and_ttl_atomically()
    {
        $key = 'test_atomic_put_' . time();
        $result = ['data'];
        $query = 'SELECT * FROM users';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);

        $fullKey = $this->buildFullKey($key);

        // After put() the key must always have a TTL — never -1 (persistent).
        // MULTI/EXEC guarantees HMSET and EXPIRE land together or not at all.
        $ttl = $this->redis->ttl($fullKey);
        $this->assertGreaterThan(0, $ttl, 'Key written by put() must have a TTL set');
        $this->assertNotEquals(-1, $ttl, 'Key must not be persistent (no TTL)');
    }

    #[Test]
    public function it_does_not_record_hits_when_stats_disabled()
    {
        // Default driver has log_enabled = false (see setUp()).
        $key = 'test_no_stats_' . time();
        $this->driver->put($key, ['x'], 'SELECT * FROM t', microtime(true));

        $this->driver->recordHit($key);
        $this->driver->recordHit($key);

        // hits exists only to feed stats logging; when that's off we skip the
        // HINCRBY entirely, so the counter stays at its put()-time value.
        $cached = $this->driver->get($key);
        $this->assertEquals(0, $cached['hits']);
    }

    #[Test]
    public function record_hit_does_not_resurrect_an_absent_key_when_stats_disabled()
    {
        // The whole point of gating recordHit(): with stats off it must never
        // touch Redis, so a key that has expired (or never existed) cannot be
        // resurrected by HINCRBY into a TTL-less, result-less zombie.
        $key = 'test_no_resurrect_' . time();
        $fullKey = $this->buildFullKey($key);

        $this->driver->recordHit($key);

        $this->assertEquals(0, $this->redis->exists($fullKey), 'recordHit() must not create a key when stats are disabled');
    }

    #[Test]
    public function get_treats_a_resultless_zombie_hash_as_a_miss()
    {
        // Simulate a resurrected key: a hash holding only {hits} and no
        // 'result' field, exactly what HINCRBY produces on an expired key.
        $key = 'test_zombie_' . time();
        $fullKey = $this->buildFullKey($key);
        $this->redis->hincrby($fullKey, 'hits', 1);

        // Without the guard, get() would return ['result' => null, ...] and the
        // caller would receive a sticky null. It must be treated as a miss.
        $this->assertNull($this->driver->get($key), 'A hash with no result field must be a cache miss');
    }

    #[Test]
    public function a_resurrected_key_is_neutralized_by_get_even_with_stats_enabled()
    {
        // End-to-end race: stats on, key expires between get() and recordHit(),
        // recordHit() resurrects a result-less zombie. The get() guard must
        // still treat the next read as a miss so the caller never sees null.
        $driver = new RedisQueryCacheDriver([
            'ttl' => 300,
            'log_enabled' => true,
            'redis_connection' => 'db_cache',
        ]);

        $key = 'test_race_heal_' . time();
        $fullKey = $this->buildFullKey($key);

        $driver->put($key, ['real'], 'SELECT * FROM t', microtime(true));

        // Mimic the key expiring after get() read it but before recordHit().
        $this->redis->del($fullKey);
        $driver->recordHit($key); // resurrects {hits, last_accessed}, no result

        // The zombie exists in Redis...
        $this->assertEquals(1, $this->redis->exists($fullKey));
        // ...but get() must report a miss, not hand back result => null.
        $this->assertNull($driver->get($key));

        $driver->flush();
    }

    #[Test]
    public function it_handles_serialization_correctly()
    {
        $key = 'test_serialize_' . time();

        // Complex data structure
        $result = [
            ['id' => 1, 'name' => 'John', 'meta' => ['age' => 30]],
            ['id' => 2, 'name' => 'Jane', 'meta' => ['age' => 25]],
        ];

        $query = 'SELECT * FROM users';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);
        $cached = $this->driver->get($key);

        $this->assertEquals($result, $cached['result']);

        // Verify deep equality
        $this->assertEquals(30, $cached['result'][0]['meta']['age']);
        $this->assertEquals('Jane', $cached['result'][1]['name']);
    }

    #[Test]
    public function it_compresses_large_results_automatically()
    {
        $key = 'test_compression_' . time();

        // Generate large result (> 1KB) to trigger compression
        $result = [];
        for ($i = 0; $i < 200; $i++) {
            $result[] = [
                'id' => $i,
                'name' => 'User ' . $i,
                'email' => 'user' . $i . '@example.com',
                'description' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 10),
                'meta' => [
                    'created_at' => '2024-01-01 00:00:00',
                    'updated_at' => '2024-01-02 00:00:00',
                    'settings' => ['theme' => 'dark', 'language' => 'en', 'timezone' => 'UTC'],
                ],
            ];
        }

        $query = 'SELECT * FROM users LIMIT 200';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);
        $cached = $this->driver->get($key);

        // Verify data integrity after manual serialization/compression
        $this->assertCount(200, $cached['result']);
        $this->assertEquals($result[0]['id'], $cached['result'][0]['id']);
        $this->assertEquals($result[199]['name'], $cached['result'][199]['name']);
        $this->assertEquals($result[100]['meta']['settings'], $cached['result'][100]['meta']['settings']);

        // Verify compression is happening (compare raw Redis data size vs original data)
        $fullKey = $this->buildFullKey($key);
        $hashData = $this->redis->hget($fullKey, 'result');

        // Calculate original size (serialize with igbinary if available, otherwise PHP serialize)
        $originalSize = function_exists('igbinary_serialize')
            ? strlen(igbinary_serialize($result))
            : strlen(serialize($result));

        $compressedSize = strlen($hashData);
        $compressionRatio = ($originalSize - $compressedSize) / $originalSize;

        // Expect at least 30% compression for this large dataset
        $this->assertGreaterThan(0.3, $compressionRatio,
            "Expected at least 30% compression. Original: {$originalSize} bytes, Compressed: {$compressedSize} bytes");
    }

    /**
     * Build full Redis key with Laravel prefix
     */
    private function buildFullKey(string $key): string
    {
        $appName = config('app.name', 'laravel');
        $appSlug = \Illuminate\Support\Str::slug($appName, '_');
        $cachePrefix = config('cache.prefix');
        return "{$appSlug}_database_{$cachePrefix}:{$key}";
    }
}
