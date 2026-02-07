<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use Illuminate\Database\Connection;
use webO3\LaravelQueryCache\Contracts\CachedConnection;
use webO3\LaravelQueryCache\Tests\TestCase;

/**
 * Abstract test class for cached database connections
 *
 * This class contains all the test methods for testing cached connections
 * regardless of the cache driver used (array, redis, etc.)
 *
 * Concrete test classes should extend this and implement getDriverName()
 * to specify which cache driver to use.
 */
abstract class AbstractCachedConnectionTest extends TestCase
{
    protected ?Connection $connection = null;

    /**
     * Get the cache driver name for this test
     * @return string
     */
    abstract protected function getDriverName(): string;

    /**
     * Get the database connection name for this test
     * @return string
     */
    protected function getConnectionName(): string
    {
        return 'mysql';
    }

    /**
     * Check if the driver is available (e.g., Redis might not be available)
     * @return bool
     */
    protected function isDriverAvailable(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Check if driver is available
        if (!$this->isDriverAvailable()) {
            $this->markTestSkipped($this->getDriverName() . ' driver is not available');
        }

        $conn = $this->getConnectionName();

        // Enable caching with the specified driver
        config([
            'query-cache.enabled' => true,
            'query-cache.driver' => $this->getDriverName(),
            'query-cache.ttl' => 300,
            'query-cache.max_size' => 1000,
            'query-cache.log_enabled' => false,
            "database.connections.{$conn}.query_cache.enabled" => true,
            "database.connections.{$conn}.query_cache.driver" => $this->getDriverName(),
            "database.connections.{$conn}.query_cache.ttl" => 300,
            "database.connections.{$conn}.query_cache.max_size" => 1000,
            "database.connections.{$conn}.query_cache.log_enabled" => false,
        ]);

        // Purge existing connection to force reconnection with new config
        app('db')->purge($conn);

        // Get connection once and reuse it throughout the test
        $this->connection = app('db')->connection($conn);

        // Skip test if not using a CachedConnection
        if (!$this->connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection is not configured');
        }

        // Clear cache before each test
        $this->connection->clearQueryCache();

        // Create temporary tables for testing
        $this->createTemporaryTables();
    }

    protected function tearDown(): void
    {
        // Drop temporary tables
        $this->dropTemporaryTables();

        // Clear cache after each test
        if ($this->connection) {
            $this->connection->clearQueryCache();
        }

        $this->connection = null;

        parent::tearDown();
    }

    /**
     * Create temporary tables for testing
     */
    protected function createTemporaryTables(): void
    {
        // Create test_cache_products table
        $this->connection->statement('
            CREATE TEMPORARY TABLE test_cache_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Create test_cache_categories table
        $this->connection->statement('
            CREATE TEMPORARY TABLE test_cache_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Insert some test data
        $this->connection->insert('INSERT INTO test_cache_products (name, price) VALUES (?, ?)', ['Product 1', 10.00]);
        $this->connection->insert('INSERT INTO test_cache_products (name, price) VALUES (?, ?)', ['Product 2', 20.00]);
        $this->connection->insert('INSERT INTO test_cache_categories (name) VALUES (?)', ['Category 1']);
        $this->connection->insert('INSERT INTO test_cache_categories (name) VALUES (?)', ['Category 2']);
    }

    /**
     * Drop temporary tables
     */
    protected function dropTemporaryTables(): void
    {
        try {
            $this->connection->statement('DROP TEMPORARY TABLE IF EXISTS test_cache_products');
            $this->connection->statement('DROP TEMPORARY TABLE IF EXISTS test_cache_categories');
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Get a cached connection instance for testing
     */
    protected function getCachedConnection(): Connection
    {
        return $this->connection;
    }

    // ===================================
    // BASIC CACHING TESTS
    // ===================================

    public function test_clear_query_cache_empties_cache()
    {
        // Arrange - Execute a query to populate cache
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        // Act
        $this->getCachedConnection()->clearQueryCache();
        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert
        $this->assertEquals(0, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);
    }

    public function test_caches_select_queries()
    {

        // Act - Execute same query twice
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Should have 1 cached query with 1 hit
        $this->assertEquals(1, $stats['cached_queries_count']);
        $this->assertEquals(1, $stats['total_cache_hits']);
    }

    public function test_caches_different_select_queries_separately()
    {
        // Arrange

        // Act - Execute two different queries
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');
        $this->getCachedConnection()->select('SELECT * FROM test_cache_categories LIMIT 1');

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Should have 2 cached queries with 0 hits
        $this->assertEquals(2, $stats['cached_queries_count']);
        $this->assertEquals(0, $stats['total_cache_hits']);
    }

    public function test_caches_queries_with_different_bindings_separately()
    {
        // Arrange

        // Act - Execute same query with different parameters
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products WHERE id = ?', [1]);
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products WHERE id = ?', [2]);

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Should have 2 cached queries
        $this->assertEquals(2, $stats['cached_queries_count']);
    }

    public function test_cache_hit_count_increments_correctly()
    {
        // Arrange

        // Act - Execute same query 5 times
        for ($i = 0; $i < 5; $i++) {
            $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');
        }

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Should have 1 cached query with 4 hits (first execution caches, next 4 hit cache)
        $this->assertEquals(1, $stats['cached_queries_count']);
        $this->assertEquals(4, $stats['total_cache_hits']);
    }

    // ===================================
    // CACHE INVALIDATION TESTS
    // ===================================

    public function test_insert_invalidates_cache_for_affected_table()
    {
        // Arrange - Cache a query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_categories LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        // Act - Insert into the same table
        $this->getCachedConnection()->insert('INSERT INTO test_cache_categories (name) VALUES (?)', ['Test Category']);

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - Cache should be invalidated
        $this->assertEquals(0, $statsAfter['cached_queries_count']);
    }

    public function test_update_invalidates_cache_for_affected_table()
    {
        // Arrange - Cache a query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        // Act - Update the same table
        $this->getCachedConnection()->update('UPDATE test_cache_products SET name = ? WHERE id = 1', ['Updated Name']);

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - Cache should be invalidated
        $this->assertEquals(0, $statsAfter['cached_queries_count']);
    }

    public function test_delete_invalidates_cache_for_affected_table()
    {
        // Arrange - Cache a query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        // Act - Delete from the same table
        $this->getCachedConnection()->delete('DELETE FROM test_cache_products WHERE id = 999999');

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - Cache should be invalidated
        $this->assertEquals(0, $statsAfter['cached_queries_count']);
    }

    public function test_truncate_invalidates_cache()
    {
        // Arrange - Cache a query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        // Act - TRUNCATE the table (this works on temporary tables)
        $this->getCachedConnection()->statement('TRUNCATE TABLE test_cache_products');

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - Cache should be invalidated
        $this->assertEquals(0, $statsAfter['cached_queries_count']);
    }

    public function test_mutation_does_not_invalidate_unrelated_cache()
    {
        // Arrange - Cache queries from two different tables
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');
        $this->getCachedConnection()->select('SELECT * FROM test_cache_categories LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(2, $statsBefore['cached_queries_count']);

        // Act - Update only test_cache_products table
        $this->getCachedConnection()->update('UPDATE test_cache_products SET name = ? WHERE id = 1', ['Updated']);

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - Only 1 cache entry should remain (test_cache_categories query)
        $this->assertEquals(1, $statsAfter['cached_queries_count']);

        // Verify the remaining query is for test_cache_categories table
        $remainingQuery = $statsAfter['queries'][0];
        $this->assertStringContainsString('test_cache_categories', $remainingQuery['query']);
    }

    // ===================================
    // JOIN QUERY TESTS
    // ===================================

    public function test_join_queries_track_all_involved_tables()
    {
        // Arrange

        // Cache a JOIN query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products INNER JOIN test_cache_categories ON test_cache_products.id = test_cache_categories.id LIMIT 1');

        $stats = $this->getCachedConnection()->getCacheStats();
        $cachedQuery = $stats['queries'][0];

        // Assert - Should track both tables
        $this->assertContains('test_cache_products', $cachedQuery['tables']);
        $this->assertContains('test_cache_categories', $cachedQuery['tables']);
    }

    public function test_invalidates_join_queries_when_any_table_mutated()
    {
        // Arrange - Cache a JOIN query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products INNER JOIN test_cache_categories ON test_cache_products.id = test_cache_categories.id LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        // Act - Update test_cache_categories table (one of the joined tables)
        $this->getCachedConnection()->update('UPDATE test_cache_categories SET name = ? WHERE id = 1', ['Updated']);

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - JOIN cache should be invalidated
        $this->assertEquals(0, $statsAfter['cached_queries_count']);
    }

    // ===================================
    // CURSOR QUERY TESTS
    // ===================================

    public function test_cursor_queries_are_not_cached()
    {
        // Arrange

        // Act - Execute cursor query twice
        $firstCursor = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_products LIMIT 5');
        $firstResults = iterator_to_array($firstCursor);

        $statsAfterFirst = $this->getCachedConnection()->getCacheStats();

        $secondCursor = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_products LIMIT 5');
        $secondResults = iterator_to_array($secondCursor);

        $statsAfterSecond = $this->getCachedConnection()->getCacheStats();

        // Assert - cursor() queries should NOT be cached
        // cursor() is designed for memory-efficient streaming, incompatible with caching
        $this->assertEquals(0, $statsAfterFirst['cached_queries_count']);
        $this->assertEquals(0, $statsAfterFirst['total_cache_hits']);

        $this->assertEquals(0, $statsAfterSecond['cached_queries_count']);
        $this->assertEquals(0, $statsAfterSecond['total_cache_hits']);

        // Results should still be correct
        $this->assertEquals($firstResults, $secondResults);
        $this->assertCount(2, $firstResults); // We have 2 products in test data
    }

    public function test_cursor_returns_iterable_results()
    {
        // Arrange

        // Act
        $cursor = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_products LIMIT 5');

        // Assert - cursor should return an iterable (Generator or array that can be iterated)
        $this->assertTrue(is_iterable($cursor), 'cursor() should return an iterable');

        // Should be able to iterate over results
        $count = 0;
        foreach ($cursor as $row) {
            $this->assertIsObject($row);
            $this->assertObjectHasProperty('id', $row);
            $this->assertObjectHasProperty('name', $row);
            $count++;
        }

        $this->assertGreaterThan(0, $count, 'cursor() should yield at least one row');
    }

    public function test_cursor_and_select_do_not_share_cache()
    {
        // Arrange

        // Act - Execute with select() first (should be cached)
        $selectResults = $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 2');

        $statsAfterSelect = $this->getCachedConnection()->getCacheStats();

        // Execute same query with cursor() - should NOT use cache
        $cursor = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_products LIMIT 2');
        $cursorResults = iterator_to_array($cursor);

        $statsAfterCursor = $this->getCachedConnection()->getCacheStats();

        // Assert - select() should be cached, cursor() should NOT
        $this->assertEquals(1, $statsAfterSelect['cached_queries_count']);
        $this->assertEquals(0, $statsAfterSelect['total_cache_hits']);

        // cursor() should not affect cache stats
        $this->assertEquals(1, $statsAfterCursor['cached_queries_count']);
        $this->assertEquals(0, $statsAfterCursor['total_cache_hits']); // No cache hit from cursor()

        // Results should be equivalent (same data)
        $this->assertCount(count($selectResults), $cursorResults);
    }

    public function test_cursor_works_after_mutations()
    {
        // Arrange - Use cursor before and after mutation

        // Execute cursor query before mutation
        $cursorBefore = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_products LIMIT 5');
        $resultsBefore = iterator_to_array($cursorBefore);

        // Act - Mutate the table
        $this->getCachedConnection()->insert('INSERT INTO test_cache_products (name, price) VALUES (?, ?)', ['New Product', 99.99]);

        // Execute cursor query after mutation
        $cursorAfter = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_products LIMIT 5');
        $resultsAfter = iterator_to_array($cursorAfter);

        // Assert - cursor() should always get fresh data (not cached)
        $this->assertCount(2, $resultsBefore); // Original 2 products
        $this->assertCount(3, $resultsAfter);  // Now 3 products (after insert)

        // Cache should still be empty (cursor doesn't cache)
        $stats = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(0, $stats['cached_queries_count']);
    }

    public function test_caching_reactivated_after_cursor()
    {
        // This test verifies that the inCursorQuery flag is properly reset
        // and caching works normally after a cursor() call

        // Arrange

        // Act - Execute select() query (should be cached)
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');
        $statsAfterSelect = $this->getCachedConnection()->getCacheStats();

        // Execute cursor() query (should NOT be cached)
        $cursor = $this->getCachedConnection()->cursor('SELECT * FROM test_cache_categories LIMIT 1');
        iterator_to_array($cursor); // Consume cursor
        $statsAfterCursor = $this->getCachedConnection()->getCacheStats();

        // Execute another select() query - should be cached again!
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products WHERE id = 1');
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products WHERE id = 1'); // Same query = cache hit
        $statsAfterSecondSelect = $this->getCachedConnection()->getCacheStats();

        // Assert
        // First select() should create 1 cache entry
        $this->assertEquals(1, $statsAfterSelect['cached_queries_count']);
        $this->assertEquals(0, $statsAfterSelect['total_cache_hits']);

        // cursor() should not add to cache
        $this->assertEquals(1, $statsAfterCursor['cached_queries_count']); // Still 1 from first select()

        // Second select() should work normally with cache
        $this->assertEquals(2, $statsAfterSecondSelect['cached_queries_count']); // 2 cached queries now
        $this->assertEquals(1, $statsAfterSecondSelect['total_cache_hits']); // 1 hit from repeated query

        // Verify caching is working correctly after cursor
        $this->assertTrue(true, 'Caching should be reactivated after cursor() completes');
    }

    // ===================================
    // QUERY NORMALIZATION TESTS
    // ===================================

    public function test_query_caching_is_case_insensitive()
    {
        // Arrange

        // Act - Execute queries with different cases
        $this->getCachedConnection()->select('select * from test_cache_products limit 1');
        $this->getCachedConnection()->select('SELECT * from test_cache_products limit 1');
        $this->getCachedConnection()->select('SeLeCt * FROM test_cache_products LIMIT 1');

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - All variations should be treated as the same query (1 cache entry, 2 hits)
        $this->assertEquals(1, $stats['cached_queries_count']);
        $this->assertEquals(2, $stats['total_cache_hits']);
    }

    public function test_whitespace_normalization_in_query_caching()
    {
        // Arrange

        // Act - Execute queries with different whitespace patterns
        $this->getCachedConnection()->select('   SELECT * FROM test_cache_products LIMIT 1');
        $this->getCachedConnection()->select('SELECT  *  FROM   test_cache_products   LIMIT 1');
        $this->getCachedConnection()->select("SELECT\t*\nFROM test_cache_products\nLIMIT 1");

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - All variations should be treated as the same query (1 cache entry, 2 hits)
        $this->assertEquals(1, $stats['cached_queries_count']);
        $this->assertEquals(2, $stats['total_cache_hits']);
    }

    public function test_extracts_backtick_quoted_table_names()
    {
        // Arrange

        // Act - Execute query with backtick-quoted table names
        $this->getCachedConnection()->select('SELECT * FROM `test_cache_products` WHERE id = 1');

        $stats = $this->getCachedConnection()->getCacheStats();
        $cachedQuery = $stats['queries'][0];

        // Assert - Should extract table name without backticks
        $this->assertContains('test_cache_products', $cachedQuery['tables']);
    }

    // ===================================
    // RESULT CORRECTNESS TESTS
    // ===================================

    public function test_cached_results_match_uncached_results()
    {
        // Arrange
        $this->getCachedConnection()->disableQueryCache();
        $uncachedResult = $this->getCachedConnection()->select('SELECT id, name FROM test_cache_products LIMIT 1');

        $this->getCachedConnection()->clearQueryCache();

        // Act - Execute twice to test cache
        $firstResult = $this->getCachedConnection()->select('SELECT id, name FROM test_cache_products LIMIT 1');
        $cachedResult = $this->getCachedConnection()->select('SELECT id, name FROM test_cache_products LIMIT 1');

        // Assert - All results should be identical
        $this->assertEquals($uncachedResult, $firstResult);
        $this->assertEquals($uncachedResult, $cachedResult);
    }

    // ===================================
    // ENABLE/DISABLE TESTS
    // ===================================

    public function test_caching_can_be_enabled_and_disabled()
    {
        // Arrange - Disable caching
        $this->getCachedConnection()->disableQueryCache();

        // Act - Execute same query twice
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Should have 0 cached queries (caching was disabled)
        $this->assertEquals(0, $stats['cached_queries_count']);

        // Enable caching
        $this->getCachedConnection()->enableQueryCache();
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $statsEnabled = $this->getCachedConnection()->getCacheStats();

        // Assert - Should now have 1 cached query
        $this->assertEquals(1, $statsEnabled['cached_queries_count']);
    }

    // ===================================
    // STATS STRUCTURE TESTS
    // ===================================

    public function test_cache_statistics_structure_is_correct()
    {
        // Arrange
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        // Act
        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Verify structure
        $this->assertArrayHasKey('cached_queries_count', $stats);
        $this->assertArrayHasKey('total_cache_hits', $stats);
        $this->assertArrayHasKey('queries', $stats);
        $this->assertIsArray($stats['queries']);

        // Verify query structure
        $query = $stats['queries'][0];
        $this->assertArrayHasKey('query', $query);
        $this->assertArrayHasKey('tables', $query);
        $this->assertArrayHasKey('hits', $query);
        $this->assertArrayHasKey('cached_at', $query);
    }

    public function test_driver_is_correctly_configured()
    {
        // Arrange & Act
        $stats = $this->getCachedConnection()->getCacheStats();

        // Assert - Should be using the expected driver
        $this->assertEquals($this->getDriverName(), $stats['driver']);
    }
}
