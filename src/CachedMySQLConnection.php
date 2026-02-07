<?php

namespace webO3\LaravelQueryCache;

use webO3\LaravelQueryCache\Concerns\CachesQueries;
use webO3\LaravelQueryCache\Contracts\CachedConnection;
use Illuminate\Database\MySqlConnection;

class CachedMySQLConnection extends MySqlConnection implements CachedConnection
{
    use CachesQueries;

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->bootCachesQueries($config);
    }
}
