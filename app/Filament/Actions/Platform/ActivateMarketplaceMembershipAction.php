<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceModerationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * ActivateMarketplaceMembershipAction — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 11. "Change member state" is one of
 * the mission spec's own explicitly named high-risk actions —
 * step-up gated (section 57). Membership is a distinct product/
 * service relationship (section 18) never granted merely by claiming
 * — only ever set here, by an admin, deliberately.
 */
class ActivateMarketplaceMembershipAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'activateMarketplaceMembership';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate Membership');
        $this->icon(Heroicon::OutlinedStar);
        $this->color('success');
        StepUpAuthentication::protect($this, 'platform_admin');
        $this->modalDescription('Grants FirmsVault Member status. This is a product/service relationship, not an endorsement of quality (section 18) — never implied by claiming alone.');

        $this->visible(fn (DirectoryFirm $record): bool => $record->is_claimed && ! $record->is_marketplace_member);

        $this->action(function (DirectoryFirm $record, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceModerationService $moderation): void {
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

            $target = DirectoryFirm::query()->find($record->getKey());
            if ($target === null || ! $target->is_claimed) {
                Notification::make()->title('Only a claimed listing can become a member.')->danger()->send();

                return;
            }

            $moderation->activateMembership($target, $actor);
            Notification::make()->title('Membership activated')->success()->send();
        });
    }
}
