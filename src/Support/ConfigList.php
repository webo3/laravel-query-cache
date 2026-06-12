<?php

namespace webO3\LaravelDbCache\Support;

use Illuminate\Support\Arr;

/**
 * Shared parsing for list-valued config entries that accept either a PHP
 * array or an env-friendly comma-separated string ("main,org").
 *
 * Used by the service provider, the stats middleware and the console
 * commands so every entry point splits, trims and filters identically.
 */
final class ConfigList
{
    /**
     * Normalize a config value into a clean list of non-empty strings.
     */
    public static function parse(mixed $raw): array
    {
        $items = is_string($raw) ? explode(',', $raw) : Arr::wrap($raw);

        $items = array_map(static fn ($item) => trim((string) $item), $items);

        return array_values(array_filter($items, static fn ($item) => $item !== ''));
    }

    /**
     * The connection names query caching is configured for.
     */
    public static function cachedConnections(): array
    {
        return self::parse(config('db-cache.connection', 'mysql'));
    }
}
