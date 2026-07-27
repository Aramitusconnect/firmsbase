<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\IncidentStatus;
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
 * ResolveIncidentAction — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). Header action on ViewPlatformIncident. Routes
 * exclusively through IncidentService::resolve() — moves the incident
 * to Resolved and records the resolution narrative in one append.
 * Hidden once an incident is already Resolved (idempotent-guard, same
 * shape as CancelSubscriptionAction/ActivatePlanAction's own
 * already-terminal-state visibility checks).
 */
class ResolveIncidentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resolveIncident';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Resolve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');

        $this->schema([
            Textarea::make('resolution')
                ->label('Resolution')
                ->required()
                ->rows(4),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Resolve incident');

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

            $incidentService->resolve(
                null,
                $record->correlation_id,
                (string) $data['resolution'],
                null,
                $actor,
            );

            Notification::make()->title('Incident resolved')->success()->send();
        });
    }
}
