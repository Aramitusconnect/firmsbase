<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlanModuleService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * AddPlanModuleAction — FIRMSVAULT STAGING ADMIN STABILIZATION. Header
 * action on the Add-ons list (PlanAddOnResource), the first supported
 * way to attach a module_catalog module to a Plan through the admin
 * UI (this pass's own defect list: "Add-ons ... provides no supported
 * creation workflow"). Routes exclusively through
 * PlanModuleService::addModule(), which validates the module code
 * against the authoritative module_catalog registry — the Select
 * below is itself already restricted to active catalog entries, so a
 * free-text/invented code is never offered, and the service's own
 * validation is a second, defense-in-depth check for the same rule.
 *
 * PlanModuleService::addModule() is keyed on updateOrCreate(plan_id,
 * module_code), so submitting an existing (plan, module) pair updates
 * that row in place rather than ever creating a duplicate — the
 * mission's "prevent duplicate plan/module combinations" requirement
 * is satisfied structurally, not by a separate pre-check here.
 *
 * Per PlanModuleService/EntitlementPlanSyncService's own documented
 * lifecycle, adding a module to a plan does NOT retroactively change
 * any firm's active entitlements — only the next (re-)assignment of
 * this plan to a firm license picks up the change. Surfaced in the
 * modal description so the UI never implies an instant fleet-wide
 * effect.
 */
class AddPlanModuleAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addPlanModule';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add module to plan');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            Select::make('plan_id')
                ->label('Plan')
                ->searchable()
                ->options(fn (): array => Plan::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->native(false),
            Select::make('module_code')
                ->label('Module')
                ->helperText('Only active modules from the authoritative module catalog are offered.')
                ->searchable()
                ->options(fn (): array => ModuleCatalog::query()
                    ->where('is_active', true)
                    ->orderBy('module_name')
                    ->pluck('module_name', 'module_code')
                    ->all())
                ->required()
                ->native(false),
            Toggle::make('enabled')
                ->label('Enabled by default')
                ->default(true),
            Toggle::make('is_addon')
                ->label('Optional paid add-on')
                ->helperText('Off means this module is bundled into the plan\'s base price rather than an optional add-on.')
                ->default(true),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Add module to plan');
        $this->modalDescription('This changes the plan catalog only. It does not immediately change any firm\'s active entitlements — a firm only picks up this change the next time its license is (re-)assigned this plan.');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, PlanModuleService $planModuleService): void {
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

            $plan = Plan::query()->find($data['plan_id']);

            if ($plan === null) {
                Notification::make()->title('That plan could not be found.')->danger()->send();

                return;
            }

            try {
                $planModuleService->addModule(
                    $plan,
                    (string) $data['module_code'],
                    enabled: (bool) ($data['enabled'] ?? true),
                    isAddon: (bool) ($data['is_addon'] ?? true),
                    actor: $actor,
                );
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not add module')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Module added to plan')->success()->send();
        });
    }
}
