<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\IncidentSeverity;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * OpenIncidentAction — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). Header action on ListPlatformIncidents.
 * Routes exclusively through IncidentService::open(). Always opens a
 * PLATFORM-WIDE incident ($firm = null) — PlatformIncidentResource is
 * deliberately scoped to platform-wide incidents only (see that
 * class's own docblock: firm-specific incidents need the per-firm-loop
 * pattern for cross-firm listing, out of this pass's scope).
 *
 * TOCTOU-safe, mirroring this phase's other actor-type-gap actions:
 * fresh actor resolution inside the closure, both canManageOperations()
 * and the blanket canMutate() rule checked before calling the service.
 */
class OpenIncidentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'openIncident';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Open Incident');
        $this->icon(Heroicon::OutlinedExclamationTriangle);
        $this->color('danger');

        $this->schema([
            Select::make('severity')
                ->options(collect(IncidentSeverity::cases())
                    ->mapWithKeys(fn (IncidentSeverity $s): array => [$s->value => Str::headline($s->value)])
                    ->all())
                ->required()
                ->native(false),
            Textarea::make('message')
                ->label('Description')
                ->required()
                ->rows(3),
            Checkbox::make('customer_impact')->label('Customer impact'),
            Checkbox::make('notification_needed')->label('Customer notification needed'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Open a new platform-wide incident');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, IncidentService $incidentService): void {
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

            $severity = IncidentSeverity::from((string) $data['severity']);

            $incidentService->open(
                null,
                $severity,
                (string) $data['message'],
                (bool) ($data['customer_impact'] ?? false),
                (bool) ($data['notification_needed'] ?? false),
                null,
                $actor,
            );

            Notification::make()->title('Incident opened')->success()->send();
        });
    }
}
