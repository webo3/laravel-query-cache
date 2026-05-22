<?php

namespace webO3\LaravelDbCache\Utils;

/**
 * Utility class to extract table names from SQL queries
 */
class SqlTableExtractor
{
    /**
     * Keywords that introduce a table reference. Possessive quantifiers prevent
     * backtracking on the JOIN-modifier prefix (matches NATURAL?, INNER, CROSS,
     * or {LEFT|RIGHT|FULL} with optional OUTER).
     */
    private const KEYWORD = '(?:FROM'
        . '|(?:NATURAL\s++)?+(?:(?:LEFT|RIGHT|FULL)(?:\s++OUTER)?+\s++|INNER\s++|CROSS\s++)?+JOIN'
        . '|STRAIGHT_JOIN'
        . '|UPDATE'
        . '|(?:INSERT|REPLACE)\s++INTO'
        . '|DELETE\s++FROM'
        . '|TRUNCATE(?:\s++TABLE)?+'
        . '|ALTER\s++TABLE'
        . '|DROP\s++TABLE(?:\s++IF\s++EXISTS)?+'
        . '|RENAME\s++TABLE'
        . ')\s++';

    /**
     * Fast path (no comma in the SQL): one keyword introduces one table reference.
     * Captures the trailing identifier directly, ignoring an optional schema prefix
     * and surrounding quotes.
     */
    private const SINGLE_REF_PATTERN = '~' . self::KEYWORD
        . '(?:["`\[]?+\w++["`\]]?+\s*+\.\s*+)?+'
        . '["`\[]?+(\w++)["`\]]?+'
        . '~i';

    /**
     * Slow path (the SQL contains a comma): captures the whole table list after
     * each keyword as a single group; the list is split and normalized in PHP,
     * which is cheaper than per-segment regex calls. Kept distinct from
     * SINGLE_REF_PATTERN because adding the comma-list repetition to the fast
     * path measurably defeats PCRE's first-byte scan optimization.
     */
    private const LIST_PATTERN = '~' . self::KEYWORD
        . '('
        . '(?:["`\[]?+\w++["`\]]?+\s*+\.\s*+)?+["`\[]?+\w++["`\]]?+'
        . '(?:\s*+,\s*+(?:["`\[]?+\w++["`\]]?+\s*+\.\s*+)?+["`\[]?+\w++["`\]]?+)*+'
        . ')~i';

    private const STRIP_CHARS = " \t\n\r`\"[]";

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

        // Decide whether the comma-list pattern is actually needed. The fast
        // single-ref pattern can be used when:
        //   - there is no comma at all, OR
        //   - the first `(` precedes the first `,` (the comma is inside a
        //     column/value list or CTE body, never a table list), OR
        //   - the first `,` precedes any `FROM` keyword (it's in the SELECT
        //     column list, e.g. `SELECT a, b FROM t`).
        // Each check is a plain strpos/stripos — cheaper than the slow path's
        // regex repetition for the common case.
        $useListPath = false;
        $commaPos = strpos($sql, ',');
        if ($commaPos !== false) {
            $parenPos = strpos($sql, '(');
            if ($parenPos === false || $parenPos > $commaPos) {
                $fromPos = stripos($sql, 'FROM');
                $useListPath = $fromPos === false || $commaPos > $fromPos;
            }
        }

        if (!$useListPath) {
            preg_match_all(self::SINGLE_REF_PATTERN, $sql, $matches);
            $tables = $matches[1];
        } else {
            preg_match_all(self::LIST_PATTERN, $sql, $matches);
            $tables = [];
            foreach ($matches[1] as $list) {
                if (strpos($list, ',') === false) {
                    $tables[] = self::normalizeRef($list);
                } else {
                    foreach (explode(',', $list) as $ref) {
                        $tables[] = self::normalizeRef($ref);
                    }
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

    /**
     * Strip surrounding whitespace/quotes from a table reference and discard
     * an optional schema qualifier (`schema.table` -> `table`).
     */
    private static function normalizeRef(string $ref): string
    {
        $ref = trim($ref, self::STRIP_CHARS);
        $dot = strpos($ref, '.');
        if ($dot !== false) {
            $ref = trim(substr($ref, $dot + 1), self::STRIP_CHARS);
        }
        return $ref;
    }
}
