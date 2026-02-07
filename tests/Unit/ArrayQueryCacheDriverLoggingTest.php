<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Log;
use webO3\LaravelQueryCache\Drivers\ArrayQueryCacheDriver;
use webO3\LaravelQueryCache\Tests\TestCase;

/**
 * Tests for ArrayQueryCacheDriver logging paths (log_enabled=true).
 *
 * Uses Orchestra TestCase to access the Log facade.
 */
class ArrayQueryCacheDriverLoggingTest extends TestCase
{
    #[Test]
    public function invalidate_tables_with_empty_tables_logs_when_enabled()
    {
        $driver = new ArrayQueryCacheDriver(['max_size' => 1000, 'log_enabled' => true]);
        $driver->flush();
        $driver->put('key1', ['data1'], 'SELECT * FROM users', microtime(true));

        Log::spy();

        $cleared = $driver->invalidateTables([], 'SOME UNKNOWN QUERY');

        $this->assertEquals(1, $cleared);

        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message) {
                return str_contains($message, 'Cleared entire cache');
            })
            ->once();

        $driver->flush();
    }

    #[Test]
    public function invalidate_tables_does_not_log_when_clearing_empty_cache()
    {
        $driver = new ArrayQueryCacheDriver(['max_size' => 1000, 'log_enabled' => true]);
        $driver->flush();

        Log::spy();

        $cleared = $driver->invalidateTables([], 'SOME UNKNOWN QUERY');

        $this->assertEquals(0, $cleared);

        // Should NOT log when clearing an already empty cache
        Log::shouldNotHaveReceived('debug');

        $driver->flush();
    }

    #[Test]
    public function invalidate_tables_logs_when_entries_invalidated()
    {
        $driver = new ArrayQueryCacheDriver(['max_size' => 1000, 'log_enabled' => true]);
        $driver->flush();
        $driver->put('key1', ['data1'], 'SELECT * FROM users', microtime(true));

        Log::spy();

        $driver->invalidateTables(['users'], 'INSERT INTO users VALUES (1)');

        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message) {
                return str_contains($message, 'Invalidated cached queries');
            })
            ->once();

        $driver->flush();
    }

    #[Test]
    public function eviction_logs_when_enabled()
    {
        $driver = new ArrayQueryCacheDriver(['max_size' => 5, 'log_enabled' => true]);
        $driver->flush();

        // Fill to capacity
        for ($i = 0; $i < 5; $i++) {
            $driver->put("key{$i}", ["data{$i}"], "SELECT {$i}", microtime(true));
            usleep(1000);
        }

        Log::spy();

        // Trigger eviction
        $driver->put('key_new', ['new'], 'SELECT new', microtime(true));

        Log::shouldHaveReceived('debug')
            ->withArgs(function ($message) {
                return str_contains($message, 'Evicted LRU entries');
            })
            ->once();

        $driver->flush();
    }
}
