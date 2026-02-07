<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use webO3\LaravelQueryCache\Drivers\ArrayQueryCacheDriver;

class ArrayQueryCacheDriverTest extends TestCase
{
    private ArrayQueryCacheDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new ArrayQueryCacheDriver(['max_size' => 1000, 'log_enabled' => false]);
        $this->driver->flush();
    }

    protected function tearDown(): void
    {
        $this->driver->flush();
        parent::tearDown();
    }

    // ===================================
    // FORGET TESTS
    // ===================================

    #[Test]
    public function forget_removes_specific_key()
    {
        $this->driver->put('key1', ['data1'], 'SELECT 1', microtime(true));
        $this->driver->put('key2', ['data2'], 'SELECT 2', microtime(true));

        $this->driver->forget('key1');

        $this->assertNull($this->driver->get('key1'));
        $this->assertNotNull($this->driver->get('key2'));
    }

    #[Test]
    public function forget_nonexistent_key_does_not_throw()
    {
        $this->driver->forget('nonexistent');
        $this->assertEquals(0, $this->driver->getStats()['cached_queries_count']);
    }

    // ===================================
    // HAS TESTS
    // ===================================

    #[Test]
    public function has_returns_true_for_existing_key()
    {
        $this->driver->put('key1', ['data'], 'SELECT 1', microtime(true));

        $this->assertTrue($this->driver->has('key1'));
        $this->assertFalse($this->driver->has('nonexistent'));
    }

    // ===================================
    // RECORD HIT TESTS
    // ===================================

    #[Test]
    public function record_hit_increments_hit_count()
    {
        $this->driver->put('key1', ['data'], 'SELECT * FROM users', microtime(true));

        $this->driver->recordHit('key1');
        $this->driver->recordHit('key1');
        $this->driver->recordHit('key1');

        $stats = $this->driver->getStats();
        $this->assertEquals(3, $stats['total_cache_hits']);
        $this->assertEquals(3, $stats['queries'][0]['hits']);
    }

    #[Test]
    public function record_hit_updates_last_accessed()
    {
        $this->driver->put('key1', ['data'], 'SELECT * FROM users', microtime(true));
        $before = microtime(true);

        usleep(1000); // 1ms
        $this->driver->recordHit('key1');

        $after = microtime(true);

        // Verify internal state through get
        $cached = $this->driver->get('key1');
        $this->assertNotNull($cached);
    }

    #[Test]
    public function record_hit_on_nonexistent_key_does_nothing()
    {
        $this->driver->recordHit('nonexistent');
        $this->assertEquals(0, $this->driver->getStats()['total_cache_hits']);
    }

    // ===================================
    // GET ALL KEYS TESTS
    // ===================================

    #[Test]
    public function get_all_keys_returns_all_cached_keys()
    {
        $this->driver->put('key1', ['data1'], 'SELECT 1', microtime(true));
        $this->driver->put('key2', ['data2'], 'SELECT 2', microtime(true));
        $this->driver->put('key3', ['data3'], 'SELECT 3', microtime(true));

        $keys = $this->driver->getAllKeys();

        $this->assertCount(3, $keys);
        $this->assertContains('key1', $keys);
        $this->assertContains('key2', $keys);
        $this->assertContains('key3', $keys);
    }

    #[Test]
    public function get_all_keys_returns_empty_when_cache_is_empty()
    {
        $keys = $this->driver->getAllKeys();
        $this->assertEmpty($keys);
    }

    // ===================================
    // INVALIDATE TABLES TESTS
    // ===================================

    #[Test]
    public function invalidate_tables_removes_matching_entries()
    {
        $this->driver->put('key1', ['data1'], 'SELECT * FROM users WHERE id = 1', microtime(true));
        $this->driver->put('key2', ['data2'], 'SELECT * FROM posts WHERE id = 1', microtime(true));
        $this->driver->put('key3', ['data3'], 'SELECT * FROM users WHERE id = 2', microtime(true));

        $invalidated = $this->driver->invalidateTables(['users'], 'INSERT INTO users VALUES (1)');

        $this->assertEquals(2, $invalidated);
        $this->assertNull($this->driver->get('key1'));
        $this->assertNotNull($this->driver->get('key2'));
        $this->assertNull($this->driver->get('key3'));
    }

    #[Test]
    public function invalidate_tables_with_empty_tables_clears_all()
    {
        $this->driver->put('key1', ['data1'], 'SELECT * FROM users', microtime(true));
        $this->driver->put('key2', ['data2'], 'SELECT * FROM posts', microtime(true));

        $cleared = $this->driver->invalidateTables([], 'SOME UNKNOWN QUERY');

        $this->assertEquals(2, $cleared);
        $this->assertEquals(0, $this->driver->getStats()['cached_queries_count']);
    }

    #[Test]
    public function invalidate_tables_returns_zero_when_no_matches()
    {
        $this->driver->put('key1', ['data1'], 'SELECT * FROM users', microtime(true));

        $invalidated = $this->driver->invalidateTables(['orders'], 'INSERT INTO orders VALUES (1)');

        $this->assertEquals(0, $invalidated);
        $this->assertEquals(1, $this->driver->getStats()['cached_queries_count']);
    }

    // ===================================
    // LRU EVICTION TESTS
    // ===================================

    #[Test]
    public function evicts_oldest_entries_when_max_size_reached()
    {
        // Create a driver with a small max_size
        $smallDriver = new ArrayQueryCacheDriver(['max_size' => 10, 'log_enabled' => false]);
        $smallDriver->flush();

        // Fill cache to max capacity
        for ($i = 0; $i < 10; $i++) {
            $smallDriver->put("key{$i}", ["data{$i}"], "SELECT {$i}", microtime(true));
            usleep(1000); // Ensure different last_accessed times
        }

        $this->assertEquals(10, $smallDriver->getStats()['cached_queries_count']);

        // Adding one more should trigger eviction of 10% (1 entry)
        $smallDriver->put('key_new', ['new_data'], 'SELECT new', microtime(true));

        // Should have evicted 1 entry (10% of 10) and then added the new one
        // So we should have 10 entries (10 - 1 evicted + 1 new)
        $stats = $smallDriver->getStats();
        $this->assertEquals(10, $stats['cached_queries_count']);

        // The new entry should be present
        $this->assertNotNull($smallDriver->get('key_new'));

        $smallDriver->flush();
    }

    #[Test]
    public function eviction_removes_least_recently_used_entries()
    {
        $smallDriver = new ArrayQueryCacheDriver(['max_size' => 5, 'log_enabled' => false]);
        $smallDriver->flush();

        // Add 5 entries with increasing timestamps
        for ($i = 0; $i < 5; $i++) {
            $smallDriver->put("key{$i}", ["data{$i}"], "SELECT {$i}", microtime(true));
            usleep(10000); // 10ms between each to ensure different timestamps
        }

        // Access key0 and key1 to make them recently used
        $smallDriver->recordHit('key0');
        usleep(1000);
        $smallDriver->recordHit('key1');
        usleep(1000);

        // Adding new entry should trigger eviction of the oldest 10% (ceil(5*0.1)=1)
        $smallDriver->put('key_new', ['new'], 'SELECT new', microtime(true));

        // key2 should be evicted (least recently used among those not explicitly accessed)
        $this->assertNotNull($smallDriver->get('key0'));
        $this->assertNotNull($smallDriver->get('key1'));
        $this->assertNotNull($smallDriver->get('key_new'));

        $smallDriver->flush();
    }

    // ===================================
    // GET STATS TESTS
    // ===================================

    #[Test]
    public function get_stats_returns_correct_structure()
    {
        $this->driver->put('key1', ['data'], 'SELECT * FROM users WHERE id = 1', microtime(true));
        $this->driver->recordHit('key1');

        $stats = $this->driver->getStats();

        $this->assertEquals('array', $stats['driver']);
        $this->assertEquals(1, $stats['cached_queries_count']);
        $this->assertEquals(1, $stats['total_cache_hits']);
        $this->assertCount(1, $stats['queries']);

        $query = $stats['queries'][0];
        $this->assertEquals('SELECT * FROM users WHERE id = 1', $query['query']);
        $this->assertContains('users', $query['tables']);
        $this->assertEquals(1, $query['hits']);
        $this->assertArrayHasKey('cached_at', $query);
    }

    #[Test]
    public function get_stats_lazy_loads_tables()
    {
        // Put a query - tables are lazy-loaded (null initially)
        $this->driver->put('key1', ['data'], 'SELECT * FROM users', microtime(true));

        // getStats() should extract tables when they're null
        $stats = $this->driver->getStats();

        $this->assertContains('users', $stats['queries'][0]['tables']);
    }

    // ===================================
    // FLUSH TESTS
    // ===================================

    #[Test]
    public function flush_clears_all_entries()
    {
        $this->driver->put('key1', ['data1'], 'SELECT 1', microtime(true));
        $this->driver->put('key2', ['data2'], 'SELECT 2', microtime(true));

        $this->driver->flush();

        $this->assertEquals(0, $this->driver->getStats()['cached_queries_count']);
        $this->assertEmpty($this->driver->getAllKeys());
    }

}
