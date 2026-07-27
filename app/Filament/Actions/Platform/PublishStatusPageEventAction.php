<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\StatusPageService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * PublishStatusPageEventAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Header action on
 * PlatformStatusPageEventsPage. Routes exclusively through
 * StatusPageService::publish(). No public status-page UI is built
 * anywhere in this codebase (explicit project rule — see
 * StatusPageEvent's own docblock) — this is the platform-admin-facing
 * draft/publish workflow over the process/data foundation only.
 */
class PublishStatusPageEventAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'publishStatusPageEvent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Publish Status Update');
        $this->icon(Heroicon::OutlinedMegaphone);
        $this->color('primary');

        $this->schema([
            TextInput::make('event_type')
                ->label('Event type')
                ->required()
                ->helperText('Free-text category, e.g. "investigating", "identified", "maintenance_scheduled".'),
            TextInput::make('component_affected')
                ->label('Component affected')
                ->required(),
            Textarea::make('public_message')
                ->label('Public message')
                ->required()
                ->rows(3),
            DateTimePicker::make('starts_at')
                ->label('Starts at')
                ->required()
                ->default(now()),
            TextInput::make('incident_correlation_id')
                ->label('Linked incident correlation ID (optional)')
                ->helperText('Optionally link this status update to an internal incident.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Publish a new status page update');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, StatusPageService $statusPageService): void {
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

            $statusPageService->publish(
                (string) $data['event_type'],
                (string) $data['component_affected'],
                (string) $data['public_message'],
                Carbon::parse((string) $data['starts_at']),
                empty($data['incident_correlation_id']) ? null : (string) $data['incident_correlation_id'],
                $actor,
            );

            Notification::make()->title('Status update published')->success()->send();
        });
    }
}
