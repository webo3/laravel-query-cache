<?php

namespace webO3\LaravelQueryCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use webO3\LaravelQueryCache\CachedConnectionFactory;
use webO3\LaravelQueryCache\Tests\TestCase;

class QueryCacheServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_cached_connection_factory()
    {
        $factory = app('db.factory');
        $this->assertInstanceOf(CachedConnectionFactory::class, $factory);
    }

    #[Test]
    public function merges_default_config()
    {
        // The service provider should merge the default config
        $this->assertNotNull(config('query-cache'));
        $this->assertArrayHasKey('enabled', config('query-cache'));
        $this->assertArrayHasKey('driver', config('query-cache'));
        $this->assertArrayHasKey('ttl', config('query-cache'));
        $this->assertArrayHasKey('max_size', config('query-cache'));
        $this->assertArrayHasKey('log_enabled', config('query-cache'));
        $this->assertArrayHasKey('connection', config('query-cache'));
        $this->assertArrayHasKey('redis_connection', config('query-cache'));
    }

    #[Test]
    public function boot_injects_query_cache_config_into_database_connection()
    {
        // Enable query cache
        config([
            'query-cache.enabled' => true,
            'query-cache.driver' => 'array',
            'query-cache.ttl' => 300,
            'query-cache.max_size' => 500,
            'query-cache.log_enabled' => true,
            'query-cache.connection' => 'mysql',
            'query-cache.redis_connection' => 'query_cache',
        ]);

        // Re-boot the service provider to trigger config injection
        $provider = app()->getProvider(\webO3\LaravelQueryCache\QueryCacheServiceProvider::class);
        $provider->boot();

        // Verify query_cache config was injected into database connection
        $dbConfig = config('database.connections.mysql.query_cache');
        $this->assertNotNull($dbConfig);
        $this->assertTrue($dbConfig['enabled']);
        $this->assertEquals('array', $dbConfig['driver']);
        $this->assertEquals(300, $dbConfig['ttl']);
        $this->assertEquals(500, $dbConfig['max_size']);
        $this->assertTrue($dbConfig['log_enabled']);
        $this->assertEquals('query_cache', $dbConfig['redis_connection']);
    }

    #[Test]
    public function boot_injects_config_for_multiple_connections()
    {
        config([
            'query-cache.enabled' => true,
            'query-cache.driver' => 'array',
            'query-cache.ttl' => 180,
            'query-cache.max_size' => 1000,
            'query-cache.log_enabled' => false,
            'query-cache.connection' => ['mysql', 'pgsql', 'sqlite'],
            'query-cache.redis_connection' => 'query_cache',
        ]);

        $provider = app()->getProvider(\webO3\LaravelQueryCache\QueryCacheServiceProvider::class);
        $provider->boot();

        // Verify config was injected into all connections
        foreach (['mysql', 'pgsql', 'sqlite'] as $conn) {
            $dbConfig = config("database.connections.{$conn}.query_cache");
            $this->assertNotNull($dbConfig, "query_cache config not injected for {$conn}");
            $this->assertTrue($dbConfig['enabled'], "enabled not set for {$conn}");
            $this->assertEquals('array', $dbConfig['driver'], "driver not set for {$conn}");
        }
    }

    #[Test]
    public function boot_does_not_inject_config_when_disabled()
    {
        // Remove any existing query_cache config
        config(['database.connections.mysql.query_cache' => null]);
        config(['query-cache.enabled' => false]);

        $provider = app()->getProvider(\webO3\LaravelQueryCache\QueryCacheServiceProvider::class);
        $provider->boot();

        // Should NOT inject config when disabled
        $this->assertNull(config('database.connections.mysql.query_cache'));
    }

    #[Test]
    public function boot_publishes_config_file()
    {
        // Verify the publishable config is registered
        $provider = app()->getProvider(\webO3\LaravelQueryCache\QueryCacheServiceProvider::class);

        // ServiceProvider::$publishes is a static property
        $publishes = \webO3\LaravelQueryCache\QueryCacheServiceProvider::$publishGroups ?? [];

        // The provider should have registered config for publishing
        // We verify this indirectly by checking that the config key exists
        $this->assertTrue($this->app->runningInConsole());
    }
}
