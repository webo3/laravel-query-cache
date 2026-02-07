<?php

namespace webO3\LaravelQueryCache\Utils;

/**
 * Utility class to extract table names from SQL queries
 */
class SqlTableExtractor
{
    /**
     * Extract table names from SQL query
     *
     * @param string $sql
     * @return array
     */
    public static function extract(string $sql): array
    {
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

        return array_unique($tables);
    }
}
