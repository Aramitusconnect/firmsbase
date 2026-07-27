<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\FleetMigrationRunStatus;
use App\Models\FleetMigrationRun;
use App\Models\PlatformAdmin;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RollbackFleetMigrationRunAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on
 * ViewPlatformFleetMigrationRun. Routes exclusively through
 * FleetMigrationOrchestrationService::rollback() — pure bookkeeping,
 * no real schema reversal is performed (see that service's own
 * docblock).
 */
class RollbackFleetMigrationRunAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'rollbackFleetMigrationRun';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Rollback');
        $this->icon(Heroicon::OutlinedArrowUturnLeft);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Roll back fleet migration run');
        $this->modalDescription('Marks every currently-Applied instance RolledBack. This is pure bookkeeping — no real schema reversal is performed.');

        $this->visible(fn (FleetMigrationRun $record): bool => in_array($record->status, [FleetMigrationRunStatus::Halted, FleetMigrationRunStatus::Completed], true));

        $this->action(function (FleetMigrationRun $record, PlatformStaffAccessPolicyService $accessPolicy, FleetMigrationOrchestrationService $fleetMigrationService): void {
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

            $target = FleetMigrationRun::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That run could not be found.')->danger()->send();

                return;
            }

            try {
                $fleetMigrationService->rollback($target, $actor);
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not roll back this run')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Run rolled back')->success()->send();
        });
    }
}
