<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use webO3\LaravelDbCache\Drivers\RedisQueryCacheDriver;
use Illuminate\Support\Facades\Redis;
use webO3\LaravelDbCache\Tests\TestCase;

/**
 * Tests for tenant-aware Redis cache isolation.
 *
 * Validates:
 * - Redis keys are namespaced by tenant ID
 * - Tracking sets and table indexes are tenant-scoped
 * - L1 cache is flushed on tenant switch
 * - Tenant data is isolated between tenants
 *
 * Note: Tests are skipped if Redis is not available.
 */
class TenantAwareRedisTest extends TestCase
{
    private RedisQueryCacheDriver $driver;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->redis = Redis::connection('db_cache');
            $this->redis->ping();

            $this->driver = new RedisQueryCacheDriver([
                'ttl' => 300,
                'log_enabled' => false,
                'redis_connection' => 'db_cache',
            ]);

            $this->driver->flush();
        } catch (\Exception|\Error $e) {
            $this->markTestSkipped('Redis connection not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->driver)) {
            // Flush default namespace
            $this->driver->flush();

            // Flush tenant-specific namespaces used in tests
            foreach (['tenant_1', 'tenant_2', 'org_100', 'org_200'] as $tenant) {
                $this->driver->setTenantContext($tenant);
                $this->driver->flush();
            }
        }
        parent::tearDown();
    }

    // ===================================
    // TENANT-NAMESPACED REDIS KEYS
    // ===================================

    #[Test]
    public function tenant_context_namespaces_tracking_set()
    {
        $this->driver->setTenantContext('tenant_1');
        $this->driver->put('key1', ['data'], 'SELECT * FROM users', microtime(true));

        // Verify key is tracked in tenant-scoped set
        $keysInTenantSet = $this->redis->smembers($this->setKey('db_cache:t:tenant_1:keys'));
        $this->assertContains('key1', $keysInTenantSet);

        // Verify key is NOT in the default (non-tenant) set
        $keysInDefaultSet = $this->redis->smembers($this->setKey('db_cache:keys'));
        $this->assertNotContains('key1', $keysInDefaultSet);
    }

    #[Test]
    public function tenant_context_namespaces_table_indexes()
    {
        // Use a unique key to avoid collision with other tests
        $uniqueKey = 'tenant_table_idx_' . time();

        $this->driver->setTenantContext('tenant_1');
        $this->driver->put($uniqueKey, ['data'], 'SELECT * FROM users WHERE id = 1', microtime(true));

        // Verify table index is tenant-scoped
        $keysInTenantIndex = $this->redis->smembers($this->setKey('db_cache:t:tenant_1:table:users'));
        $this->assertContains($uniqueKey, $keysInTenantIndex);

        // Verify NOT in the default table index
        $keysInDefaultIndex = $this->redis->smembers($this->setKey('db_cache:table:users'));
        $this->assertNotContains($uniqueKey, $keysInDefaultIndex);
    }

    #[Test]
    public function tenant_context_namespaces_cache_keys()
    {
        $key = 'test_tenant_key_' . time();

        $this->driver->setTenantContext('tenant_1');
        $this->driver->put($key, ['data'], 'SELECT * FROM users', microtime(true));

        // Verify the full Redis key contains tenant prefix
        $fullKey = $this->buildFullKey($key, 'tenant_1');
        $exists = $this->redis->exists($fullKey);
        $this->assertTrue((bool)$exists, "Expected tenant-namespaced key to exist: {$fullKey}");

        // Verify the non-tenant key does NOT exist
        $nonTenantKey = $this->buildFullKey($key);
        $existsNonTenant = $this->redis->exists($nonTenantKey);
        $this->assertFalse((bool)$existsNonTenant, "Non-tenant key should not exist: {$nonTenantKey}");
    }

    // ===================================
    // TENANT ISOLATION
    // ===================================

    #[Test]
    public function different_tenants_have_isolated_caches()
    {
        // Tenant 1 caches data
        $this->driver->setTenantContext('org_100');
        $this->driver->put('shared_key', ['org_100_data'], 'SELECT * FROM users', microtime(true));

        $tenant1Stats = $this->driver->getStats();
        $this->assertEquals(1, $tenant1Stats['cached_queries_count']);

        // Switch to Tenant 2
        $this->driver->setTenantContext('org_200');

        // Tenant 2 should see empty cache
        $tenant2Stats = $this->driver->getStats();
        $this->assertEquals(0, $tenant2Stats['cached_queries_count']);

        // Tenant 2 caches data with same key
        $this->driver->put('shared_key', ['org_200_data'], 'SELECT * FROM users', microtime(true));

        // Switch back to Tenant 1 — should still see its own data
        $this->driver->setTenantContext('org_100');
        $cached = $this->driver->get('shared_key');
        $this->assertNotNull($cached);
        $this->assertEquals(['org_100_data'], $cached['result']);

        // Tenant 2 data should be separate
        $this->driver->setTenantContext('org_200');
        $cached = $this->driver->get('shared_key');
        $this->assertNotNull($cached);
        $this->assertEquals(['org_200_data'], $cached['result']);
    }

    #[Test]
    public function tenant_invalidation_does_not_affect_other_tenants()
    {
        // Tenant 1 caches a query
        $this->driver->setTenantContext('org_100');
        $this->driver->put('key1', ['data'], 'SELECT * FROM users WHERE id = 1', microtime(true));

        // Tenant 2 caches the same query
        $this->driver->setTenantContext('org_200');
        $this->driver->put('key1', ['data'], 'SELECT * FROM users WHERE id = 1', microtime(true));

        // Invalidate users table for Tenant 2
        $this->driver->invalidateTables(['users'], 'INSERT INTO users VALUES (1)');

        // Tenant 2 cache should be empty
        $this->assertEquals(0, $this->driver->getStats()['cached_queries_count']);

        // Tenant 1 cache should be untouched
        $this->driver->setTenantContext('org_100');
        $this->assertEquals(1, $this->driver->getStats()['cached_queries_count']);
        $this->assertNotNull($this->driver->get('key1'));
    }

    #[Test]
    public function tenant_flush_does_not_affect_other_tenants()
    {
        // Tenant 1 caches data
        $this->driver->setTenantContext('org_100');
        $this->driver->put('key1', ['data'], 'SELECT 1', microtime(true));

        // Tenant 2 caches data
        $this->driver->setTenantContext('org_200');
        $this->driver->put('key2', ['data'], 'SELECT 2', microtime(true));

        // Flush Tenant 2
        $this->driver->flush();
        $this->assertEquals(0, $this->driver->getStats()['cached_queries_count']);

        // Tenant 1 should be untouched
        $this->driver->setTenantContext('org_100');
        $this->assertEquals(1, $this->driver->getStats()['cached_queries_count']);
    }

    // ===================================
    // L1 CACHE FLUSH ON TENANT SWITCH
    // ===================================

    #[Test]
    public function l1_cache_is_flushed_on_tenant_switch()
    {
        $this->driver->setTenantContext('tenant_1');
        $this->driver->put('key1', ['tenant_1_data'], 'SELECT * FROM users', microtime(true));

        // key1 is now in L1 cache — get() should return it
        $cached = $this->driver->get('key1');
        $this->assertNotNull($cached);

        // Switch tenant — L1 cache should be flushed
        $this->driver->setTenantContext('tenant_2');

        // key1 should not be in L1 cache, and won't be in Redis under tenant_2 namespace
        $cached = $this->driver->get('key1');
        $this->assertNull($cached);
    }

    #[Test]
    public function same_tenant_does_not_flush_l1_cache()
    {
        $this->driver->setTenantContext('tenant_1');
        $this->driver->put('key1', ['data'], 'SELECT * FROM users', microtime(true));

        // key1 is in L1 cache
        $this->assertNotNull($this->driver->get('key1'));

        // Setting same tenant should NOT flush L1
        $this->driver->setTenantContext('tenant_1');
        $this->assertNotNull($this->driver->get('key1'));
    }

    // ===================================
    // NO TENANT CONTEXT (BACKWARDS COMPAT)
    // ===================================

    #[Test]
    public function works_without_tenant_context()
    {
        // Should work exactly as before when no tenant is set
        $this->driver->put('key1', ['data'], 'SELECT * FROM users', microtime(true));

        $cached = $this->driver->get('key1');
        $this->assertNotNull($cached);
        $this->assertEquals(['data'], $cached['result']);

        // Keys should be in default namespace
        $keysInDefaultSet = $this->redis->smembers($this->setKey('db_cache:keys'));
        $this->assertContains('key1', $keysInDefaultSet);
    }

    /**
     * Build full Redis key with Laravel prefix
     */
    private function setKey(string $logical): string
    {
        $appName = config('app.name', 'laravel');
        $appSlug = \Illuminate\Support\Str::slug($appName, '_');
        $cachePrefix = config('cache.prefix');
        return "{$appSlug}_database_{$cachePrefix}:{$logical}";
    }

    private function buildFullKey(string $key, ?string $tenantId = null): string
    {
        $appName = config('app.name', 'laravel');
        $appSlug = \Illuminate\Support\Str::slug($appName, '_');
        $cachePrefix = config('cache.prefix');

        if ($tenantId !== null) {
            return "{$appSlug}_database_{$cachePrefix}:t:{$tenantId}:{$key}";
        }

        return "{$appSlug}_database_{$cachePrefix}:{$key}";
    }
}
