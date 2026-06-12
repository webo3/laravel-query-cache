<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use webO3\LaravelDbCache\Contracts\CachedConnection;
use Illuminate\Database\Connection;
use webO3\LaravelDbCache\Tests\TestCase;

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
            'db-cache.enabled' => true,
            'db-cache.driver' => 'array',
            'db-cache.ttl' => 300,
            'db-cache.max_size' => 1000,
            'db-cache.log_enabled' => false,
            'database.connections.mysql.db_cache.enabled' => true,
            'database.connections.mysql.db_cache.driver' => 'array',
            'database.connections.mysql.db_cache.ttl' => 300,
            'database.connections.mysql.db_cache.max_size' => 1000,
            'database.connections.mysql.db_cache.log_enabled' => false,
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

        // Whitespace differences (the safe normalization) must collapse to one
        // cache entry. Case is intentionally NOT folded — see
        // test_identifier_case_creates_distinct_cache_entries.

        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [1]);

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

        // Copy-pasted query with extra whitespace
        $this->connection->select('    SELECT * FROM demo_table WHERE id = ?    ', [1]);

        // IDE auto-formatted
        $this->connection->select("SELECT\n    *\nFROM\n    demo_table\nWHERE\n    id = ?", [1]);

        $stats = $this->connection->getCacheStats();

        // Assert - All queries should hit the same cache entry
        $this->assertEquals(1, $stats['cached_queries_count'], 'Should have only 1 cached query');
        $this->assertEquals(7, $stats['total_cache_hits'], 'Should have 7 cache hits (8 queries - 1 initial cache)');
    }

    /**
     * Identifier case must NOT be folded: on case-sensitive systems (MySQL
     * with lower_case_table_names=0) demo_table and DEMO_TABLE are different
     * tables, and folding case would alias them to one cache key — a
     * collision serving the wrong table's rows.
     */
    public function test_identifier_case_creates_distinct_cache_entries()
    {
        $this->connection->enableQueryCache();

        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [1]);
        $this->connection->select('select * from demo_table where id = ?', [1]);
        $this->connection->select('SELECT * FROM DEMO_TABLE WHERE id = ?', [1]);

        $stats = $this->connection->getCacheStats();

        $this->assertEquals(3, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);
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
        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [2]); // Same query, different binding

        $stats = $this->connection->getCacheStats();

        // Assert - Should have 2 different cache entries (different bindings)
        $this->assertEquals(2, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);

        // Same query and same binding should hit cache
        $this->connection->select('SELECT * FROM demo_table WHERE id = ?', [1]); // Same query, same binding

        $statsAfter = $this->connection->getCacheStats();
        $this->assertEquals(2, $statsAfter['cached_queries_count']);
        $this->assertEquals(1, $statsAfter['total_cache_hits']);
    }
}
