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
 * ArchivePlanAction — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Routes exclusively
 * through PlanService::archive(). Archiving does not touch any existing
 * license/subscription already on this plan (PlanStatus's own docblock:
 * "Archived plans remain referenced by existing licenses/history but
 * cannot be newly assigned") — this action's modal description says so
 * explicitly rather than implying an instant fleet-wide effect.
 *
 * TOCTOU-safe, mirroring ActivatePlanAction's identical shape.
 * Visible only for a plan not already Archived.
 */
class ArchivePlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'archivePlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Archive');
        $this->icon(Heroicon::OutlinedArchiveBoxArrowDown);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Archive plan');
        $this->modalDescription('This plan can no longer be newly assigned to a license or subscription. Firms/organizations already on this plan are unaffected — their existing license and entitlements are not changed by this action.');

        $this->visible(fn (Plan $record): bool => $record->status !== PlanStatus::Archived);

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

            $planService->archive($target, $actor);

            Notification::make()->title('Plan archived')->success()->send();
        });
    }
}
