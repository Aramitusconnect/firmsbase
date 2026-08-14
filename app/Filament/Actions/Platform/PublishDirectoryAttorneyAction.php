<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Services\DirectoryAttorneyAdministrationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PublishDirectoryAttorneyAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT3). Same reversible, non-step-up
 * shape as PublishDirectoryFirmAction.
 */
class PublishDirectoryAttorneyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'publishDirectoryAttorney';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Publish');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Makes this attorney publicly visible on MyAttorney.');

        $this->visible(fn (DirectoryAttorney $record): bool => $record->publication_state !== DirectoryPublicationState::Published);

        $this->action(function (DirectoryAttorney $record, PlatformStaffAccessPolicyService $accessPolicy, DirectoryAttorneyAdministrationService $service): void {
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

            $target = DirectoryAttorney::query()->find($record->getKey());
            if ($target === null) {
                Notification::make()->title('That attorney could not be found.')->danger()->send();

                return;
            }

            $service->publish($target, $actor);
            Notification::make()->title('Attorney published')->success()->send();
        });
    }
}
