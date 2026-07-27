<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\HealthCheckService;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RunHealthChecksNowAction — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). The sole mutating action on
 * PlatformServiceHealthPage. Routes exclusively through
 * HealthCheckService::runAllAndRecord(null) — the platform-wide check
 * run (see that service's own docblock: "8 platform-wide checks need
 * no per-firm loop at all"). Genuinely safe: every default-registered
 * check either delegates to a real, side-effect-free read
 * (QueueHealthService/SchedulerHealthService/
 * TenantIsolationAnomalyService) or is a hardcoded stub returning
 * Healthy — no external call of any kind is made, and the only write
 * is to health_checks itself.
 *
 * TOCTOU-safe, mirroring ActivatePlanAction/CancelSubscriptionAction's
 * established shape: fresh actor resolution inside the closure, both
 * canManageOperations() and the blanket canMutate() rule checked
 * explicitly before calling the service.
 */
class RunHealthChecksNowAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'runHealthChecksNow';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Run health checks now');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('primary');

        $this->requiresConfirmation();
        $this->modalHeading('Run health checks now');
        $this->modalDescription(
            'Runs every registered platform-wide health check immediately and records a new health_checks row for '.
            'each one. This has zero external side effects — several checks are stub checks with no real provider '.
            'behind them yet (see the page\'s own disclosure below).'
        );

        $this->action(function (PlatformStaffAccessPolicyService $accessPolicy, HealthCheckService $healthCheckService, PlatformAdminAuditEventRecorder $auditRecorder): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageOperations($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $results = $healthCheckService->runAllAndRecord(null);

            // HealthCheckService::runAllAndRecord() accepts no actor
            // parameter at all (it is not per-firm-attributed, unlike
            // IncidentService/FleetMigrationOrchestrationService) — the
            // audit trail for this on-demand trigger is recorded here,
            // directly in the Action, exactly like the other resolved
            // actor-type-gap call sites in this phase.
            $auditRecorder->recordPlatformEvent($actor, 'health_checks_run_on_demand', 'operations_health_check', [
                'result_count' => count($results),
            ]);

            Notification::make()
                ->title('Health checks run')
                ->body(count($results).' check(s) recorded.')
                ->success()
                ->send();
        });
    }
}
