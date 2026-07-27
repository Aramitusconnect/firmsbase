<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateFleetMigrationRunAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on
 * ListPlatformFleetMigrationRuns. Routes exclusively through
 * FleetMigrationOrchestrationService::createRun(). SIMULATED ONLY —
 * this is a rehearsal/planning tool, never a real rollout trigger; no
 * real infrastructure or firm data is ever touched (see that service's
 * own docblock).
 *
 * Since this console has no real firm-panel User to attribute
 * `initiated_by` to, this always calls createRun() with
 * `$initiatedBy = null`, letting the service fall back to its own
 * lazily-created inert sentinel actor row for that NOT NULL FK column
 * — the platform admin's real identity is captured separately via
 * `$platformAdminActor` (see that service's own docblock for the full
 * reasoning behind this resolution).
 */
class CreateFleetMigrationRunAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createFleetMigrationRun';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create Fleet Migration Run');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            TextInput::make('migration_identifier')
                ->label('Migration identifier')
                ->required()
                ->helperText('E.g. "2026_08_01_000000_example". Enrolls every current Dedicated/Private Enterprise firm as a Pending instance.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Create a new fleet migration run');
        $this->modalDescription('This is a SIMULATED rehearsal/planning tool — no real infrastructure or firm data is ever touched. Every "apply" outcome is caller-supplied later, never a real migration.');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, FleetMigrationOrchestrationService $fleetMigrationService): void {
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

            $fleetMigrationService->createRun((string) $data['migration_identifier'], null, $actor);

            Notification::make()->title('Fleet migration run created')->success()->send();
        });
    }
}
