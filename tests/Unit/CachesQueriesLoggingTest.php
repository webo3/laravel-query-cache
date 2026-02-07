<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use webO3\LaravelQueryCache\Contracts\CachedConnection;
use webO3\LaravelQueryCache\Tests\TestCase;

/**
 * Tests for CachesQueries logging methods (logCacheHit, logCacheMiss, getCallerInfo)
 *
 * These tests enable log_enabled=true to exercise the logging code paths
 * that are normally skipped when logging is disabled.
 */
class CachesQueriesLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable caching WITH logging
        config([
            'query-cache.enabled' => true,
            'query-cache.driver' => 'array',
            'query-cache.log_enabled' => true,
            'query-cache.connection' => 'sqlite',
            'database.connections.sqlite.query_cache.enabled' => true,
            'database.connections.sqlite.query_cache.driver' => 'array',
            'database.connections.sqlite.query_cache.ttl' => 300,
            'database.connections.sqlite.query_cache.max_size' => 1000,
            'database.connections.sqlite.query_cache.log_enabled' => true,
        ]);

        app('db')->purge('sqlite');
    }

    private function getCachedSqliteConnection()
    {
        $connection = DB::connection('sqlite');
        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured for sqlite');
        }
        $connection->clearQueryCache();
        return $connection;
    }

    #[Test]
    public function log_cache_miss_is_called_on_first_query()
    {
        $connection = $this->getCachedSqliteConnection();
        $connection->statement('CREATE TABLE IF NOT EXISTS test_log (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_log (name) VALUES (?)', ['Test']);
        $connection->clearQueryCache();

        // Use spy to capture all log calls
        Log::spy();

        $connection->select('SELECT * FROM test_log');

        // Assert that a MISS log was recorded
        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message) {
                return str_contains($message, 'MISS');
            })
            ->atLeast()->once();

        $connection->statement('DROP TABLE IF EXISTS test_log');
    }

    #[Test]
    public function log_cache_hit_is_called_on_second_query()
    {
        $connection = $this->getCachedSqliteConnection();
        $connection->statement('CREATE TABLE IF NOT EXISTS test_log_hit (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_log_hit (name) VALUES (?)', ['Test']);
        $connection->clearQueryCache();

        // Use spy to capture all log calls
        Log::spy();

        // First query - cache miss
        $connection->select('SELECT * FROM test_log_hit');
        // Second query - cache hit
        $connection->select('SELECT * FROM test_log_hit');

        // Assert that a HIT log was recorded
        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message) {
                return str_contains($message, 'HIT');
            })
            ->atLeast()->once();

        $connection->statement('DROP TABLE IF EXISTS test_log_hit');
    }

    #[Test]
    public function log_includes_caller_info()
    {
        $connection = $this->getCachedSqliteConnection();
        $connection->statement('CREATE TABLE IF NOT EXISTS test_caller (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_caller (name) VALUES (?)', ['Test']);
        $connection->clearQueryCache();

        Log::spy();

        $connection->select('SELECT * FROM test_caller');

        // Assert that MISS log includes caller info
        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message, $context = []) {
                if (str_contains($message, 'MISS') && isset($context['caller'])) {
                    return is_string($context['caller']) && !empty($context['caller']);
                }
                return false;
            })
            ->atLeast()->once();

        $connection->statement('DROP TABLE IF EXISTS test_caller');
    }

    #[Test]
    public function log_includes_query_and_bindings()
    {
        $connection = $this->getCachedSqliteConnection();
        $connection->statement('CREATE TABLE IF NOT EXISTS test_bindings (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_bindings (name) VALUES (?)', ['Test']);
        $connection->clearQueryCache();

        Log::spy();

        $connection->select('SELECT * FROM test_bindings WHERE id = ?', [1]);

        // Assert that MISS log includes query and bindings
        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message, $context = []) {
                if (str_contains($message, 'MISS') && isset($context['query'])) {
                    return str_contains($context['query'], 'test_bindings')
                        && isset($context['bindings']);
                }
                return false;
            })
            ->atLeast()->once();

        $connection->statement('DROP TABLE IF EXISTS test_bindings');
    }

    #[Test]
    public function no_logging_when_log_disabled()
    {
        // Reconfigure with logging disabled
        config([
            'database.connections.sqlite.query_cache.log_enabled' => false,
        ]);
        app('db')->purge('sqlite');

        $connection = DB::connection('sqlite');
        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured');
        }
        $connection->clearQueryCache();

        $connection->statement('CREATE TABLE IF NOT EXISTS test_nolog (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_nolog (name) VALUES (?)', ['Test']);
        $connection->clearQueryCache();

        Log::spy();

        $connection->select('SELECT * FROM test_nolog');
        $connection->select('SELECT * FROM test_nolog');

        // Should NOT have any MISS or HIT debug logs
        Log::shouldNotHaveReceived('debug');

        $connection->statement('DROP TABLE IF EXISTS test_nolog');
    }

    #[Test]
    public function cache_driver_null_creates_null_driver()
    {
        config([
            'database.connections.sqlite.query_cache.driver' => 'null',
            'database.connections.sqlite.query_cache.enabled' => true,
        ]);
        app('db')->purge('sqlite');

        $connection = DB::connection('sqlite');
        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured');
        }

        $stats = $connection->getCacheStats();
        $this->assertEquals('null', $stats['driver']);
    }

    #[Test]
    public function cache_driver_unknown_falls_back_to_null()
    {
        config([
            'database.connections.sqlite.query_cache.driver' => 'unknown_driver',
            'database.connections.sqlite.query_cache.enabled' => true,
        ]);
        app('db')->purge('sqlite');

        $connection = DB::connection('sqlite');
        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured');
        }

        $stats = $connection->getCacheStats();
        $this->assertEquals('null', $stats['driver']);
    }

    #[Test]
    public function unknown_query_type_is_not_cached()
    {
        $connection = $this->getCachedSqliteConnection();

        // PRAGMA is not a recognized query type — it should pass through without caching
        $connection->select('PRAGMA table_info(sqlite_master)');

        $stats = $connection->getCacheStats();
        // PRAGMA is not a SELECT, so it shouldn't be cached
        // (it actually starts with PRAGMA, not SELECT/INSERT/etc.)
        $this->assertEquals(0, $stats['cached_queries_count']);
    }

    #[Test]
    public function invalidation_with_logging_enabled_logs_invalidation()
    {
        $connection = $this->getCachedSqliteConnection();
        $connection->statement('CREATE TABLE IF NOT EXISTS test_inv_log (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_inv_log (name) VALUES (?)', ['Test']);
        $connection->clearQueryCache();

        // Cache a query
        $connection->select('SELECT * FROM test_inv_log');

        Log::spy();

        // Mutate to trigger invalidation with logging
        $connection->insert('INSERT INTO test_inv_log (name) VALUES (?)', ['Test2']);

        // The ArrayQueryCacheDriver should log invalidation details
        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message) {
                return str_contains($message, 'Invalidated');
            })
            ->atLeast()->once();

        $connection->statement('DROP TABLE IF EXISTS test_inv_log');
    }
}
