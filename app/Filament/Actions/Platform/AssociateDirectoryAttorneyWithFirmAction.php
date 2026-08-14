<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\DirectoryAttorneyAdministrationService;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * AssociateDirectoryAttorneyWithFirmAction — MyAttorney SuperAdmin
 * console professionalization mission (MYAT3). "Move/associate with
 * firm through a safe workflow" per this mission's own spec —
 * requires confirmation, always goes through
 * DirectoryAttorneyAdministrationService::associateWithFirm(), which
 * transitions any existing Current relationship to Former rather than
 * leaving two simultaneous Current rows.
 */
class AssociateDirectoryAttorneyWithFirmAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'associateDirectoryAttorneyWithFirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Manage Firm Association');
        $this->icon(Heroicon::OutlinedLink);
        $this->schema([
            Select::make('directory_firm_id')
                ->label('Firm')
                ->options(fn (): array => DirectoryFirm::query()->orderBy('display_name')->limit(200)->pluck('display_name', 'id')->all())
                ->searchable()
                ->required(),
            TextInput::make('firm_title')->label('Title at Firm')->maxLength(255),
        ]);
        $this->requiresConfirmation();
        $this->modalDescription('Sets this attorney\'s current firm. Any prior current association is preserved as history, not deleted.');

        $this->action(function (DirectoryAttorney $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, DirectoryAttorneyAdministrationService $service): void {
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

            $service->associateWithFirm($target, (int) $data['directory_firm_id'], $data['firm_title'] ?? null, $actor);
            Notification::make()->title('Firm association updated')->success()->send();
        });
    }
}
