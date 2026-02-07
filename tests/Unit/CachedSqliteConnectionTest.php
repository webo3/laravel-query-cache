<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

/**
 * Test cached database connection using SQLite + Array driver
 *
 * Verifies that CachedSQLiteConnection works end-to-end with
 * an in-memory SQLite database. All test methods are inherited
 * from AbstractCachedConnectionTest.
 */
class CachedSqliteConnectionTest extends AbstractCachedConnectionTest
{
    protected function getDriverName(): string
    {
        return 'array';
    }

    protected function getConnectionName(): string
    {
        return 'sqlite';
    }

    protected function createTemporaryTables(): void
    {
        $this->connection->statement('
            CREATE TABLE IF NOT EXISTS test_cache_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price REAL NOT NULL DEFAULT 0.00,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->connection->statement('
            CREATE TABLE IF NOT EXISTS test_cache_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->connection->insert('INSERT INTO test_cache_products (name, price) VALUES (?, ?)', ['Product 1', 10.00]);
        $this->connection->insert('INSERT INTO test_cache_products (name, price) VALUES (?, ?)', ['Product 2', 20.00]);
        $this->connection->insert('INSERT INTO test_cache_categories (name) VALUES (?)', ['Category 1']);
        $this->connection->insert('INSERT INTO test_cache_categories (name) VALUES (?)', ['Category 2']);
    }

    protected function dropTemporaryTables(): void
    {
        try {
            $this->connection->statement('DROP TABLE IF EXISTS test_cache_products');
            $this->connection->statement('DROP TABLE IF EXISTS test_cache_categories');
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * SQLite does not support TRUNCATE TABLE.
     * Override to use DELETE FROM instead, which still triggers cache invalidation.
     */
    public function test_truncate_invalidates_cache()
    {
        // Arrange - Cache a query
        $this->getCachedConnection()->select('SELECT * FROM test_cache_products LIMIT 1');

        $statsBefore = $this->getCachedConnection()->getCacheStats();
        $this->assertEquals(1, $statsBefore['cached_queries_count']);

        // Act - DELETE all rows (SQLite equivalent of TRUNCATE)
        $this->getCachedConnection()->delete('DELETE FROM test_cache_products');

        $statsAfter = $this->getCachedConnection()->getCacheStats();

        // Assert - Cache should be invalidated
        $this->assertEquals(0, $statsAfter['cached_queries_count']);
    }
}
