<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlanModuleStatus;
use App\Models\PlanModule;
use App\Models\PlatformAdmin;
use App\Services\PlanModuleService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RetirePlanModuleAction — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Used on
 * PlanAddOnResource. Routes exclusively through
 * PlanModuleService::retire() — a catalog-only, terminal-status edit
 * (sets status = Retired, enabled = false). Same "does not retroactively
 * touch firm_entitlements" property as SetPlanModuleEnabledAction — the
 * modal description says so explicitly.
 *
 * TOCTOU-safe, identical shape to SetPlanModuleEnabledAction. Not
 * offered on an already-Retired row (retire() is idempotent server-side,
 * but offering it again would just be a confusing no-op).
 */
class RetirePlanModuleAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retirePlanModule';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Retire');
        $this->icon(Heroicon::OutlinedArchiveBoxXMark);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Retire add-on');
        $this->modalDescription('This retires the add-on from the plan catalog and disables it. This changes the plan catalog only — it does not immediately change any firm\'s active entitlements. This cannot be undone from this panel.');

        $this->visible(fn (PlanModule $record): bool => $record->status !== PlanModuleStatus::Retired);

        $this->action(function (PlanModule $record, PlatformStaffAccessPolicyService $accessPolicy, PlanModuleService $planModuleService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManagePlatformBilling($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $target = PlanModule::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That add-on could not be found.')->danger()->send();

                return;
            }

            $planModuleService->retire($target, $actor);

            Notification::make()->title('Add-on retired')->success()->send();
        });
    }
}
