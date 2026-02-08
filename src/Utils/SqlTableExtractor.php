<?php

namespace webO3\LaravelDbCache\Utils;

/**
 * Utility class to extract table names from SQL queries
 */
class SqlTableExtractor
{
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

        $tables = [];

        // Identifier quoting: MySQL uses backticks, PostgreSQL uses double quotes, SQLite uses brackets or double quotes
        $q = '[`"\\[]?';  // optional opening quote
        $qc = '[`"\\]]?'; // optional closing quote

        // Match FROM clause
        if (preg_match_all('/FROM\s+' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Match JOIN clauses (all types)
        if (preg_match_all('/(?:INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|CROSS\s+JOIN|OUTER\s+JOIN|JOIN)\s+' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Match UPDATE
        if (preg_match('/^\s*UPDATE\s+' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Match INSERT INTO
        if (preg_match('/^\s*(?:INSERT|REPLACE)\s+INTO\s+' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Match DELETE FROM
        if (preg_match('/^\s*DELETE\s+FROM\s+' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Match TRUNCATE
        if (preg_match('/^\s*TRUNCATE\s+(?:TABLE\s+)?' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Match ALTER TABLE
        if (preg_match('/^\s*ALTER\s+TABLE\s+' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        // Match DROP TABLE
        if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?' . $q . '([a-zA-Z0-9_]+)' . $qc . '/i', $sql, $matches)) {
            $tables[] = $matches[1];
        }

        $result = array_values(array_unique($tables));
        self::$cache[$sql] = $result;

        return $result;
    }
}
