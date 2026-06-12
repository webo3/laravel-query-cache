# How It Works

## The caching pipeline

1. **SELECT queries** are intercepted at the connection level. The query + bindings are normalized and hashed (SHA-256, namespaced by connection) to produce a cache key. If a cached result exists, it's returned immediately without hitting the database.

2. **Mutation queries** (`INSERT`, `UPDATE`, `DELETE`, `TRUNCATE`, `ALTER`, `DROP`, `CREATE`, `REPLACE`, `LOAD DATA`, `COPY`, …) trigger automatic invalidation. The package extracts table names from the SQL and invalidates all cached queries that reference those tables. Inside a transaction, invalidations are aggregated per table and deferred to `COMMIT`.

3. **Table extraction** uses regex-based SQL parsing to identify which tables a query reads from or writes to. It supports `FROM`, all `JOIN` variants, comma-joined lists, `INSERT/REPLACE/MERGE INTO`, `UPDATE`, `DELETE FROM`, `RENAME TABLE`, subqueries, CTEs (aliases are filtered out), and quoted identifiers containing special characters.

4. **Query normalization** ensures that queries differing only in whitespace produce the same cache key (e.g. `SELECT * FROM users` and `SELECT *  FROM  users` hit the same entry). Case is intentionally **not** folded: on case-sensitive systems (MySQL with `lower_case_table_names=0`) `users` and `Users` are different tables, and folding case would alias them to one key — a collision serving the wrong table's rows.

## What is never cached

| Bypass | Why |
|---|---|
| `SELECT`s inside an open transaction | They could observe this connection's own uncommitted rows; caching them would leak dirty reads that survive a rollback. |
| Locking reads (`FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE`) | A cache hit would skip the row lock the statement exists to acquire. |
| Nondeterministic queries (`NOW()`, `RAND()`, `UUID()`, `nextval()`, `LAST_INSERT_ID()`, `CURRENT_TIMESTAMP`, …) | Serving a cached value changes their semantics — `nextval()` would hand the same sequence value to multiple callers. |
| `SELECT … INTO` (table / OUTFILE / @variable) | The statement has a side effect a cache hit would silently skip. |
| Cursor queries (`DB::cursor()`) | Designed for memory-efficient streaming; caching would defeat the purpose. Queries executed *inside* a cursor loop are cached and invalidated normally. |
| System/metadata catalogs (`information_schema`, `pg_catalog`, `sqlite_master`, …) | Not tracked for invalidation. |
| Excluded identifiers (views) | A view's base-table mutations can't be matched to the cached view query — see [configuration.md](configuration.md#excluding-views-and-other-identifiers). |
| Queries during `pretend()` | Pretended statements don't execute; caching their empty results would poison the cache for real callers. |

## Consistency model

This is a read-through (cache-aside) cache, so reads are **eventually consistent**, not strongly consistent:

- A cached result can be at most **`ttl` seconds** stale. Keep `DB_QUERY_CACHE_TTL` short for write-heavy tables.
- **Transaction-aware:** a write's invalidation is **deferred to commit** — it fires after `COMMIT` and is dropped on `ROLLBACK`. Invalidations are aggregated per distinct table, so a bulk import of thousands of statements schedules one invalidation per table, not one per statement.
- Under concurrency, a narrow **lost-invalidation window** remains: if a reader reads the old row, a writer commits + invalidates, and only then does the reader write its (now-stale) entry, that entry lives until its TTL. There is **no single-flight lock** on a cache miss, so a very hot key can briefly stampede the database when it expires — TTL jitter (±10%, applied automatically) spreads synchronized expiries out.
- Queries the table extractor can't attribute to a table (e.g. `CALL` a stored procedure) conservatively **flush the entire cache** for that connection on mutation, which is safe but coarse.
- **Cached payloads are HMAC-signed with `APP_KEY`** (redis driver): a payload that fails authentication is treated as a miss, so unauthenticated data is never unserialized. Rotating `APP_KEY` therefore invalidates the whole cache once (self-healing misses), as does upgrading from a pre-HMAC version of this package.

If you require read-your-writes within a request, read through the same connection inside the transaction (bypassed) or call `DB::connection(...)->clearQueryCache()` after the critical write.

## Redis outage behavior

Any Redis connection/timeout error opens a **circuit breaker**: the driver skips Redis entirely (reads, writes, and L2 invalidation) for 5 seconds, logging once per window instead of once per query. During the outage the per-request L1 cache keeps serving — and keeps honoring table-based invalidation locally — so a Redis blip degrades to "per-request caching" rather than per-query socket timeouts. See [redis.md](redis.md#resilience).
