<?php

namespace App\Jobs;

use App\Models\Firm;
use App\Services\HealthCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RunHealthChecksJob — queued wrapper around
 * HealthCheckRegistry::runAll() (via HealthCheckService), intended to
 * be triggered on a schedule (the schedule entry itself is out of
 * phase — no routes/console changes are made here). No real external
 * monitoring provider or real queue worker is required to exercise
 * this in tests (project rule) — HealthCheckRegistry's default checks
 * are already fakeable stubs.
 */
class RunHealthChecksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $firmId = null)
    {
    }

    public function handle(HealthCheckService $healthChecks): void
    {
        $firm = $this->firmId ? Firm::query()->find($this->firmId) : null;

        $healthChecks->runAllAndRecord($firm);
    }
}
