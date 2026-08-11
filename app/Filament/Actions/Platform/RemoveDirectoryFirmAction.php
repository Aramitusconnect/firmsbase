<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Marketplace\Enums\DirectoryPublicationState;
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
 * RemoveDirectoryFirmAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. "Remove listing" is one of the mission spec's
 * own explicitly named high-risk actions — step-up gated via
 * StepUpAuthentication::protect(), the canonical mechanism from
 * Mission 1B, never an ad-hoc password check (section 57).
 */
class RemoveDirectoryFirmAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'removeDirectoryFirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Remove Listing');
        $this->icon(Heroicon::OutlinedTrash);
        $this->color('danger');
        StepUpAuthentication::mergeInto($this, [
            Textarea::make('reason')->label('Reason')->required()->rows(2),
        ], 'platform_admin');
        $this->modalDescription('Permanently removes this listing from MyAttorney. This is a high-risk, disclosed action — the row is never deleted, only removed from public visibility.');

        $this->visible(fn (DirectoryFirm $record): bool => $record->publication_state !== DirectoryPublicationState::Removed);

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

            $moderation->remove($target, $actor, $data['reason']);
            Notification::make()->title('Listing removed')->success()->send();
        });
    }
}
