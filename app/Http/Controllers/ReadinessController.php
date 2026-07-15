<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * ReadinessController — ECS/ALB readiness probe, distinct from Laravel's
 * built-in liveness route (`/up`, registered in bootstrap/app.php) and from
 * the pre-existing HealthCheckRegistry/HealthCheckService system
 * (app/Services/HealthCheckRegistry.php), which persists a database row on
 * every invocation and is designed for periodic business-health monitoring,
 * not a load balancer polling every 15-30 seconds per task.
 *
 * Deliberately minimal by design (see docs/ecs/container-architecture.md
 * "Health checks"):
 *  - no database writes
 *  - no tenant context established (TenantContextService is never touched)
 *  - no business/tenant data read
 *  - only checks the two things that determine whether THIS task can safely
 *    receive ALB traffic right now: the primary database connection, and
 *    Redis when something is actually configured to depend on it.
 *
 * Never returns exception messages, connection strings, or stack traces in
 * the response body — only a fixed "ok"/"error" token per check, per the
 * mission requirement that health endpoints not leak secrets or diagnostic
 * detail to an unauthenticated network path.
 */
class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
        ];

        if ($this->redisIsRequired()) {
            $checks['redis'] = $this->checkRedis();
        }

        $ready = ! in_array('error', $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function redisIsRequired(): bool
    {
        return config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.default') === 'redis';
    }

    private function checkRedis(): string
    {
        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }
}
