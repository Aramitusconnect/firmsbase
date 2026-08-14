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

class ArchiveDirectoryAttorneyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'archiveDirectoryAttorney';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Archive');
        $this->icon(Heroicon::OutlinedArchiveBox);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalDescription('Marks this attorney record as archived (e.g. no longer practicing). Hidden from public search but the record is preserved.');

        $this->visible(fn (DirectoryAttorney $record): bool => $record->publication_state !== DirectoryPublicationState::Archived);

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

            $service->archive($target, $actor);
            Notification::make()->title('Attorney archived')->success()->send();
        });
    }
}
