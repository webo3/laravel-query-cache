<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use webO3\LaravelQueryCache\Drivers\RedisQueryCacheDriver;
use Illuminate\Support\Facades\Redis;
use webO3\LaravelQueryCache\Tests\TestCase;

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
            $this->redis = Redis::connection('query_cache');
            $this->redis->ping();

            // Create driver only after verifying Redis connection
            $this->driver = new RedisQueryCacheDriver([
                'ttl' => 300,
                'log_enabled' => false,
                'redis_connection' => 'query_cache',
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
        $key = 'test_hincrby_' . time();
        $result = ['item'];
        $query = 'SELECT * FROM items';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);

        // Record multiple hits
        $this->driver->recordHit($key);
        $this->driver->recordHit($key);
        $this->driver->recordHit($key);

        // Verify hits were incremented atomically
        $cached = $this->driver->get($key);
        $this->assertEquals(3, $cached['hits']);

        // Verify using direct Redis HGET
        $fullKey = $this->buildFullKey($key);
        $hitsFromRedis = (int)$this->redis->hget($fullKey, 'hits');
        $this->assertEquals(3, $hitsFromRedis);
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
        $tableIndexKey = 'query_cache:table:users';
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
        $keysInSet = $this->redis->smembers('query_cache:keys');
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
        $keysInSet = $this->redis->smembers('query_cache:keys');
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
        $keysInSet = $this->redis->smembers('query_cache:keys');
        $this->assertEmpty($keysInSet);
    }

    #[Test]
    public function it_preserves_ttl_on_record_hit()
    {
        $key = 'test_ttl_' . time();
        $result = ['data'];
        $query = 'SELECT 1';
        $executedAt = microtime(true);

        $this->driver->put($key, $result, $query, $executedAt);

        // Get initial TTL
        $fullKey = $this->buildFullKey($key);
        $ttlBefore = $this->redis->ttl($fullKey);

        // Wait a bit
        usleep(100000); // 0.1 seconds

        // Record hit should refresh TTL
        $this->driver->recordHit($key);

        $ttlAfter = $this->redis->ttl($fullKey);

        // TTL should be refreshed (close to original TTL)
        $this->assertGreaterThan($ttlBefore - 5, $ttlAfter);
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
