<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use Illuminate\Support\Facades\DB;

/**
 * Test cached database connection using PostgreSQL + Array driver
 *
 * Verifies that CachedPostgresConnection works end-to-end with
 * a PostgreSQL database. All test methods are inherited
 * from AbstractCachedConnectionTest.
 *
 * Tests are automatically skipped if PostgreSQL is not available.
 */
class CachedPostgresConnectionTest extends AbstractCachedConnectionTest
{
    protected function getDriverName(): string
    {
        return 'array';
    }

    protected function getConnectionName(): string
    {
        return 'pgsql';
    }

    protected function isDriverAvailable(): bool
    {
        try {
            DB::connection('pgsql')->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function createTemporaryTables(): void
    {
        $this->connection->statement('
            CREATE TEMPORARY TABLE test_cache_products (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->connection->statement('
            CREATE TEMPORARY TABLE test_cache_categories (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
     * PostgreSQL does not support backtick quoting — it uses double quotes.
     * Override to test with PostgreSQL-native quoting.
     */
    public function test_extracts_backtick_quoted_table_names()
    {
        $this->getCachedConnection()->select('SELECT * FROM "test_cache_products" WHERE id = 1');

        $stats = $this->getCachedConnection()->getCacheStats();
        $cachedQuery = $stats['queries'][0];

        $this->assertContains('test_cache_products', $cachedQuery['tables']);
    }
}
