<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunHealthChecksJob;
use Illuminate\Console\Command;

/**
 * health:checks:run — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"), resolving Open Decision 1 of
 * phase4-architecture-map-operations-governance.md: RunHealthChecksJob
 * existed, fully tested, but had zero live production call sites and
 * was never scheduled anywhere. This command is the thin Artisan
 * wrapper — mirroring every other scheduled command in this
 * application (DispatchOutboxEventsCommand,
 * RefreshIntegrationPlatformOverviewSummariesCommand, etc.) — that
 * bootstrap/app.php's ->withSchedule() now dispatches on a cheap
 * interval (see that file's own docblock for the exact cadence).
 * Dispatches the platform-wide check run only ($firmId = null) — the
 * one firm-specific check type (TenantIsolationAnomalies) is written
 * out-of-band by TenantIsolationAnomalyService::recordAnomaly()
 * itself, not by this scheduled sweep (see HealthCheckService's own
 * docblock).
 */
final class RunHealthChecksCommand extends Command
{
    protected $signature = 'health:checks:run';

    protected $description = 'Dispatches RunHealthChecksJob to run and record the platform-wide HealthCheckRegistry checks.';

    public function handle(): int
    {
        RunHealthChecksJob::dispatch();

        return self::SUCCESS;
    }
}
