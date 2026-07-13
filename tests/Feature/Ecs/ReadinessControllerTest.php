<?php

namespace Tests\Feature\Ecs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Covers the ECS/ALB readiness probe (app/Http/Controllers/ReadinessController.php),
 * added for container-readiness (docs/ecs/container-architecture.md), and
 * confirms it stays cleanly separate from:
 *  - Laravel's own liveness route (`/up`, bootstrap/app.php) — no dependency
 *    checks, always 200 while the process is alive.
 *  - the pre-existing HealthCheckRegistry/HealthCheckService business
 *    monitoring system (tests/Feature/HealthCheck/) — this endpoint never
 *    persists a health_checks row and never touches tenant context.
 */
class ReadinessControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_endpoint_responds_ok_with_no_dependency_checks(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_readiness_endpoint_reports_ready_when_database_is_reachable(): void
    {
        // The test database connection is always reachable by the time a
        // feature test runs (RefreshDatabase already migrated it), and the
        // default testing config (phpunit.xml) does not set CACHE_STORE,
        // SESSION_DRIVER, or QUEUE_CONNECTION to redis, so only the
        // database check should be present.
        $response = $this->get('/readyz');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ready',
            'checks' => [
                'database' => 'ok',
            ],
        ]);
    }

    public function test_readiness_endpoint_does_not_include_a_redis_check_when_nothing_is_configured_to_use_redis(): void
    {
        $response = $this->get('/readyz');

        $response->assertOk();
        $response->assertJsonMissingPath('checks.redis');
    }

    public function test_readiness_endpoint_reports_not_ready_with_a_generic_error_token_when_the_database_is_unreachable(): void
    {
        // Point the default connection at a database alias that does not
        // exist in config/database.php at all, so DB::connection() throws
        // immediately (no real network wait) — proves the failure path
        // returns 503 and a bare "error" token, never the underlying
        // exception message/connection details.
        //
        // Restored in `finally`: RefreshDatabase rolls back this test's
        // transaction via DB::connection() (the default connection) during
        // its own teardown, so leaving `database.default` pointed at a
        // nonexistent connection past the end of this test breaks that
        // rollback with the same "not configured" exception, attributed
        // back to this test rather than surfacing as an assertion result.
        $originalDefault = config('database.default');
        config(['database.default' => 'nonexistent_connection_for_test']);

        try {
            $response = $this->get('/readyz');

            $response->assertStatus(503);
            $response->assertExactJson([
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'error',
                ],
            ]);
        } finally {
            config(['database.default' => $originalDefault]);
        }
    }

    public function test_readiness_endpoint_checks_redis_when_cache_store_is_redis_and_reports_ok_when_reachable(): void
    {
        config(['cache.default' => 'redis']);

        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn(true);

        $response = $this->get('/readyz');

        $response->assertOk();
        $response->assertJson([
            'status' => 'ready',
            'checks' => [
                'database' => 'ok',
                'redis' => 'ok',
            ],
        ]);
    }

    public function test_readiness_endpoint_reports_not_ready_with_a_generic_error_token_when_redis_is_unreachable(): void
    {
        config(['cache.default' => 'redis']);

        Redis::shouldReceive('connection')->once()->andThrow(
            new \RuntimeException('connection refused to redis://internal-host:6379 password=super-secret')
        );

        $response = $this->get('/readyz');

        $response->assertStatus(503);
        $response->assertExactJson([
            'status' => 'not_ready',
            'checks' => [
                'database' => 'ok',
                'redis' => 'error',
            ],
        ]);

        // The whole point of the generic "error" token: even if the
        // underlying exception message contains a hostname/credential (as
        // simulated above), it must never reach the HTTP response body.
        $response->assertDontSee('super-secret');
        $response->assertDontSee('internal-host');
    }

    public function test_readiness_endpoint_checks_redis_when_session_driver_is_redis(): void
    {
        config(['session.driver' => 'redis']);

        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn(true);

        $this->get('/readyz')->assertOk();
    }

    public function test_readiness_endpoint_checks_redis_when_queue_connection_is_redis(): void
    {
        config(['queue.default' => 'redis']);

        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('ping')->once()->andReturn(true);

        $this->get('/readyz')->assertOk();
    }
}
