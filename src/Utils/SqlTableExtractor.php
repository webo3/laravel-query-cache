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
     * or {LEFT|RIGHT|FULL} with optional OUTER). COPY and LOAD DATA targets are
     * handled by separate statement-anchored patterns: putting them here would
     * let an ordinary column named "copy" swallow the following FROM keyword.
     */
    private const KEYWORD = '(?:FROM'
        . '|(?:NATURAL\s++)?+(?:(?:LEFT|RIGHT|FULL)(?:\s++OUTER)?+\s++|INNER\s++|CROSS\s++)?+JOIN'
        . '|STRAIGHT_JOIN'
        . '|UPDATE'
        . '|(?:INSERT|REPLACE|MERGE)\s++INTO'
        . '|DELETE\s++FROM'
        . '|TRUNCATE(?:\s++TABLE)?+'
        . '|ALTER\s++TABLE'
        . '|DROP\s++TABLE(?:\s++IF\s++EXISTS)?+'
        . '|RENAME\s++TABLE'
        . ')\s++';

    /**
     * A single identifier: backtick-, double-quote- or bracket-quoted (any
     * characters, so names with spaces/hyphens are matched) or a bare word.
     */
    private const IDENT = '(?:`[^`]++`|"[^"]++"|\[[^\]]++\]|\w++)';

    /**
     * One table reference: an identifier with an optional schema qualifier.
     */
    private const REF = '(?:' . self::IDENT . '\s*+\.\s*+)?+' . self::IDENT;

    /**
     * Captures the table reference(s) introduced by each keyword as a single
     * group: a leading reference plus any comma-separated siblings (old-style
     * implicit joins). The group is split and normalized in PHP. One pattern
     * handles the single-table, JOIN and comma-list cases uniformly — a former
     * fast/slow split mis-routed comma-joined FROM lists whose first comma fell
     * in the SELECT projection and silently dropped every table after the first.
     */
    private const LIST_PATTERN = '~' . self::KEYWORD
        . '(' . self::REF . '(?:\s*+,\s*+' . self::REF . ')*+)~i';

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
     * Cap on the per-request memo so long-running CLI jobs emitting many
     * distinct SQL shapes can't grow it without bound.
     */
    private const CACHE_MAX_ENTRIES = 5000;

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

        // A single pass with the list-capable pattern handles single-table,
        // JOIN and comma-separated (implicit join) references uniformly. Each
        // captured group is the reference list following one keyword; re-match
        // the reference pattern over it (instead of splitting on commas, which
        // would break quoted identifiers containing a comma) and normalize.
        preg_match_all(self::LIST_PATTERN, $sql, $matches);
        $tables = [];
        foreach ($matches[1] as $list) {
            preg_match_all('~' . self::REF . '~', $list, $refs);
            foreach ($refs[0] as $ref) {
                $tables[] = self::normalizeRef($ref);
            }
        }

        // For RENAME TABLE statements, extract all source/target table pairs
        if (preg_match('/^\s*RENAME\s+TABLE\b/i', $sql)) {
            preg_match_all(self::RENAME_PAIR_PATTERN, $sql, $renameMatches);
            $tables = array_merge($renameMatches[1], $renameMatches[2]);
        }

        // Postgres bulk load/dump: COPY table [(cols)] FROM/TO ...
        if (preg_match('~^\s*COPY\s++(' . self::REF . ')~i', $sql, $copyMatch)) {
            $tables[] = self::normalizeRef($copyMatch[1]);
        }

        // MySQL bulk load: LOAD DATA|XML ... INTO TABLE t
        if (preg_match('/^\s*LOAD\s+(?:DATA|XML)\b/i', $sql)
            && preg_match('~\bINTO\s++TABLE\s++(' . self::REF . ')~i', $sql, $loadMatch)) {
            $tables[] = self::normalizeRef($loadMatch[1]);
        }

        // CTE aliases are not real tables — strip them (case-insensitively, as
        // SQL resolves them) so they don't pollute invalidation keys.
        if (preg_match('/^\s*WITH\b/i', $sql)) {
            preg_match_all(self::CTE_PATTERN, $sql, $cteMatches);
            if (!empty($cteMatches[1])) {
                $cteLower = array_map('strtolower', $cteMatches[1]);
                $tables = array_filter(
                    $tables,
                    static fn ($table) => !in_array(strtolower($table), $cteLower, true)
                );
            }
        }

        $result = array_values(array_unique($tables));

        if (count(self::$cache) >= self::CACHE_MAX_ENTRIES) {
            self::$cache = [];
        }
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
     * an optional schema qualifier (`schema.table` -> `table`). The qualifier
     * is detected on the quoted form first so a quoted name containing a dot
     * ("my.table") is not mis-split.
     */
    private static function normalizeRef(string $ref): string
    {
        $ref = trim($ref);

        if (preg_match('~^' . self::IDENT . '\s*+\.\s*+(' . self::IDENT . ')$~', $ref, $m)) {
            $ref = $m[1];
        }

        return trim($ref, self::STRIP_CHARS);
    }
}
