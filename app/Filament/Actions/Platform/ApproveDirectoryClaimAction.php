<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Services\MarketplaceClaimService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ApproveDirectoryClaimAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. "Approve claim" is one of the mission spec's
 * own explicitly named high-risk actions — step-up gated. Routes
 * exclusively through MarketplaceClaimService::approve(), which links
 * the directory listing to the claimant's tenant Firm and auto-rejects
 * every other active competing claim (checkpoint 6's own logic —
 * never duplicated here).
 */
class ApproveDirectoryClaimAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'approveDirectoryClaim';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        StepUpAuthentication::protect($this, 'platform_admin');
        $this->modalDescription('Links this listing to the claimant\'s Firm and rejects any other active claim on it.');

        $this->visible(fn (DirectoryClaim $record): bool => $record->state->isActive());

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
                $claims->approve($target, $actor);
                Notification::make()->title('Claim approved')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not approve claim')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
