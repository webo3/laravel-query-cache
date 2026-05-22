<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use Illuminate\Support\Facades\Redis;

/**
 * Test cached database connection using Redis driver
 *
 * This class tests the CachedMySQLConnection behavior with the
 * Redis cache driver. All test methods are inherited from
 * AbstractCachedConnectionTest.
 *
 * Note: These tests will be automatically skipped if Redis is not available
 * (useful for CI/CD pipelines without Redis).
 */
class CachedConnectionWithRedisDriverTest extends AbstractCachedConnectionTest
{
    /**
     * Get the cache driver name for this test
     * @return string
     */
    protected function getDriverName(): string
    {
        return 'redis';
    }

    /**
     * Check if Redis is available
     * @return bool
     */
    protected function isDriverAvailable(): bool
    {
        try {
            $redis = Redis::connection('db_cache');
            $redis->ping();
            return true;
        } catch (\Exception|\Error $e) {
            return false;
        }
    }

    /**
     * Redis-only: L2 (Redis) must survive a flushRequestCache() call.
     * Only L1 is per-request; L2 is shared and persistent.
     */
    public function test_flush_request_cache_preserves_l2_redis_entries()
    {
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        $this->getCachedConnection()->flushRequestCache();

        // L2 entry should still be in Redis after L1 flush
        $statsAfter = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsAfter['cached_queries_count']);
    }
}
