# Multi-Tenancy

For multi-tenant applications where multiple tenants share the same database connection, the package provides tenant-aware cache isolation via `setTenantContext()`. This namespaces all cache keys by tenant ID, preventing cross-tenant data leakage.

## Usage

Call `setTenantContext()` on the connection after resolving the tenant — typically in your tenant database resolver or middleware:

```php
$connection = DB::connection('org');

if (method_exists($connection, 'setTenantContext')) {
    $connection->setTenantContext((string) $org->id);
}
```

Tenant IDs may contain letters, digits, `.`, `_` and `-`. Anything else (notably `:`) throws an `InvalidArgumentException`, because the ID is embedded in Redis key namespaces and a crafted ID could otherwise overlap another tenant's namespace.

Once set, all cache operations on that connection are scoped to the tenant:

- **Cache keys** are prefixed with the tenant ID (e.g. `app_database_cache:t:42:abc123`)
- **Tracking sets** are tenant-scoped and app-prefixed (e.g. `app_database_cache:db_cache:t:42:keys`)
- **Table indexes** are tenant-scoped and app-prefixed (e.g. `app_database_cache:db_cache:t:42:table:users`)
- **Cache invalidation** only affects the current tenant's cached queries

The context is automatically reset at request/job boundaries (see [long-running workers](configuration.md#long-running-workers-horizon-octane-frankenphp)), so under Octane or queue workers each request must set it again — your tenancy middleware normally does this anyway.

## How it works per driver

| Driver | Behavior |
|---|---|
| **Redis** | Keys, tracking sets, and table indexes are namespaced by tenant. L1 (in-memory) cache is flushed on tenant switch. Each tenant's data is fully isolated in Redis. |
| **Array** | Cache is flushed when switching between tenants (since the static array is shared). Within a single request serving one tenant, caching works normally. |
| **Null** | No-op (accepts the call, does nothing). |

## Connections without tenant context

Connections that don't call `setTenantContext()` (e.g. a shared `main` connection) work exactly as before — no tenant prefix is applied. This allows you to cache both shared and tenant-specific connections simultaneously:

```env
DB_QUERY_CACHE_CONNECTION=main,org
```

The `main` connection caches globally, while the `org` connection caches per-tenant after `setTenantContext()` is called.

## The `tenant_required` fail-safe

If a tenant-spanning connection forgets to call `setTenantContext()`, every tenant would share one un-namespaced cache — a cross-tenant leak. Set `DB_QUERY_CACHE_TENANT_REQUIRED=true` (or `'tenant_required' => true` in `config/db-cache.php`) to make this fail safe: caching is fully bypassed (no reads, writes, or invalidation) until a tenant context has been set, so a missing `setTenantContext()` degrades to "no caching" instead of leaking.

Because the context resets at every request/job boundary, the fail-safe holds on **every** request of a long-running worker's life — not just the first one. Leave it `false` for single-tenant apps.

## Clearing tenant caches

```bash
# Clear everything: the default namespace AND every tenant namespace
php artisan db-cache:clear

# Clear ONLY one tenant's cache
php artisan db-cache:clear --connection=org --tenant=42
```

The driver maintains a registry of every tenant ID that has had a namespace, so an untenanted clear can enumerate and purge them all.
