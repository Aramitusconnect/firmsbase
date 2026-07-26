<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlanService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ActivatePlanAction — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Routes exclusively
 * through PlanService::activate() — the only place a Plan's status
 * transitions to Active (see PlanService's own docblock: "the only
 * place Plan rows are created or have their lifecycle status changed").
 *
 * TOCTOU-safe, mirroring TogglePlatformAdminActiveStatusAction's shape:
 * fresh actor resolution, fresh target re-fetch, both
 * canManagePlatformBilling() and the blanket canMutate() rule checked
 * explicitly before calling the service.
 *
 * Visible only for a plan not already Active — activating an already-
 * Active plan would just be a confusing no-op against PlanService's own
 * idempotent update.
 */
class ActivatePlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activatePlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Activate plan');
        $this->modalDescription('This makes the plan assignable to new licenses. Existing licenses/subscriptions on other plans are unaffected.');

        $this->visible(fn (Plan $record): bool => $record->status !== PlanStatus::Active);

        $this->action(function (Plan $record, PlatformStaffAccessPolicyService $accessPolicy, PlanService $planService): void {
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

            $target = Plan::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That plan could not be found.')->danger()->send();

                return;
            }

            $planService->activate($target, $actor);

            Notification::make()->title('Plan activated')->success()->send();
        });
    }
}
