<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use Illuminate\Database\Connection;
use webO3\LaravelDbCache\Contracts\CachedConnection;
use webO3\LaravelDbCache\Tests\TestCase;

/**
 * Regression tests for the audit-remediation fixes that need a live connection:
 * transaction-awareness, locking-read bypass, comment-prefixed mutation
 * detection, binary-binding cache keys and the tenant_required fail-safe.
 *
 * Uses the MySQL connection with the array driver (no Redis dependency).
 */
class CacheCorrectnessFixesTest extends TestCase
{
    protected ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'db-cache.enabled' => true,
            'db-cache.driver' => 'array',
            'db-cache.ttl' => 300,
            'database.connections.mysql.db_cache.enabled' => true,
            'database.connections.mysql.db_cache.driver' => 'array',
            'database.connections.mysql.db_cache.ttl' => 300,
            'database.connections.mysql.db_cache.log_enabled' => true,
            'database.connections.mysql.db_cache.tenant_required' => false,
        ]);

        app('db')->purge('mysql');
        $this->connection = app('db')->connection('mysql');

        if (!$this->connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection is not configured');
        }

        $this->connection->clearQueryCache();
        $this->connection->statement('
            CREATE TEMPORARY TABLE fix_products (
                id INT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0
            )
        ');
        $this->connection->insert('INSERT INTO fix_products (id, name, price) VALUES (?, ?, ?)', [1, 'Widget', 10.00]);
    }

    protected function tearDown(): void
    {
        try {
            $this->connection?->statement('DROP TEMPORARY TABLE IF EXISTS fix_products');
            $this->connection?->clearQueryCache();
        } catch (\Throwable $e) {
            // ignore
        }
        $this->connection = null;
        parent::tearDown();
    }

    private function price(int $id): float
    {
        $rows = $this->connection->select('SELECT price FROM fix_products WHERE id = ?', [$id]);
        return (float) $rows[0]->price;
    }

    // ---- P0 #1: transaction-awareness ----

    public function test_select_inside_transaction_is_not_cached()
    {
        $this->connection->beginTransaction();
        $this->connection->select('SELECT * FROM fix_products');
        $this->connection->select('SELECT * FROM fix_products');
        $this->connection->commit();

        $stats = $this->connection->getCacheStats();
        $this->assertSame(0, $stats['cached_queries_count'], 'SELECTs inside a transaction must not be cached (dirty-read protection)');
    }

    public function test_invalidation_is_applied_after_commit()
    {
        $this->assertSame(10.0, $this->price(1)); // caches 10

        $this->connection->beginTransaction();
        $this->connection->update('UPDATE fix_products SET price = ? WHERE id = ?', [99.00, 1]);
        $this->connection->commit();

        $this->assertSame(99.0, $this->price(1), 'Committing a write must invalidate the cached read');
    }

    public function test_rolled_back_write_does_not_serve_or_purge_incorrectly()
    {
        $this->assertSame(10.0, $this->price(1)); // caches 10

        $this->connection->beginTransaction();
        $this->connection->update('UPDATE fix_products SET price = ? WHERE id = ?', [99.00, 1]);
        $this->connection->rollBack();

        // The write rolled back; the deferred invalidation is dropped, and the
        // (still-valid) cached read is served.
        $this->assertSame(10.0, $this->price(1), 'A rolled-back write must not change served data');
    }

    // ---- P0 #3: locking reads ----

    public function test_for_update_reads_are_not_cached()
    {
        $this->connection->select('SELECT * FROM fix_products WHERE id = 1 FOR UPDATE');
        $this->connection->select('SELECT * FROM fix_products WHERE id = 1 FOR UPDATE');

        $stats = $this->connection->getCacheStats();
        $this->assertSame(0, $stats['cached_queries_count'], 'FOR UPDATE reads must bypass the cache');
    }

    public function test_for_share_reads_are_not_cached()
    {
        $this->connection->select('SELECT * FROM fix_products WHERE id = 1 FOR SHARE');
        $this->connection->select('SELECT * FROM fix_products WHERE id = 1 FOR SHARE');

        $stats = $this->connection->getCacheStats();
        $this->assertSame(0, $stats['cached_queries_count'], 'FOR SHARE reads must bypass the cache');
    }

    // ---- P0 #4: comment-prefixed mutation detection ----

    public function test_comment_prefixed_mutation_invalidates_cache()
    {
        $this->assertSame(10.0, $this->price(1)); // caches 10

        // A leading comment must not hide the UPDATE verb from invalidation.
        $this->connection->update('/* app:trace */ UPDATE fix_products SET price = ? WHERE id = ?', [99.00, 1]);

        $this->assertSame(99.0, $this->price(1), 'A comment-prefixed UPDATE must still invalidate the cache');
    }

    // ---- P0 #6: binary-binding cache keys ----

    public function test_binary_bindings_produce_distinct_cache_keys()
    {
        $ref = new \ReflectionMethod($this->connection, 'generateCacheKey');
        $ref->setAccessible(true);

        $query = 'SELECT * FROM fix_products WHERE name = ?';
        // Both are invalid UTF-8 — json_encode() would return false for each,
        // collapsing them to the SAME key under the old implementation.
        $keyA = $ref->invoke($this->connection, $query, ["\xC3\x28"]);
        $keyB = $ref->invoke($this->connection, $query, ["\xFF\xFE"]);

        $this->assertNotEquals($keyA, $keyB, 'Different binary bindings must yield different cache keys');
    }

    // ---- P0 #5: tenant_required fail-safe ----

    public function test_tenant_required_bypasses_caching_until_context_is_set()
    {
        config(['database.connections.mysql.db_cache.tenant_required' => true]);
        app('db')->purge('mysql');
        $conn = app('db')->connection('mysql');
        $conn->statement('CREATE TEMPORARY TABLE IF NOT EXISTS fix_products (id INT PRIMARY KEY, name VARCHAR(255), price DECIMAL(10,2))');
        $conn->clearQueryCache();

        // No tenant context yet -> caching must be bypassed entirely.
        $conn->select('SELECT * FROM fix_products');
        $conn->select('SELECT * FROM fix_products');
        $this->assertSame(0, $conn->getCacheStats()['cached_queries_count'], 'Caching must be off until a tenant context is set');

        // Once a tenant is set, caching resumes.
        $conn->setTenantContext('tenant_a');
        $conn->select('SELECT * FROM fix_products');
        $this->assertGreaterThanOrEqual(1, $conn->getCacheStats()['cached_queries_count'], 'Caching must resume after setTenantContext()');

        $conn->statement('DROP TEMPORARY TABLE IF EXISTS fix_products');
    }

    // ---- Audit fix #1: tenant context must not survive request boundaries ----

    public function test_tenant_fail_safe_is_restored_at_request_boundaries()
    {
        config(['database.connections.mysql.db_cache.tenant_required' => true]);
        app('db')->purge('mysql');
        $conn = app('db')->connection('mysql');
        $conn->statement('CREATE TEMPORARY TABLE IF NOT EXISTS fix_products (id INT PRIMARY KEY, name VARCHAR(255), price DECIMAL(10,2))');
        $conn->clearQueryCache();

        $conn->setTenantContext('tenant_a');
        $conn->select('SELECT * FROM fix_products');
        $this->assertGreaterThanOrEqual(1, $conn->getCacheStats()['cached_queries_count']);

        // Request boundary: under Octane/queue workers the connection object
        // persists. The tenant context must be dropped so a request that
        // forgets setTenantContext() bypasses caching instead of silently
        // reading the previous tenant's cache.
        $conn->flushRequestCache();

        $conn->select('SELECT * FROM fix_products');
        $conn->select('SELECT * FROM fix_products');
        $this->assertSame(0, $conn->getCacheStats()['cached_queries_count'], 'tenant_required fail-safe must hold again after a request boundary');

        $conn->statement('DROP TEMPORARY TABLE IF EXISTS fix_products');
    }

    // ---- Audit fix #2: mutations inside a cursor loop must invalidate ----

    public function test_mutation_inside_cursor_loop_invalidates_cache()
    {
        $this->assertSame(10.0, $this->price(1)); // caches 10

        foreach ($this->connection->cursor('SELECT * FROM fix_products') as $row) {
            $this->connection->update('UPDATE fix_products SET price = ? WHERE id = ?', [55.00, $row->id]);
        }

        $this->assertSame(55.0, $this->price(1), 'A mutation executed while iterating a cursor must invalidate the cache');
    }

    public function test_selects_during_cursor_iteration_are_cached()
    {
        $statsBefore = $this->connection->getCacheStats()['cached_queries_count'];

        foreach ($this->connection->cursor('SELECT * FROM fix_products') as $row) {
            $this->connection->select('SELECT name FROM fix_products WHERE id = ?', [$row->id]);
        }

        $statsAfter = $this->connection->getCacheStats()['cached_queries_count'];
        $this->assertGreaterThan($statsBefore, $statsAfter, 'Unrelated SELECTs inside a cursor loop should be cached normally');
    }

    // ---- Audit fix #3: pretend() must not poison the cache ----

    public function test_pretend_mode_does_not_poison_cache()
    {
        $this->connection->pretend(function ($conn) {
            $conn->select('SELECT price FROM fix_products WHERE id = ?', [1]);
        });

        $this->assertSame(0, $this->connection->getCacheStats()['cached_queries_count'], 'Pretended queries must not create cache entries');

        // The real query must return real rows, not a cached pretend [].
        $this->assertSame(10.0, $this->price(1));
    }

    // ---- Audit fix #7: nondeterministic SELECTs must not be cached ----

    public function test_nondeterministic_selects_are_not_cached()
    {
        $this->connection->select('SELECT NOW()');
        $this->connection->select('SELECT RAND()');
        $this->connection->select('SELECT UUID()');

        $this->assertSame(0, $this->connection->getCacheStats()['cached_queries_count'], 'NOW()/RAND()/UUID() queries must never be cached');
    }

    // ---- Audit fix #8: SELECT ... INTO must not be cached ----

    public function test_select_into_is_not_cached()
    {
        $this->connection->select('SELECT price INTO @audit_fix_p FROM fix_products WHERE id = 1');

        $this->assertSame(0, $this->connection->getCacheStats()['cached_queries_count'], 'SELECT ... INTO has a side effect and must never be cached');
    }

    // ---- Audit fix #12: rollback must reset pending-invalidation state ----

    public function test_invalidation_still_works_after_a_rollback()
    {
        $this->assertSame(10.0, $this->price(1)); // caches 10

        // A rolled-back write schedules (then discards) an invalidation for
        // fix_products; the bookkeeping must be reset with it.
        $this->connection->beginTransaction();
        $this->connection->update('UPDATE fix_products SET price = ? WHERE id = ?', [77.00, 1]);
        $this->connection->rollBack();

        $this->assertSame(10.0, $this->price(1));

        // A later committed write on the same table must still invalidate —
        // a stale "already scheduled" flag would skip it.
        $this->connection->beginTransaction();
        $this->connection->update('UPDATE fix_products SET price = ? WHERE id = ?', [88.00, 1]);
        $this->connection->commit();

        $this->assertSame(88.0, $this->price(1), 'Invalidation must work in a transaction following a rollback');
    }
}
