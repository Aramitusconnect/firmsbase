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
 * SetPlanModuleEnabledAction — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Used on
 * PlanAddOnResource (App\Models\PlanModule rows with is_addon = true —
 * see that Resource's own docblock for why no separate add-on model/
 * table exists). Label/icon/color flip between "Enable"/"Disable" based
 * on the record's CURRENT enabled state at render time — mirrors
 * TogglePlatformAdminActiveStatusAction's single-class-both-directions
 * shape exactly, since the underlying write
 * (PlanModuleService::setEnabled()) is the same call either direction.
 *
 * Routes exclusively through PlanModuleService::setEnabled() — a
 * catalog-only edit. Per PlanModuleService's own docblock, this NEVER
 * writes firm_entitlements directly and does not retroactively change
 * any firm's active entitlements — the modal description says so
 * explicitly.
 *
 * TOCTOU-safe: fresh actor resolution, fresh target re-fetch, both
 * canManagePlatformBilling() and the blanket canMutate() rule checked
 * explicitly before calling the service. Not offered on a Retired
 * add-on (retiring already forces enabled = false server-side — see
 * RetirePlanModuleAction).
 */
class SetPlanModuleEnabledAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'setPlanModuleEnabled';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (PlanModule $record): string => $record->enabled ? 'Disable' : 'Enable');
        $this->icon(fn (PlanModule $record): Heroicon => $record->enabled ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle);
        $this->color(fn (PlanModule $record): string => $record->enabled ? 'danger' : 'success');

        $this->requiresConfirmation();
        $this->modalHeading(fn (PlanModule $record): string => $record->enabled ? 'Disable add-on' : 'Enable add-on');
        $this->modalDescription('This changes the plan catalog only. It does not immediately change any firm\'s active entitlements — a firm only picks up this change the next time its license is (re-)assigned this plan.');

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

            $wasEnabled = $target->enabled;

            $updated = $planModuleService->setEnabled($target, ! $wasEnabled, $actor);

            Notification::make()
                ->title($updated->enabled ? 'Add-on enabled' : 'Add-on disabled')
                ->success()
                ->send();
        });
    }
}
