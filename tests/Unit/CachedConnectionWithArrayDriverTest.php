<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

/**
 * Test cached database connection using Array driver
 *
 * This class tests the CachedMySQLConnection behavior with the
 * in-memory array cache driver. All test methods are inherited
 * from AbstractCachedConnectionTest.
 */
class CachedConnectionWithArrayDriverTest extends AbstractCachedConnectionTest
{
    /**
     * Get the cache driver name for this test
     * @return string
     */
    protected function getDriverName(): string
    {
        return 'array';
    }
}
