<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use webO3\LaravelQueryCache\Utils\SqlTableExtractor;

/**
 * Unit tests for SqlTableExtractor
 *
 * These tests don't require a database connection - pure unit tests.
 */
class SqlTableExtractorTest extends TestCase
{
    #[Test]
    public function it_extracts_table_from_select()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM users WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_table_from_select_with_backticks()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM `users` WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_tables_from_left_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users LEFT JOIN profiles ON users.id = profiles.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('profiles', $tables);
    }

    #[Test]
    public function it_extracts_tables_from_multiple_joins()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id LEFT JOIN products ON orders.product_id = products.id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
        $this->assertContains('products', $tables);
    }

    #[Test]
    public function it_extracts_table_from_insert()
    {
        $tables = SqlTableExtractor::extract('INSERT INTO users (name) VALUES ("John")');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_update()
    {
        $tables = SqlTableExtractor::extract('UPDATE users SET name = "Jane" WHERE id = 1');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_delete()
    {
        $tables = SqlTableExtractor::extract('DELETE FROM users WHERE id = 1');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_truncate()
    {
        $tables = SqlTableExtractor::extract('TRUNCATE TABLE users');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_truncate_without_table_keyword()
    {
        $tables = SqlTableExtractor::extract('TRUNCATE users');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_alter()
    {
        $tables = SqlTableExtractor::extract('ALTER TABLE users ADD COLUMN email VARCHAR(255)');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_drop()
    {
        $tables = SqlTableExtractor::extract('DROP TABLE users');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_drop_if_exists()
    {
        $tables = SqlTableExtractor::extract('DROP TABLE IF EXISTS users');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_replace()
    {
        $tables = SqlTableExtractor::extract('REPLACE INTO users (id, name) VALUES (1, "John")');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_returns_unique_tables()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users INNER JOIN users ON users.id = users.manager_id'
        );
        // Should have only one entry for 'users' despite appearing multiple times
        $this->assertCount(1, array_filter($tables, fn($t) => $t === 'users'));
    }

    #[Test]
    public function it_is_case_insensitive()
    {
        $tables = SqlTableExtractor::extract('select * from Users where id = 1');
        $this->assertContains('Users', $tables);
    }

    #[Test]
    public function it_handles_tables_with_underscores()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM user_profiles WHERE user_id = 1');
        $this->assertContains('user_profiles', $tables);
    }

    #[Test]
    public function it_handles_tables_with_numbers()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM cache2_entries WHERE key = "test"');
        $this->assertContains('cache2_entries', $tables);
    }

    // ===================================
    // PostgreSQL double-quote quoting
    // ===================================

    #[Test]
    public function it_extracts_table_from_select_with_double_quotes()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM "users" WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_join_with_double_quotes()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM "users" INNER JOIN "orders" ON "users".id = "orders".user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_insert_with_double_quotes()
    {
        $tables = SqlTableExtractor::extract('INSERT INTO "users" (name) VALUES (\'John\')');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_update_with_double_quotes()
    {
        $tables = SqlTableExtractor::extract('UPDATE "users" SET name = \'Jane\' WHERE id = 1');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_delete_with_double_quotes()
    {
        $tables = SqlTableExtractor::extract('DELETE FROM "users" WHERE id = 1');
        $this->assertContains('users', $tables);
    }

    // ===================================
    // SQLite bracket quoting
    // ===================================

    #[Test]
    public function it_extracts_table_from_select_with_brackets()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM [users] WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_join_with_brackets()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM [users] INNER JOIN [orders] ON [users].id = [orders].user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_insert_with_brackets()
    {
        $tables = SqlTableExtractor::extract('INSERT INTO [users] (name) VALUES (\'John\')');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_update_with_brackets()
    {
        $tables = SqlTableExtractor::extract('UPDATE [users] SET name = \'Jane\' WHERE id = 1');
        $this->assertContains('users', $tables);
    }

    #[Test]
    public function it_extracts_table_from_delete_with_brackets()
    {
        $tables = SqlTableExtractor::extract('DELETE FROM [users] WHERE id = 1');
        $this->assertContains('users', $tables);
    }
}
