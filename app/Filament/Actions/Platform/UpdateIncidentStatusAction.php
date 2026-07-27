<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\IncidentStatus;
use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * UpdateIncidentStatusAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on ViewPlatformIncident.
 * Routes exclusively through IncidentService::updateStatus(). Use
 * ResolveIncidentAction (not this one) to move an incident to Resolved
 * — that path also records a resolution narrative, which this generic
 * status update does not collect.
 */
class UpdateIncidentStatusAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'updateIncidentStatus';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Update Status');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('info');

        $this->schema([
            Select::make('status')
                ->options(collect(IncidentStatus::cases())
                    ->filter(fn (IncidentStatus $s): bool => $s !== IncidentStatus::Resolved)
                    ->mapWithKeys(fn (IncidentStatus $s): array => [$s->value => Str::headline($s->value)])
                    ->all())
                ->required()
                ->native(false)
                ->helperText('Use "Resolve" (a separate action) to move this incident to Resolved with a resolution narrative.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Update incident status');

        $this->visible(fn (IncidentEvent $record): bool => $record->status !== IncidentStatus::Resolved);

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

            $incidentService->updateStatus(
                null,
                $record->correlation_id,
                IncidentStatus::from((string) $data['status']),
                null,
                $actor,
            );

            Notification::make()->title('Status updated')->success()->send();
        });
    }
}
