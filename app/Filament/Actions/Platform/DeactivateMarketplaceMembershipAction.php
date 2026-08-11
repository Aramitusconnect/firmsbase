<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceModerationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DeactivateMarketplaceMembershipAction — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 11. Same "change member state"
 * high-risk classification as ActivateMarketplaceMembershipAction —
 * step-up gated.
 */
class DeactivateMarketplaceMembershipAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivateMarketplaceMembership';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate Membership');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        StepUpAuthentication::mergeInto($this, [
            Textarea::make('reason')->label('Reason (internal)')->rows(2)->nullable(),
        ], 'platform_admin');
        $this->modalDescription('Removes FirmsVault Member status. The listing remains claimed and published unless separately suspended/removed.');

        $this->visible(fn (DirectoryFirm $record): bool => $record->is_marketplace_member);

        $this->action(function (DirectoryFirm $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceModerationService $moderation): void {
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
            if ($target === null) {
                Notification::make()->title('That listing could not be found.')->danger()->send();

                return;
            }

            $moderation->deactivateMembership($target, $actor, $data['reason'] ?? null);
            Notification::make()->title('Membership deactivated')->success()->send();
        });
    }
}
