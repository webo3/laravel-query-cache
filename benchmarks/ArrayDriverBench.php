<?php

namespace webO3\LaravelDbCache\Benchmarks;

use PhpBench\Attributes as Bench;
use webO3\LaravelDbCache\Drivers\ArrayQueryCacheDriver;

/**
 * Benchmarks for the Array cache driver (put, get, invalidation, eviction).
 *
 * Run: vendor/bin/phpbench run benchmarks/ArrayDriverBench.php --report=default
 */
class ArrayDriverBench
{
    private ArrayQueryCacheDriver $driver;

    private array $queries = [
        'SELECT * FROM users WHERE id = ?',
        'SELECT * FROM orders WHERE user_id = ?',
        'SELECT * FROM products WHERE category_id = ?',
    ];

    public function __construct()
    {
        $this->driver = new ArrayQueryCacheDriver(['max_size' => 5000, 'log_enabled' => false]);
    }

    // ----- GET (cache hit) -----

    #[Bench\BeforeMethods('setUpGetBench')]
    #[Bench\Revs(10000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchGet(): void
    {
        $this->driver->get('key_1');
    }

    public function setUpGetBench(): void
    {
        $this->driver = new ArrayQueryCacheDriver(['max_size' => 5000, 'log_enabled' => false]);
        foreach ($this->queries as $i => $query) {
            $this->driver->put('key_' . $i, [['id' => $i, 'name' => "item_{$i}"]], $query, microtime(true));
        }
    }

    // ----- PUT -----

    #[Bench\BeforeMethods('setUpPutBench')]
    #[Bench\Revs(5000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchPut(): void
    {
        static $counter = 0;
        $this->driver->put(
            'bench_' . ($counter++ % 200),
            [['id' => $counter]],
            $this->queries[$counter % 3],
            microtime(true)
        );
    }

    public function setUpPutBench(): void
    {
        $this->driver = new ArrayQueryCacheDriver(['max_size' => 5000, 'log_enabled' => false]);
    }

    // ----- Invalidation at different cache sizes -----

    #[Bench\BeforeMethods('setUpInvalidation50')]
    #[Bench\Revs(2000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchInvalidation50Entries(): void
    {
        $this->driver->invalidateTables(['users'], 'UPDATE users SET x = 1');
    }

    public function setUpInvalidation50(): void
    {
        $this->driver = $this->buildCacheWithEntries(50);
    }

    #[Bench\BeforeMethods('setUpInvalidation200')]
    #[Bench\Revs(2000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchInvalidation200Entries(): void
    {
        $this->driver->invalidateTables(['users'], 'UPDATE users SET x = 1');
    }

    public function setUpInvalidation200(): void
    {
        $this->driver = $this->buildCacheWithEntries(200);
    }

    #[Bench\BeforeMethods('setUpInvalidation500')]
    #[Bench\Revs(2000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchInvalidation500Entries(): void
    {
        $this->driver->invalidateTables(['users'], 'UPDATE users SET x = 1');
    }

    public function setUpInvalidation500(): void
    {
        $this->driver = $this->buildCacheWithEntries(500);
    }

    #[Bench\BeforeMethods('setUpInvalidation1000')]
    #[Bench\Revs(2000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchInvalidation1000Entries(): void
    {
        $this->driver->invalidateTables(['users'], 'UPDATE users SET x = 1');
    }

    public function setUpInvalidation1000(): void
    {
        $this->driver = $this->buildCacheWithEntries(1000);
    }

    // ----- Eviction under pressure -----

    #[Bench\BeforeMethods('setUpEviction')]
    #[Bench\Revs(1000)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchPutWithEviction(): void
    {
        static $counter = 0;
        $this->driver->put(
            'evict_' . ($counter++),
            [['id' => $counter, 'data' => str_repeat('x', 50)]],
            'SELECT * FROM table_' . ($counter % 10) . ' WHERE id = ?',
            microtime(true)
        );
    }

    public function setUpEviction(): void
    {
        $this->driver = new ArrayQueryCacheDriver(['max_size' => 100, 'log_enabled' => false]);
        // Pre-fill to capacity
        for ($i = 0; $i < 100; $i++) {
            $this->driver->put(
                'prefill_' . $i,
                [['id' => $i]],
                'SELECT * FROM table_' . ($i % 10) . ' WHERE id = ?',
                microtime(true)
            );
        }
    }

    // ----- Full request simulation -----

    #[Bench\Revs(500)]
    #[Bench\Iterations(10)]
    #[Bench\Warmup(3)]
    public function benchFullRequestSimulation(): void
    {
        $driver = new ArrayQueryCacheDriver(['max_size' => 1000, 'log_enabled' => false]);

        $selectQueries = [
            'SELECT * FROM users WHERE id = ?',
            'SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC',
            'SELECT p.*, c.name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.active = ?',
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read = ?',
            'SELECT * FROM settings WHERE user_id = ?',
        ];

        // Simulate a single request: 5 SELECTs + occasional mutation
        foreach ($selectQueries as $i => $query) {
            $key = md5($query . json_encode([$i]));
            $cached = $driver->get($key);
            if ($cached === null) {
                $driver->put($key, [['id' => $i, 'data' => 'result']], $query, microtime(true));
            } else {
                $driver->recordHit($key);
            }
        }

        // Mutation
        $driver->invalidateTables(['orders'], 'UPDATE orders SET status = ? WHERE id = ?');
    }

    private function buildCacheWithEntries(int $size): ArrayQueryCacheDriver
    {
        $tables = ['users', 'orders', 'products', 'categories', 'sessions', 'payments', 'logs', 'notifications'];
        $driver = new ArrayQueryCacheDriver(['max_size' => $size + 100, 'log_enabled' => false]);

        for ($i = 0; $i < $size; $i++) {
            $table = $tables[$i % count($tables)];
            $driver->put("k_{$i}", [['id' => $i]], "SELECT * FROM {$table} WHERE id = ?", microtime(true));
        }

        return $driver;
    }
}
