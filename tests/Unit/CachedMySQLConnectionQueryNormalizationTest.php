<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use webO3\LaravelQueryCache\Contracts\CachedConnection;
use Illuminate\Database\Connection;
use webO3\LaravelQueryCache\Tests\TestCase;

/**
 * Demonstrates the benefits of query normalization for caching efficiency
 */
class CachedMySQLConnectionQueryNormalizationTest extends TestCase
{
    protected ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable caching with array driver for testing
        config([
            'query-cache.enabled' => true,
            'query-cache.driver' => 'array',
            'query-cache.ttl' => 300,
            'query-cache.max_size' => 1000,
            'query-cache.log_enabled' => false,
            'database.connections.mysql.query_cache.enabled' => true,
            'database.connections.mysql.query_cache.driver' => 'array',
            'database.connections.mysql.query_cache.ttl' => 300,
            'database.connections.mysql.query_cache.max_size' => 1000,
            'database.connections.mysql.query_cache.log_enabled' => false,
        ]);

        app('db')->purge('mysql');
        $this->connection = app('db')->connection('mysql');

        if (!$this->connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection is not configured');
        }

        // Clear cache before each test
        $this->connection->clearQueryCache();

        $this->createTemporaryTable();
    }

    protected function tearDown(): void
    {
        $this->dropTemporaryTable();

        if ($this->connection) {
            $this->connection->clearQueryCache();
        }

        $this->connection = null;
        parent::tearDown();
    }

    protected function createTemporaryTable(): void
    {
        $this->connection->statement('
            CREATE TEMPORARY TABLE demo_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )
        ');
        $this->connection->insert('INSERT INTO demo_table (name) VALUES (?)', ['Demo Item']);
    }

    protected function dropTemporaryTable(): void
    {
        try {
            $this->connection->statement('DROP TEMPORARY TABLE IF EXISTS demo_table');
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Test that demonstrates query normalization improves cache hit rate
     */
    public function test_query_normalization_improves_cache_efficiency()
    {
        // Enable caching
        $this->connection->enableQueryCache();

        // Scenario: Different developers writing queries in different styles
        // Before normalization: These would create 12 separate cache entries
        // After normalization: All hit the same cache (1 entry, 11 hits)

        // Developer 1 prefers lowercase
        $this->connection->select('select * from demo_table where id = ?', [1]);

        // Developer 2 prefers uppercase
        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [1]);

        // Developer 3 uses mixed case
        $this->connection->select('Select * From demo_table Where id = ?', [1]);

        // ORM might add extra spaces
        $this->connection->select('SELECT  *  FROM  demo_table  WHERE  id = ?', [1]);

        // Code formatter adds newlines
        $this->connection->select("SELECT *\nFROM demo_table\nWHERE id = ?", [1]);

        // Leading/trailing whitespace from concatenation
        $this->connection->select('  SELECT * FROM demo_table WHERE id = ?  ', [1]);

        // Tabs instead of spaces
        $this->connection->select("SELECT\t*\tFROM\tdemo_table\tWHERE\tid = ?", [1]);

        // Multiple newlines and spaces
        $this->connection->select("SELECT   *\n\n  FROM   demo_table\n  WHERE   id = ?", [1]);

        // Another developer's style
        $this->connection->select('sElEcT * fRoM demo_table wHeRe id = ?', [1]);

        // Copy-pasted query with extra whitespace
        $this->connection->select('    SELECT * FROM demo_table WHERE id = ?    ', [1]);

        // IDE auto-formatted
        $this->connection->select("SELECT\n    *\nFROM\n    demo_table\nWHERE\n    id = ?", [1]);

        // Final variation
        $this->connection->select('SELECT * from DEMO_TABLE where ID = ?', [1]);

        $stats = $this->connection->getCacheStats();

        // Assert - All queries should hit the same cache entry
        $this->assertEquals(1, $stats['cached_queries_count'], 'Should have only 1 cached query');
        $this->assertEquals(11, $stats['total_cache_hits'], 'Should have 11 cache hits (12 queries - 1 initial cache)');

        // Calculate efficiency improvement
        $cacheHitRate = ($stats['total_cache_hits'] / 12) * 100;
        $this->assertGreaterThan(90, $cacheHitRate, 'Cache hit rate should be > 90%');
    }

    /**
     * Test that different logical queries still create separate cache entries
     */
    public function test_different_queries_still_cached_separately()
    {
        // Enable caching
        $this->connection->enableQueryCache();

        // These are logically different queries and should be cached separately
        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [1]);
        $this->connection->select('SELECT * FROM demo_table WHERE id > ?', [0]);
        $this->connection->select('SELECT name FROM demo_table WHERE id = ?', [1]);
        $this->connection->select('SELECT * FROM demo_table LIMIT 1');

        $stats = $this->connection->getCacheStats();

        // Assert - Should have 4 different cache entries
        $this->assertEquals(4, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);
    }

    /**
     * Test that bindings still differentiate queries
     */
    public function test_different_bindings_create_different_cache_entries()
    {
        // Enable caching
        $this->connection->enableQueryCache();

        // Same query but different parameters
        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [1]);
        $this->connection->select('select * from demo_table where id = ?', [2]); // Different case but different binding

        $stats = $this->connection->getCacheStats();

        // Assert - Should have 2 different cache entries (different bindings)
        $this->assertEquals(2, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);

        // Same query and same binding should hit cache
        $this->connection->select('SeLeCt * FrOm demo_table WhErE id = ?', [1]); // Different case but same binding

        $statsAfter = $this->connection->getCacheStats();
        $this->assertEquals(2, $statsAfter['cached_queries_count']);
        $this->assertEquals(1, $statsAfter['total_cache_hits']);
    }
}
