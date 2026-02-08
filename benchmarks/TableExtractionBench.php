<?php

namespace webO3\LaravelDbCache\Benchmarks;

use PhpBench\Attributes as Bench;
use webO3\LaravelDbCache\Utils\SqlTableExtractor;

/**
 * Benchmarks for SQL table extraction.
 *
 * Run: vendor/bin/phpbench run benchmarks/TableExtractionBench.php --report=default
 */
class TableExtractionBench
{
    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchSimpleSelect(): void
    {
        SqlTableExtractor::extract('SELECT * FROM users WHERE id = ?');
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchMultiJoin(): void
    {
        SqlTableExtractor::extract(
            'SELECT o.*, c.name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id RIGHT JOIN shipments s ON o.id = s.order_id'
        );
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchInsert(): void
    {
        SqlTableExtractor::extract('INSERT INTO orders (user_id, total) VALUES (?, ?)');
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchUpdate(): void
    {
        SqlTableExtractor::extract('UPDATE users SET status = ? WHERE id = ?');
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchDelete(): void
    {
        SqlTableExtractor::extract('DELETE FROM sessions WHERE expired_at < ?');
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchQuotedTables(): void
    {
        SqlTableExtractor::extract(
            'SELECT * FROM `quoted_table` INNER JOIN `another_table` ON `quoted_table`.id = `another_table`.ref_id'
        );
    }
}
