<?php

namespace webO3\LaravelQueryCache;

use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Custom Connection Factory that supports query caching
 *
 * This factory creates cached connection instances when query caching
 * is enabled in the database configuration. Supports MySQL, PostgreSQL,
 * and SQLite drivers.
 */
class CachedConnectionFactory extends ConnectionFactory
{
    /**
     * Create a new connection instance
     *
     * @param  string  $driver
     * @param  \PDO|\Closure  $connection
     * @param  string  $database
     * @param  string  $prefix
     * @param  array  $config
     * @return \Illuminate\Database\Connection
     */
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        if ($config['query_cache']['enabled'] ?? false) {
            return match ($driver) {
                'mysql' => new CachedMySQLConnection($connection, $database, $prefix, $config),
                'pgsql' => new CachedPostgresConnection($connection, $database, $prefix, $config),
                'sqlite' => new CachedSQLiteConnection($connection, $database, $prefix, $config),
                default => parent::createConnection($driver, $connection, $database, $prefix, $config),
            };
        }

        return parent::createConnection($driver, $connection, $database, $prefix, $config);
    }
}
