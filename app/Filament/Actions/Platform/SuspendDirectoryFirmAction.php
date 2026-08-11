<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

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
 * SuspendDirectoryFirmAction — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 11. Reversible (Publish exists), so not step-up
 * gated — matches ArchivePlanAction's own "reversible state change,
 * confirm-only" precedent.
 */
class SuspendDirectoryFirmAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'suspendDirectoryFirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Suspend');
        $this->icon(Heroicon::OutlinedPauseCircle);
        $this->color('warning');
        $this->schema([
            Textarea::make('reason')->label('Reason (internal)')->rows(2)->nullable(),
        ]);
        $this->requiresConfirmation();
        $this->modalDescription('Temporarily hides this listing from public MyAttorney search/profile pages. Reversible via Publish.');

        $this->visible(fn (DirectoryFirm $record): bool => $record->publication_state === DirectoryPublicationState::Published);

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

            $moderation->suspend($target, $actor, $data['reason'] ?? null);
            Notification::make()->title('Listing suspended')->success()->send();
        });
    }
}
