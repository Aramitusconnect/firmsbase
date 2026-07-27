<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SchedulerHealthService;
use Illuminate\Console\Command;

/**
 * scheduler:heartbeat:record — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"), resolving Open Decision 1 of
 * phase4-architecture-map-operations-governance.md: nothing in this
 * application ever called SchedulerHealthService::recordHeartbeat(),
 * so isHealthy()/lastHeartbeatAt() were permanently unable to report
 * anything other than "unknown" in a real environment, regardless of
 * whether Laravel's scheduler was actually running. This command is a
 * thin Artisan wrapper — mirroring every other scheduled command's
 * shape in this application — that bootstrap/app.php's ->withSchedule()
 * now dispatches at a very frequent interval (see that file's own
 * docblock). A plain cache write, no tenant context, no queue
 * dispatch — the heartbeat itself must be recorded synchronously,
 * inline in the scheduler tick, for isHealthy()'s "recent enough"
 * check to mean anything.
 */
final class RecordSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat:record';

    protected $description = 'Records a scheduler heartbeat via SchedulerHealthService::recordHeartbeat().';

    public function handle(SchedulerHealthService $schedulerHealth): int
    {
        $schedulerHealth->recordHeartbeat();

        return self::SUCCESS;
    }
}
