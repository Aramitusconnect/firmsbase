<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Services\MarketplaceClaimService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * MarkClaimUnderReviewAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT4). MarketplaceClaimService::
 * markUnderReview() has existed since Mission 2 checkpoint 11 but was
 * never wired to a Filament Action — a real, previously-unused
 * capability this mission surfaces, not new domain logic. Reversible
 * (a claim can move on to Approve/Reject/RequireEvidence from here),
 * so not step-up gated.
 */
class MarkClaimUnderReviewAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'markClaimUnderReview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Under Review');
        $this->icon(Heroicon::OutlinedMagnifyingGlass);
        $this->color('gray');
        $this->requiresConfirmation();

        $this->visible(fn (DirectoryClaim $record): bool => in_array($record->state, [ClaimState::Pending, ClaimState::EvidenceRequired, ClaimState::Disputed], true));

        $this->action(function (DirectoryClaim $record, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceClaimService $claims): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $decision = $accessPolicy->canManageMarketplaceGovernance($actor);
            if (! $decision->allowed) {
                Notification::make()->title('Not permitted')->body($decision->reason)->danger()->send();

                return;
            }

            $target = DirectoryClaim::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That claim could not be found.')->danger()->send();

                return;
            }

            try {
                $claims->markUnderReview($target, $actor);
                Notification::make()->title('Claim marked under review')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not update claim')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
