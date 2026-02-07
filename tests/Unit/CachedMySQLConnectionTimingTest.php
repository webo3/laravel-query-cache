<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use webO3\LaravelQueryCache\Contracts\CachedConnection;
use Illuminate\Database\Connection;
use webO3\LaravelQueryCache\Tests\TestCase;

/**
 * Tests to verify save()/refresh() timing behavior with cache invalidation.
 *
 * Validates that cache invalidation after UPDATE is sufficient,
 * and useWritePdo bypass is not needed (except with read replicas).
 */
class CachedMySQLConnectionTimingTest extends TestCase
{
    protected ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable caching with array driver for testing
        config([
            'query-cache.enabled' => true,
            'query-cache.driver' => 'array',
            'query-cache.ttl' => 300,
            'query-cache.max_size' => 1000,
            'query-cache.log_enabled' => false,
            'database.connections.mysql.query_cache.enabled' => true,
            'database.connections.mysql.query_cache.driver' => 'array',
            'database.connections.mysql.query_cache.ttl' => 300,
            'database.connections.mysql.query_cache.max_size' => 1000,
            'database.connections.mysql.query_cache.log_enabled' => false,
        ]);

        app('db')->purge('mysql');
        $this->connection = app('db')->connection('mysql');

        if (!$this->connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection is not configured');
        }

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
            CREATE TEMPORARY TABLE test_timing_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(50) DEFAULT "draft",
                counter INT DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ');
        $this->connection->insert(
            'INSERT INTO test_timing_items (name, status, counter) VALUES (?, ?, ?)',
            ['Test Item', 'draft', 0]
        );
    }

    protected function dropTemporaryTable(): void
    {
        try {
            $this->connection->statement('DROP TEMPORARY TABLE IF EXISTS test_timing_items');
        } catch (\Exception $e) {
            // Ignore
        }
    }

    // ===================================
    // TESTS: CACHE INVALIDATION AFTER UPDATE
    // ===================================

    /**
     * Verifies that UPDATE properly invalidates the cache.
     */
    public function test_update_invalidates_cache_for_table(): void
    {
        // 1. First SELECT - cached
        $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [1],
            true
        );

        $statsBeforeUpdate = $this->connection->getCacheStats();
        $this->assertEquals(1, $statsBeforeUpdate['cached_queries_count'], 'Should have 1 cached query before UPDATE');

        // 2. UPDATE - should invalidate cache
        $this->connection->update(
            'UPDATE test_timing_items SET status = ? WHERE id = ?',
            ['updated', 1]
        );

        $statsAfterUpdate = $this->connection->getCacheStats();

        // Cache should be invalidated
        $this->assertEquals(
            0,
            $statsAfterUpdate['cached_queries_count'],
            'Cache should be invalidated after UPDATE - no need for useWritePdo bypass'
        );
    }

    /**
     * Simulates the save() + refresh() pattern WITHOUT useWritePdo bypass.
     */
    public function test_save_refresh_pattern_works_with_cache_invalidation_only(): void
    {
        // 1. Initial load (simulates Model::find())
        $initial = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [1],
            true // useReadPdo = true (normal behavior)
        );
        $this->assertEquals('draft', $initial[0]->status);

        // 2. save() - simulates UPDATE
        $this->connection->update(
            'UPDATE test_timing_items SET status = ? WHERE id = ?',
            ['confirmed', 1]
        );

        // 3. refresh() - WITH useReadPdo=true (no bypass)
        $refreshed = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [1],
            true // useReadPdo = true - no bypass!
        );

        $this->assertEquals(
            'confirmed',
            $refreshed[0]->status,
            'refresh() should get fresh data thanks to cache invalidation (no bypass needed)'
        );
    }

    /**
     * Test with multiple rapid save/refresh cycles.
     */
    public function test_rapid_save_refresh_cycles_work_without_bypass(): void
    {
        $statuses = ['pending', 'processing', 'confirmed', 'shipped', 'delivered'];

        foreach ($statuses as $index => $status) {
            // save()
            $this->connection->update(
                'UPDATE test_timing_items SET status = ?, counter = ? WHERE id = ?',
                [$status, $index + 1, 1]
            );

            // refresh() - without bypass
            $result = $this->connection->select(
                'SELECT * FROM test_timing_items WHERE id = ?',
                [1],
                true // useReadPdo = true
            );

            $this->assertEquals(
                $status,
                $result[0]->status,
                "Iteration {$index}: status should be '{$status}'"
            );

            $this->assertEquals(
                $index + 1,
                $result[0]->counter,
                "Iteration {$index}: counter should be " . ($index + 1)
            );
        }
    }

    // ===================================
    // TESTS: POTENTIALLY PROBLEMATIC SCENARIOS
    // ===================================

    /**
     * Test: What happens when reading BEFORE and AFTER an UPDATE?
     */
    public function test_cache_state_before_and_after_update(): void
    {
        $query = 'SELECT * FROM test_timing_items WHERE id = ?';
        $bindings = [1];

        // 1. First SELECT - cache miss, then cached
        $resultBefore = $this->connection->select($query, $bindings, true);
        $this->assertEquals('draft', $resultBefore[0]->status);

        $statsAfterFirstSelect = $this->connection->getCacheStats();
        $this->assertEquals(1, $statsAfterFirstSelect['cached_queries_count']);
        $this->assertEquals(0, $statsAfterFirstSelect['total_cache_hits']);

        // 2. Second SELECT - cache hit
        $resultCached = $this->connection->select($query, $bindings, true);
        $this->assertEquals('draft', $resultCached[0]->status);

        $statsAfterCacheHit = $this->connection->getCacheStats();
        $this->assertEquals(1, $statsAfterCacheHit['cached_queries_count']);
        $this->assertEquals(1, $statsAfterCacheHit['total_cache_hits']);

        // 3. UPDATE - invalidates cache
        $this->connection->update(
            'UPDATE test_timing_items SET status = ? WHERE id = ?',
            ['modified', 1]
        );

        $statsAfterUpdate = $this->connection->getCacheStats();
        $this->assertEquals(0, $statsAfterUpdate['cached_queries_count'], 'Cache invalidated');

        // 4. SELECT after UPDATE - cache miss, fresh data
        $resultAfter = $this->connection->select($query, $bindings, true);
        $this->assertEquals('modified', $resultAfter[0]->status, 'Should get fresh data');

        $statsFinal = $this->connection->getCacheStats();
        $this->assertEquals(1, $statsFinal['cached_queries_count'], 'New cache entry created');
        $this->assertEquals(0, $statsFinal['total_cache_hits'], 'No hits yet for new entry');
    }

    /**
     * Test: Verify no race condition between UPDATE and SELECT.
     */
    public function test_no_race_condition_between_update_and_select(): void
    {
        $iterations = 100;
        $errors = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $expectedStatus = "status_{$i}";

            // UPDATE
            $this->connection->update(
                'UPDATE test_timing_items SET status = ? WHERE id = ?',
                [$expectedStatus, 1]
            );

            // Immediate SELECT (no delay)
            $result = $this->connection->select(
                'SELECT status FROM test_timing_items WHERE id = ?',
                [1],
                true
            );

            if ($result[0]->status !== $expectedStatus) {
                $errors++;
            }
        }

        $this->assertEquals(
            0,
            $errors,
            "Found {$errors}/{$iterations} race conditions - cache invalidation timing issue"
        );
    }

    // ===================================
    // TESTS: WITHOUT useWritePdo BYPASS
    // ===================================

    /**
     * Compare behavior with and without useWritePdo bypass.
     */
    public function test_compare_with_and_without_write_pdo_bypass(): void
    {
        // Scenario 1: WITH useReadPdo=true (no bypass)
        $this->connection->clearQueryCache();

        $this->connection->select('SELECT * FROM test_timing_items WHERE id = ?', [1], true);

        $this->connection->update(
            'UPDATE test_timing_items SET status = ? WHERE id = ?',
            ['value_a', 1]
        );

        $resultWithoutBypass = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [1],
            true // No bypass
        );

        // Scenario 2: WITH useReadPdo=false (with bypass)
        $this->connection->clearQueryCache();

        $this->connection->update(
            'UPDATE test_timing_items SET status = ? WHERE id = ?',
            ['value_b', 1]
        );

        $resultWithBypass = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [1],
            false // With bypass
        );

        // Both should return the correct value
        $this->assertEquals('value_a', $resultWithoutBypass[0]->status);
        $this->assertEquals('value_b', $resultWithBypass[0]->status);
    }

    // ===================================
    // TESTS: BOOKING-SPECIFIC SCENARIOS
    // ===================================

    /**
     * Simulates the booking save/refresh pattern.
     */
    public function test_booking_save_refresh_scenario(): void
    {
        // Create a "booking"
        $this->connection->insert(
            'INSERT INTO test_timing_items (name, status, counter) VALUES (?, ?, ?)',
            ['Booking #123', 'draft', 0]
        );

        $bookingId = $this->connection->getPdo()->lastInsertId();

        // Load the "booking"
        $booking = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [$bookingId],
            true
        );

        $this->assertEquals('draft', $booking[0]->status);

        // Modify and save()
        $this->connection->update(
            'UPDATE test_timing_items SET status = ?, counter = ? WHERE id = ?',
            ['confirmed', 1, $bookingId]
        );

        // refresh() - should get new values
        $refreshed = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [$bookingId],
            true // useReadPdo = true
        );

        $this->assertEquals('confirmed', $refreshed[0]->status, 'Status should be updated');
        $this->assertEquals(1, $refreshed[0]->counter, 'Counter should be updated');
    }

    /**
     * Test INSERT followed by SELECT.
     */
    public function test_insert_then_select_returns_correct_data(): void
    {
        $name = 'New Booking ' . uniqid();

        // INSERT
        $this->connection->insert(
            'INSERT INTO test_timing_items (name, status) VALUES (?, ?)',
            [$name, 'new']
        );

        $newId = $this->connection->getPdo()->lastInsertId();

        // Immediate SELECT
        $result = $this->connection->select(
            'SELECT * FROM test_timing_items WHERE id = ?',
            [$newId],
            true
        );

        $this->assertCount(1, $result, 'Should find the newly inserted record');
        $this->assertEquals($name, $result[0]->name);
        $this->assertEquals('new', $result[0]->status);
    }

    // ===================================
    // DIAGNOSTIC: WHEN IS BYPASS NEEDED?
    // ===================================

    /**
     * Documents when useWritePdo bypass is necessary.
     */
    public function test_document_when_bypass_is_needed(): void
    {
        /*
         * CASE 1: Single Database (no read replica)
         * - UPDATE goes to master
         * - SELECT goes to master (same server)
         * - Cache invalidated after UPDATE
         * - Bypass NOT needed
         *
         * CASE 2: Read Replica Setup
         * - UPDATE goes to master
         * - SELECT normally goes to replica
         * - Replica may have replication lag
         * - useWritePdo() forces SELECT on master
         * - Bypass NEEDED to avoid reading unreplicated data
         *
         * CASE 3: Read Replica + Cache
         * - Same issue as CASE 2
         * - BUT if cache is invalidated on UPDATE, and SELECT
         *   goes to master (useWritePdo), then we read from master
         * - The issue is cache could be filled with replica data
         *   BEFORE invalidation
         */

        // This test verifies our current setup (single DB) works without bypass
        $this->assertTrue(true, 'Documentation test - see comments above');

        // Verify we're not using a read replica
        $readPdo = $this->connection->getReadPdo();
        $writePdo = $this->connection->getPdo();

        // If same PDO object, no read replica
        $sameConnection = ($readPdo === $writePdo);

        if ($sameConnection) {
            $this->addToAssertionCount(1);
            // No read replica = bypass not needed
        } else {
            // Read replica detected = bypass may be needed
            $this->markTestIncomplete(
                'Read replica detected - useWritePdo bypass may be necessary'
            );
        }
    }
}
