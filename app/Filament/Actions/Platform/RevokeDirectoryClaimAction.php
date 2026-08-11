<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Services\MarketplaceClaimService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RevokeDirectoryClaimAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. "Revoke claim" is one of the mission spec's
 * own explicitly named high-risk actions — step-up gated. Unlinks the
 * directory listing from the claimant's tenant Firm.
 */
class RevokeDirectoryClaimAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokeDirectoryClaim';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke');
        $this->icon(Heroicon::OutlinedTrash);
        $this->color('danger');
        StepUpAuthentication::mergeInto($this, [
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ], 'platform_admin');
        $this->modalDescription('Unlinks this listing from the claimant\'s Firm and returns it to Public Listing status.');

        $this->visible(fn (DirectoryClaim $record): bool => $record->state === ClaimState::Approved);

        $this->action(function (DirectoryClaim $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceClaimService $claims): void {
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
                $claims->revoke($target, $actor, $data['reason']);
                Notification::make()->title('Claim revoked')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not revoke claim')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
