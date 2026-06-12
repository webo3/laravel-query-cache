<?php

namespace webO3\LaravelDbCache\Console;

use Illuminate\Console\Command;
use webO3\LaravelDbCache\Contracts\CachedConnection;
use webO3\LaravelDbCache\Support\ConfigList;

/**
 * Reconciles the Redis driver's tracking sets and table indexes with reality:
 * members whose data hash has expired via TTL are removed. Without periodic
 * pruning, read-mostly tables (whose indexes are never cleaned by mutation)
 * accumulate dead references between their TTL refreshes.
 *
 * Schedule it, e.g.: $schedule->command('db-cache:prune')->hourly();
 */
class PruneQueryCacheCommand extends Command
{
    protected $signature = 'db-cache:prune
                            {--connection= : Specific connection to prune (default: all cached connections)}';

    protected $description = 'Remove stale tracking-set and index references from the database query cache';

    public function handle(): int
    {
        $connectionNames = $this->getConnectionNames();

        if (empty($connectionNames)) {
            $this->components->warn('No cached connections configured. Is DB_QUERY_CACHE_ENABLED=true?');
            return self::SUCCESS;
        }

        foreach ($connectionNames as $name) {
            $this->pruneConnection($name);
        }

        return self::SUCCESS;
    }

    private function pruneConnection(string $name): void
    {
        try {
            $connection = app('db')->connection($name);
        } catch (\Exception $e) {
            $this->components->error("Connection [{$name}] not found.");
            return;
        }

        if (!$connection instanceof CachedConnection) {
            $this->components->warn("Connection [{$name}] is not a cached connection, skipping.");
            return;
        }

        $removed = $connection->pruneQueryCache();
        $this->components->info("Pruned {$removed} stale cache reference(s) for connection [{$name}].");
    }

    private function getConnectionNames(): array
    {
        $specific = $this->option('connection');

        if ($specific) {
            return ConfigList::parse($specific);
        }

        if (!config('db-cache.enabled', false)) {
            return [];
        }

        return ConfigList::cachedConnections();
    }
}
