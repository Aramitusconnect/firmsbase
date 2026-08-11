<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceModerationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PublishDirectoryFirmAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Not step-up gated — publishing a Draft/
 * Suspended listing is reversible (Suspend/Remove exist) and not one
 * of the mission's own explicitly named high-risk actions.
 */
class PublishDirectoryFirmAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'publishDirectoryFirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Publish');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Makes this listing publicly visible on MyAttorney.');

        $this->visible(fn (DirectoryFirm $record): bool => $record->publication_state !== DirectoryPublicationState::Published);

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
            if ($target === null) {
                Notification::make()->title('That listing could not be found.')->danger()->send();

                return;
            }

            $moderation->publish($target, $actor);
            Notification::make()->title('Listing published')->success()->send();
        });
    }
}
