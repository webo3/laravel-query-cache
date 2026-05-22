<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use webO3\LaravelDbCache\Utils\SqlTableExtractor;

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

    // ===================================
    // RENAME TABLE support
    // ===================================

    #[Test]
    public function it_extracts_both_tables_from_rename()
    {
        $tables = SqlTableExtractor::extract('RENAME TABLE users TO customers');
        $this->assertContains('users', $tables);
        $this->assertContains('customers', $tables);
        $this->assertCount(2, $tables);
    }

    #[Test]
    public function it_extracts_tables_from_rename_with_backticks()
    {
        $tables = SqlTableExtractor::extract('RENAME TABLE `users` TO `customers`');
        $this->assertContains('users', $tables);
        $this->assertContains('customers', $tables);
    }

    #[Test]
    public function it_extracts_all_tables_from_multi_rename()
    {
        $tables = SqlTableExtractor::extract(
            'RENAME TABLE users TO customers, orders TO purchases'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('customers', $tables);
        $this->assertContains('orders', $tables);
        $this->assertContains('purchases', $tables);
        $this->assertCount(4, $tables);
    }

    // ===================================
    // Schema/database-qualified table names
    // ===================================

    #[Test]
    public function it_extracts_table_from_schema_qualified_select()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM mydb.users WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_table_from_schema_qualified_select_with_double_quotes()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM "schema"."users" WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_table_from_schema_qualified_select_with_backticks()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM `mydb`.`users` WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_schema_qualified_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM mydb.users INNER JOIN otherdb.orders ON mydb.users.id = otherdb.orders.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
        $this->assertNotContains('mydb', $tables);
        $this->assertNotContains('otherdb', $tables);
    }

    #[Test]
    public function it_extracts_table_from_schema_qualified_update()
    {
        $tables = SqlTableExtractor::extract('UPDATE mydb.users SET name = "Jane" WHERE id = 1');
        $this->assertContains('users', $tables);
        $this->assertNotContains('mydb', $tables);
    }

    #[Test]
    public function it_extracts_table_from_schema_qualified_insert()
    {
        $tables = SqlTableExtractor::extract('INSERT INTO mydb.users (name) VALUES ("John")');
        $this->assertContains('users', $tables);
        $this->assertNotContains('mydb', $tables);
    }

    #[Test]
    public function it_extracts_table_from_schema_qualified_delete()
    {
        $tables = SqlTableExtractor::extract('DELETE FROM mydb.users WHERE id = 1');
        $this->assertContains('users', $tables);
        $this->assertNotContains('mydb', $tables);
    }

    // ===================================
    // Comma-separated (implicit join) tables
    // ===================================

    #[Test]
    public function it_extracts_all_comma_separated_tables_from_select()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users, posts WHERE users.id = posts.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
    }

    #[Test]
    public function it_extracts_all_three_comma_separated_tables()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users, posts, comments WHERE users.id = posts.user_id AND posts.id = comments.post_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
        $this->assertContains('comments', $tables);
    }

    #[Test]
    public function it_extracts_schema_qualified_comma_separated_tables()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM mydb.users, otherdb.orders');
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
        $this->assertNotContains('mydb', $tables);
        $this->assertNotContains('otherdb', $tables);
    }

    #[Test]
    public function it_extracts_comma_separated_tables_with_no_whitespace()
    {
        $tables = SqlTableExtractor::extract('SELECT * FROM users,posts,comments');
        $this->assertContains('users', $tables);
        $this->assertContains('posts', $tables);
        $this->assertContains('comments', $tables);
    }

    #[Test]
    public function it_does_not_treat_insert_column_list_as_additional_tables()
    {
        $tables = SqlTableExtractor::extract('INSERT INTO users (name, email, age) VALUES ("a", "b", 1)');
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_does_not_treat_update_set_list_as_additional_tables()
    {
        $tables = SqlTableExtractor::extract('UPDATE users SET name = "x", email = "y" WHERE id = 1');
        $this->assertEquals(['users'], $tables);
    }

    // ===================================
    // Subqueries (regression + coverage)
    // ===================================

    #[Test]
    public function it_extracts_table_from_where_subquery()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_exists_subquery()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users u WHERE EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_derived_table_subquery()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM (SELECT * FROM users) AS u WHERE u.id = 1'
        );
        $this->assertEquals(['users'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_nested_subqueries()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE product_id IN (SELECT id FROM products))'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
        $this->assertContains('products', $tables);
    }

    #[Test]
    public function it_extracts_table_from_insert_with_select_subquery()
    {
        $tables = SqlTableExtractor::extract('INSERT INTO archive SELECT * FROM users WHERE deleted_at IS NOT NULL');
        $this->assertContains('archive', $tables);
        $this->assertContains('users', $tables);
    }

    // ===================================
    // CTE / WITH clause filtering
    // ===================================

    #[Test]
    public function it_filters_out_cte_alias_from_results()
    {
        $tables = SqlTableExtractor::extract(
            'WITH active AS (SELECT * FROM users WHERE active = 1) SELECT * FROM active'
        );
        $this->assertContains('users', $tables);
        $this->assertNotContains('active', $tables);
    }

    #[Test]
    public function it_filters_out_recursive_cte_alias()
    {
        $tables = SqlTableExtractor::extract(
            'WITH RECURSIVE tree AS (SELECT id FROM nodes UNION ALL SELECT n.id FROM nodes n JOIN tree ON n.parent_id = tree.id) SELECT * FROM tree'
        );
        $this->assertContains('nodes', $tables);
        $this->assertNotContains('tree', $tables);
    }

    #[Test]
    public function it_filters_out_multiple_cte_aliases()
    {
        $tables = SqlTableExtractor::extract(
            'WITH a AS (SELECT * FROM t1), b AS (SELECT * FROM t2) SELECT * FROM a JOIN b ON a.id = b.a_id'
        );
        $this->assertContains('t1', $tables);
        $this->assertContains('t2', $tables);
        $this->assertNotContains('a', $tables);
        $this->assertNotContains('b', $tables);
    }

    #[Test]
    public function it_filters_out_cte_alias_with_column_list()
    {
        $tables = SqlTableExtractor::extract(
            'WITH summary (total, name) AS (SELECT SUM(amount), name FROM orders GROUP BY name) SELECT * FROM summary'
        );
        $this->assertContains('orders', $tables);
        $this->assertNotContains('summary', $tables);
    }

    // ===================================
    // Non-standard JOIN syntax
    // ===================================

    #[Test]
    public function it_extracts_table_from_full_outer_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users FULL OUTER JOIN orders ON users.id = orders.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_left_outer_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users LEFT OUTER JOIN orders ON users.id = orders.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_natural_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users NATURAL JOIN orders'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_natural_left_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users NATURAL LEFT JOIN orders'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    #[Test]
    public function it_extracts_table_from_straight_join()
    {
        $tables = SqlTableExtractor::extract(
            'SELECT * FROM users STRAIGHT_JOIN orders ON users.id = orders.user_id'
        );
        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }
}
