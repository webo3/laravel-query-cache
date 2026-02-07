<?php

namespace webO3\LaravelQueryCache\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use webO3\LaravelQueryCache\QueryCacheServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            QueryCacheServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Load .env from package root if available
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
            $dotenv->safeLoad();
        }

        // Configure MySQL connection for testing
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        // Configure PostgreSQL connection for testing
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_PGSQL_HOST', '127.0.0.1'),
            'port' => env('DB_PGSQL_PORT', '5432'),
            'database' => env('DB_PGSQL_DATABASE', 'testing'),
            'username' => env('DB_PGSQL_USERNAME', ''),
            'password' => env('DB_PGSQL_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);

        // Configure SQLite connection for testing
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Configure Redis for testing
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'predis'));
        $app['config']->set('database.redis.query_cache', [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUERY_CACHE_DB', '2'),
            'timeout' => 2.0,
            'read_timeout' => 2.0,
        ]);

        // Configure cache store for query_cache
        $app['config']->set('cache.stores.query_cache', [
            'driver' => 'redis',
            'connection' => 'query_cache',
        ]);
    }
}
