<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Enums\FleetMigrationRunStatus;
use App\Models\FleetMigrationInstanceStatus;
use App\Models\FleetMigrationRun;
use App\Models\PlatformAdmin;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ApplyFleetMigrationInstanceAction — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Row action on the per-instance
 * drill-down table embedded on ViewPlatformFleetMigrationRun. Routes
 * exclusively through
 * FleetMigrationOrchestrationService::applyInstance(). $succeeded is
 * entirely caller-supplied (a required Radio field with explanatory
 * text for each option, per this mission's own "not a bare toggle
 * with no explanation" convention) — SIMULATED ONLY, this action never
 * performs a real migration of any kind (see that service's own
 * docblock).
 *
 * The parent run is resolved from PlatformFleetMigrationRunDetailPage's
 * own public `$runUuid` scalar property — mirrors
 * RequeueOutboxEventAsSupportAction's `Firm::findByUuid((string)
 * $livewire->firmUuid)` pattern exactly for reaching page-level context
 * from a row action — never re-derived from the row's own array/model
 * state, since a FleetMigrationInstanceStatus row does not itself carry
 * enough information to reconstruct which run it belongs to for a
 * fresh re-fetch (its own fleet_migration_run_id column is trusted only
 * as a lookup key, per this mission's established TOCTOU discipline).
 */
class ApplyFleetMigrationInstanceAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'applyFleetMigrationInstance';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Apply');
        $this->icon(Heroicon::OutlinedBolt);
        $this->color('warning');

        $this->schema([
            Radio::make('succeeded')
                ->label('Simulated outcome')
                ->boolean()
                ->default(true)
                ->descriptions([
                    '1' => 'Succeeded — marks this firm\'s instance Applied.',
                    '0' => 'Failed — marks this instance Failed, halts the run, and marks every other still-Pending instance Skipped.',
                ])
                ->required(),
            Textarea::make('error_detail')
                ->label('Error detail (when failed)')
                ->rows(2)
                ->visible(fn ($get): bool => (string) $get('succeeded') === '0'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Apply simulated migration outcome');
        $this->modalDescription('SIMULATED ONLY — no real migration is ever performed. This outcome is entirely your own input.');

        $this->visible(function (FleetMigrationInstanceStatus $record, $livewire): bool {
            $run = $this->resolveRun($livewire);

            return $run instanceof FleetMigrationRun
                && $run->status === FleetMigrationRunStatus::InProgress
                && $record->status === InstanceStatus::Pending;
        });

        $this->action(function (FleetMigrationInstanceStatus $record, array $data, $livewire, PlatformStaffAccessPolicyService $accessPolicy, FleetMigrationOrchestrationService $fleetMigrationService): void {
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

            $run = $this->resolveRun($livewire);

            if (! $run instanceof FleetMigrationRun) {
                Notification::make()->title('Could not resolve the parent run.')->danger()->send();

                return;
            }

            $firm = $record->firm;

            if ($run === null || $firm === null) {
                Notification::make()->title('Could not resolve the run or firm for this instance.')->danger()->send();

                return;
            }

            $succeeded = (bool) ($data['succeeded'] ?? true);
            $errorDetail = $succeeded ? null : (string) ($data['error_detail'] ?? '');

            try {
                $fleetMigrationService->applyInstance($run, $firm, $succeeded, $errorDetail === '' ? null : $errorDetail, $actor);
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not apply this instance')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Instance outcome applied')->success()->send();
        });
    }

    /**
     * Resolves the parent FleetMigrationRun, fresh, from the hosting
     * page's public `$runUuid` property — never trusts a cached model
     * instance, so a run whose status changed between page load and
     * this click is always seen correctly.
     */
    private function resolveRun($livewire): ?FleetMigrationRun
    {
        if (! property_exists($livewire, 'runUuid') || empty($livewire->runUuid)) {
            return null;
        }

        return FleetMigrationRun::query()->where('uuid', $livewire->runUuid)->first();
    }
}
