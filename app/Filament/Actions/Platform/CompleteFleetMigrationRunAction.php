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
 * CompleteFleetMigrationRunAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on
 * ViewPlatformFleetMigrationRun. Routes exclusively through
 * FleetMigrationOrchestrationService::complete() — the service itself
 * re-verifies no Pending/Failed instances remain before allowing the
 * transition, so this action's own visible() check is a UX
 * convenience, not the authoritative guard.
 */
class CompleteFleetMigrationRunAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'completeFleetMigrationRun';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Complete');
        $this->icon(Heroicon::OutlinedFlag);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalHeading('Complete fleet migration run');

        $this->visible(fn (FleetMigrationRun $record): bool => $record->status === FleetMigrationRunStatus::InProgress);

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
                $fleetMigrationService->complete($target, $actor);
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not complete this run')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Run completed')->success()->send();
        });
    }
}
