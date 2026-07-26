<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\TrialRequestStatus;
use App\Models\PlatformAdmin;
use App\Models\TrialRequest;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TrialRequestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ActivateTrialRequestAction — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Routes
 * exclusively through TrialRequestService::activate(). Visible only for
 * a Provisioned trial request — this is the normal
 * Requested -> Provisioned -> Active progression's next step.
 *
 * TOCTOU-safe, matching CancelSubscriptionAction/ActivatePlanAction's
 * identical shape.
 */
class ActivateTrialRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activateTrialRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Activate trial');

        $this->visible(fn (TrialRequest $record): bool => $record->status === TrialRequestStatus::Provisioned);

        $this->action(function (TrialRequest $record, PlatformStaffAccessPolicyService $accessPolicy, TrialRequestService $trialRequestService): void {
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

            $target = TrialRequest::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That trial request could not be found.')->danger()->send();

                return;
            }

            $trialRequestService->activate($target, $actor);

            Notification::make()->title('Trial activated')->success()->send();
        });
    }
}
