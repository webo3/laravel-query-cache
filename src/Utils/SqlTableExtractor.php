<?php

namespace webO3\LaravelDbCache\Utils;

/**
 * Utility class to extract table names from SQL queries
 */
class SqlTableExtractor
{
    /**
     * Matches a SQL keyword that introduces a table reference, then captures
     * the full table list (supports schema-qualified names and comma lists).
     */
    private const PATTERN = '/(?:'
        . 'FROM'
        . '|(?:(?:NATURAL|LEFT|RIGHT|FULL|INNER|OUTER|CROSS)\s+){0,3}JOIN'
        . '|STRAIGHT_JOIN'
        . '|UPDATE'
        . '|(?:INSERT|REPLACE)\s+INTO'
        . '|DELETE\s+FROM'
        . '|TRUNCATE(?:\s+TABLE)?'
        . '|ALTER\s+TABLE'
        . '|DROP\s+TABLE(?:\s+IF\s+EXISTS)?'
        . '|RENAME\s+TABLE'
        . ')\s+('
        . '(?:[`"\[]?[a-zA-Z0-9_]+[`"\]]?\s*\.\s*)?[`"\[]?[a-zA-Z0-9_]+[`"\]]?'
        . '(?:\s*,\s*(?:[`"\[]?[a-zA-Z0-9_]+[`"\]]?\s*\.\s*)?[`"\[]?[a-zA-Z0-9_]+[`"\]]?)*'
        . ')/i';

    /**
     * Extracts the trailing identifier from an optionally schema-qualified reference
     * (e.g. `mydb.users` -> `users`, `"schema"."users"` -> `users`).
     */
    private const TABLE_REF_PATTERN = '/(?:[`"\[]?[a-zA-Z0-9_]+[`"\]]?\s*\.\s*)?[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/';

    /**
     * Pattern to extract all table pairs from RENAME TABLE statements.
     * Matches each "source TO target" pair, including comma-separated ones.
     */
    private const RENAME_PAIR_PATTERN = '/[`"\[]?([a-zA-Z0-9_]+)[`"\]]?\s+TO\s+[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i';

    /**
     * Captures CTE aliases declared after WITH/comma so they can be filtered
     * out of the table list (`name AS (...)` with optional column list).
     */
    private const CTE_PATTERN = '/(?:^\s*WITH(?:\s+RECURSIVE)?|,)\s+[`"\[]?([a-zA-Z0-9_]+)[`"\]]?(?:\s*\([^)]*\))?\s+AS\s*\(/i';

    /**
     * Per-request cache of extracted tables keyed by SQL string
     */
    private static array $cache = [];

    /**
     * Extract table names from SQL query
     *
     * Results are cached per-request so repeated extraction of the same
     * query (e.g. during invalidation + stats) is free.
     *
     * @param string $sql
     * @return array
     */
    public static function extract(string $sql): array
    {
        if (isset(self::$cache[$sql])) {
            return self::$cache[$sql];
        }

        preg_match_all(self::PATTERN, $sql, $matches);

        $tables = [];
        foreach ($matches[1] as $tableList) {
            foreach (explode(',', $tableList) as $tableRef) {
                if (preg_match(self::TABLE_REF_PATTERN, $tableRef, $m)) {
                    $tables[] = $m[1];
                }
            }
        }

        // For RENAME TABLE statements, extract all source/target table pairs
        if (preg_match('/^\s*RENAME\s+TABLE\b/i', $sql)) {
            preg_match_all(self::RENAME_PAIR_PATTERN, $sql, $renameMatches);
            $tables = array_merge($renameMatches[1], $renameMatches[2]);
        }

        // CTE aliases are not real tables — strip them so they don't pollute invalidation keys
        if (preg_match('/^\s*WITH\b/i', $sql)) {
            preg_match_all(self::CTE_PATTERN, $sql, $cteMatches);
            if (!empty($cteMatches[1])) {
                $tables = array_diff($tables, $cteMatches[1]);
            }
        }

        $result = array_values(array_unique($tables));
        self::$cache[$sql] = $result;

        return $result;
    }

    /**
     * Clear the per-request cache (useful for testing/benchmarking)
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
