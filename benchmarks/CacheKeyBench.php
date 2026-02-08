<?php

namespace webO3\LaravelDbCache\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Benchmarks for cache key generation (normalization + hashing).
 *
 * Run: vendor/bin/phpbench run benchmarks/CacheKeyBench.php --report=default
 */
class CacheKeyBench
{
    private static array $normalizedCache = [];

    /** @var list<array{query: string, bindings: array}> */
    private array $cases;

    public function __construct()
    {
        $this->cases = [
            ['query' => 'SELECT * FROM users WHERE id = ?', 'bindings' => [42]],
            ['query' => 'SELECT u.*, p.name FROM users u INNER JOIN profiles p ON u.id = p.user_id WHERE u.status = ?', 'bindings' => ['active']],
            ['query' => 'SELECT orders.id, orders.total, customers.name FROM orders LEFT JOIN customers ON orders.customer_id = customers.id WHERE orders.created_at > ?', 'bindings' => ['2024-01-01']],
            ['query' => 'select id, name, email from users where active = ? and role = ? order by created_at desc limit ?', 'bindings' => [1, 'admin', 10]],
            ['query' => 'SELECT COUNT(*) as total FROM products WHERE category_id IN (?, ?, ?, ?) AND price BETWEEN ? AND ?', 'bindings' => [1, 2, 3, 4, 10.00, 99.99]],
        ];
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchCacheKeyGeneration(): void
    {
        foreach ($this->cases as $case) {
            $this->generateCacheKey($case['query'], $case['bindings']);
        }
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchNormalizationOnly(): void
    {
        foreach ($this->cases as $case) {
            $this->normalizeQuery($case['query']);
        }
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchHashingOnly(): void
    {
        // Pre-normalized strings to isolate hashing cost
        $raw = 'SELECT * FROM USERS WHERE ID = ?[42]';
        if (function_exists('hash')) {
            hash('xxh128', $raw);
        } else {
            md5($raw);
        }
    }

    private function generateCacheKey(string $query, array $bindings): string
    {
        $normalized = $this->normalizeQuery($query);
        $raw = $normalized . json_encode($bindings);

        if (function_exists('hash')) {
            return hash('xxh128', $raw);
        }

        return md5($raw);
    }

    private function normalizeQuery(string $query): string
    {
        if (isset(self::$normalizedCache[$query])) {
            return self::$normalizedCache[$query];
        }

        $normalized = preg_replace('/\s+/', ' ', strtoupper(trim($query)));
        self::$normalizedCache[$query] = $normalized;

        return $normalized;
    }
}
