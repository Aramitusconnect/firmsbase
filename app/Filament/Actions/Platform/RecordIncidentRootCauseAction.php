<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RecordIncidentRootCauseAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on ViewPlatformIncident.
 * Routes exclusively through IncidentService::recordRootCause().
 */
class RecordIncidentRootCauseAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'recordIncidentRootCause';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Record Root Cause');
        $this->icon(Heroicon::OutlinedMagnifyingGlass);
        $this->color('gray');

        $this->schema([
            Textarea::make('root_cause')
                ->label('Root cause')
                ->required()
                ->rows(4),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Record root cause');

        $this->action(function (IncidentEvent $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, IncidentService $incidentService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManageOperations($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $incidentService->recordRootCause(
                null,
                $record->correlation_id,
                (string) $data['root_cause'],
                null,
                $actor,
            );

            Notification::make()->title('Root cause recorded')->success()->send();
        });
    }
}
