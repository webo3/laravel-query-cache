<?php

namespace webO3\LaravelDbCache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use webO3\LaravelDbCache\Contracts\CachedConnection;
use webO3\LaravelDbCache\Middleware\QueryCacheStatsMiddleware;
use webO3\LaravelDbCache\Tests\TestCase;

class QueryCacheStatsMiddlewareTest extends TestCase
{
    private QueryCacheStatsMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new QueryCacheStatsMiddleware();
    }

    #[Test]
    public function handle_passes_request_to_next_middleware()
    {
        config(['db-cache.log_enabled' => false]);

        $request = Request::create('/test', 'GET');
        $expectedResponse = new Response('OK');

        $response = $this->middleware->handle($request, function ($req) use ($expectedResponse) {
            return $expectedResponse;
        });

        $this->assertSame($expectedResponse, $response);
    }

    #[Test]
    public function handle_does_not_log_when_logging_disabled()
    {
        config(['db-cache.log_enabled' => false]);

        Log::shouldReceive('info')->never();

        $request = Request::create('/test', 'GET');
        $response = $this->runMiddleware($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Run the middleware the way the kernel does: handle(), then terminate()
     * after the response has been "sent" — stats are collected in terminate().
     */
    private function runMiddleware(Request $request): Response
    {
        $response = $this->middleware->handle($request, function () {
            return new Response('OK');
        });

        $this->middleware->terminate($request, $response);

        return $response;
    }

    #[Test]
    public function handle_logs_stats_when_enabled_and_queries_cached()
    {
        // Enable caching and logging
        config([
            'db-cache.enabled' => true,
            'db-cache.log_enabled' => true,
            'db-cache.driver' => 'array',
            'db-cache.connection' => 'sqlite',
            'database.connections.sqlite.db_cache.enabled' => true,
            'database.connections.sqlite.db_cache.driver' => 'array',
            'database.connections.sqlite.db_cache.ttl' => 300,
            'database.connections.sqlite.db_cache.max_size' => 1000,
            'database.connections.sqlite.db_cache.log_enabled' => false,
        ]);

        app('db')->purge('sqlite');
        $connection = DB::connection('sqlite');

        // Skip if not a CachedConnection
        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured for sqlite');
        }

        // Create table and run a query to populate cache
        $connection->statement('CREATE TABLE IF NOT EXISTS test_middleware (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_middleware (name) VALUES (?)', ['Test']);
        $connection->select('SELECT * FROM test_middleware');
        $connection->select('SELECT * FROM test_middleware'); // cache hit

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Query Cache Statistics'
                    && $context['connection'] === 'sqlite'
                    && $context['driver'] === 'array'
                    && $context['cached_queries'] >= 1
                    && isset($context['hit_rate']);
            });

        $request = Request::create('/test', 'GET');
        $this->runMiddleware($request);

        // Cleanup
        $connection->statement('DROP TABLE IF EXISTS test_middleware');
    }

    #[Test]
    public function handle_does_not_log_when_no_cached_queries()
    {
        config([
            'db-cache.enabled' => true,
            'db-cache.log_enabled' => true,
            'db-cache.driver' => 'array',
            'db-cache.connection' => 'sqlite',
            'database.connections.sqlite.db_cache.enabled' => true,
            'database.connections.sqlite.db_cache.driver' => 'array',
            'database.connections.sqlite.db_cache.ttl' => 300,
            'database.connections.sqlite.db_cache.max_size' => 1000,
            'database.connections.sqlite.db_cache.log_enabled' => false,
        ]);

        app('db')->purge('sqlite');
        $connection = DB::connection('sqlite');

        if ($connection instanceof CachedConnection) {
            $connection->clearQueryCache();
        }

        Log::shouldReceive('info')->never();

        $request = Request::create('/test', 'GET');
        $this->runMiddleware($request);
    }

    #[Test]
    public function handle_logs_warning_on_exception()
    {
        // Configure with a connection that will fail when accessed
        config([
            'db-cache.log_enabled' => true,
            'db-cache.connection' => 'nonexistent_connection',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to log query cache stats'
                    && isset($context['error']);
            });

        $request = Request::create('/test', 'GET');
        $response = $this->runMiddleware($request);

        // Middleware should not break the response even on error
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function handle_supports_multiple_connections()
    {
        config([
            'db-cache.enabled' => true,
            'db-cache.log_enabled' => true,
            'db-cache.driver' => 'array',
            'db-cache.connection' => ['sqlite'],
            'database.connections.sqlite.db_cache.enabled' => true,
            'database.connections.sqlite.db_cache.driver' => 'array',
            'database.connections.sqlite.db_cache.ttl' => 300,
            'database.connections.sqlite.db_cache.max_size' => 1000,
            'database.connections.sqlite.db_cache.log_enabled' => false,
        ]);

        app('db')->purge('sqlite');
        $connection = DB::connection('sqlite');

        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured');
        }

        $connection->statement('CREATE TABLE IF NOT EXISTS test_multi (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_multi (name) VALUES (?)', ['Test']);
        $connection->select('SELECT * FROM test_multi');

        Log::shouldReceive('info')->once();

        $request = Request::create('/test', 'GET');
        $this->runMiddleware($request);

        $connection->statement('DROP TABLE IF EXISTS test_multi');
    }

    #[Test]
    public function it_parses_comma_separated_connection_lists()
    {
        // Regression: 'a,b' used to be passed verbatim to DB::connection(),
        // which threw on every request and stats were never logged.
        config([
            'db-cache.enabled' => true,
            'db-cache.log_enabled' => true,
            'db-cache.driver' => 'array',
            'db-cache.connection' => 'sqlite, nonexistent_connection',
            'database.connections.sqlite.db_cache.enabled' => true,
            'database.connections.sqlite.db_cache.driver' => 'array',
            'database.connections.sqlite.db_cache.ttl' => 300,
            'database.connections.sqlite.db_cache.max_size' => 1000,
            'database.connections.sqlite.db_cache.log_enabled' => false,
        ]);

        app('db')->purge('sqlite');
        $connection = DB::connection('sqlite');

        if (!$connection instanceof CachedConnection) {
            $this->markTestSkipped('CachedConnection not configured');
        }

        $connection->statement('CREATE TABLE IF NOT EXISTS test_commas (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->insert('INSERT INTO test_commas (name) VALUES (?)', ['Test']);
        $connection->select('SELECT * FROM test_commas');

        // The sqlite stats get logged; the unknown second connection only
        // produces the caught warning, not a fatal.
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();

        $request = Request::create('/test', 'GET');
        $this->runMiddleware($request);

        $connection->statement('DROP TABLE IF EXISTS test_commas');
    }
}
