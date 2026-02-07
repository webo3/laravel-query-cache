<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use webO3\LaravelQueryCache\Drivers\NullQueryCacheDriver;

class NullQueryCacheDriverTest extends TestCase
{
    private NullQueryCacheDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new NullQueryCacheDriver();
    }

    #[Test]
    public function get_always_returns_null()
    {
        $this->assertNull($this->driver->get('any-key'));
        $this->assertNull($this->driver->get(''));
        $this->assertNull($this->driver->get('nonexistent'));
    }

    #[Test]
    public function put_does_nothing()
    {
        // Should not throw or store anything
        $this->driver->put('key', ['data'], 'SELECT 1', microtime(true));

        // Verify nothing was stored
        $this->assertNull($this->driver->get('key'));
    }

    #[Test]
    public function has_always_returns_false()
    {
        $this->assertFalse($this->driver->has('any-key'));

        // Even after put, has should return false
        $this->driver->put('key', ['data'], 'SELECT 1', microtime(true));
        $this->assertFalse($this->driver->has('key'));
    }

    #[Test]
    public function forget_does_nothing()
    {
        // Should not throw
        $this->driver->forget('any-key');
        $this->driver->forget('');
        $this->assertNull($this->driver->get('any-key'));
    }

    #[Test]
    public function invalidate_tables_returns_zero()
    {
        $result = $this->driver->invalidateTables(['users', 'posts'], 'INSERT INTO users VALUES (1)');
        $this->assertEquals(0, $result);

        $result = $this->driver->invalidateTables([], 'INSERT INTO users VALUES (1)');
        $this->assertEquals(0, $result);
    }

    #[Test]
    public function flush_does_nothing()
    {
        // Should not throw
        $this->driver->flush();
        $this->assertEquals(0, $this->driver->getStats()['cached_queries_count']);
    }

    #[Test]
    public function get_stats_returns_empty_structure()
    {
        $stats = $this->driver->getStats();

        $this->assertEquals('null', $stats['driver']);
        $this->assertEquals(0, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);
        $this->assertEmpty($stats['queries']);
        $this->assertIsArray($stats['queries']);
    }

    #[Test]
    public function record_hit_does_nothing()
    {
        // Should not throw
        $this->driver->recordHit('any-key');
        $this->driver->recordHit('');

        // Stats should still show 0 hits
        $this->assertEquals(0, $this->driver->getStats()['total_cache_hits']);
    }

    #[Test]
    public function get_all_keys_returns_empty_array()
    {
        $keys = $this->driver->getAllKeys();

        $this->assertIsArray($keys);
        $this->assertEmpty($keys);

        // Even after put, should still return empty
        $this->driver->put('key', ['data'], 'SELECT 1', microtime(true));
        $this->assertEmpty($this->driver->getAllKeys());
    }
}
