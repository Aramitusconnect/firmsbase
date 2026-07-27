<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\IncidentSeverity;
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
 * UpdateIncidentSeverityAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on ViewPlatformIncident.
 * Routes exclusively through IncidentService::updateSeverity(), which
 * itself re-reads currentState($correlationId) fresh inside its own
 * context wrap — this action never trusts the page-load-time $record
 * for anything beyond its stable correlation_id identifier.
 */
class UpdateIncidentSeverityAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'updateIncidentSeverity';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Update Severity');
        $this->icon(Heroicon::OutlinedAdjustmentsHorizontal);
        $this->color('warning');

        $this->schema([
            Select::make('severity')
                ->options(collect(IncidentSeverity::cases())
                    ->mapWithKeys(fn (IncidentSeverity $s): array => [$s->value => Str::headline($s->value)])
                    ->all())
                ->required()
                ->native(false),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Update incident severity');

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

            $incidentService->updateSeverity(
                null,
                $record->correlation_id,
                IncidentSeverity::from((string) $data['severity']),
                null,
                $actor,
            );

            Notification::make()->title('Severity updated')->success()->send();
        });
    }
}
