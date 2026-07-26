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
 * ConvertTrialRequestAction — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Routes
 * exclusively through TrialRequestService::convert(), which also
 * records a trial->paid conversion event when an organization is
 * attached (see that service's own logic — this action does not
 * duplicate that decision, it is entirely internal to convert()).
 * Visible only for an Active trial request — converting a trial that
 * was never activated is not a supported transition per
 * TrialRequestStatus's own state shape.
 *
 * TOCTOU-safe, matching ActivateTrialRequestAction's identical shape.
 */
class ConvertTrialRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'convertTrialRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Convert');
        $this->icon(Heroicon::OutlinedSparkles);
        $this->color('success');

        $this->requiresConfirmation();
        $this->modalHeading('Convert trial to paid');

        $this->visible(fn (TrialRequest $record): bool => $record->status === TrialRequestStatus::Active);

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

            $trialRequestService->convert($target, $actor);

            Notification::make()->title('Trial converted')->success()->send();
        });
    }
}
