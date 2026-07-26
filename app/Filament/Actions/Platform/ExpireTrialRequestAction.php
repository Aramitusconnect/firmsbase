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
 * ExpireTrialRequestAction — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Routes exclusively
 * through TrialRequestService::expire(). The "override trial" admin
 * capability mentioned in the mission brief — lets an admin end a trial
 * early (or clean up a stale one) without waiting on expires_at. Visible
 * for any non-terminal trial request (Requested/Provisioned/Active) —
 * not on one already Expired/Converted/Cancelled.
 *
 * TOCTOU-safe, matching ActivateTrialRequestAction's identical shape.
 */
class ExpireTrialRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'expireTrialRequest';
    }

    private const NON_TERMINAL_STATUSES = [
        TrialRequestStatus::Requested,
        TrialRequestStatus::Provisioned,
        TrialRequestStatus::Active,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Expire');
        $this->icon(Heroicon::OutlinedClock);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Expire trial');
        $this->modalDescription('Ends this trial now, regardless of its expires_at date.');

        $this->visible(fn (TrialRequest $record): bool => in_array($record->status, self::NON_TERMINAL_STATUSES, true));

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

            $trialRequestService->expire($target, $actor);

            Notification::make()->title('Trial expired')->success()->send();
        });
    }
}
