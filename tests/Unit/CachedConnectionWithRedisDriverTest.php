<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

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
            $redis = Redis::connection('query_cache');
            $redis->ping();
            return true;
        } catch (\Exception|\Error $e) {
            return false;
        }
    }
}
