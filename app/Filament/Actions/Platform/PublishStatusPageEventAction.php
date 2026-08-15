<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\StatusPagePublicationCapabilityService;
use App\Services\StatusPageService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
 *
 * OPERATIONS CONTROL PLANE ADDITIONS, both about the gap between what
 * this action is called and what it does:
 *
 *  - The confirmation now states plainly, derived from
 *    StatusPagePublicationCapabilityService, that no public endpoint
 *    exists and that customers are not informed by this action. The
 *    word "Publish" on a button during an incident is otherwise read
 *    as "customers have been told."
 *  - A live preview of the exact public message is shown before
 *    confirming. Public text is a different information
 *    classification from internal incident detail, and the only
 *    reliable way to keep hostnames, stack traces and customer names
 *    out of it is to make the author look at the finished text.
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

        // Capability-derived label: while no public endpoint exists,
        // the button must not promise publication it cannot perform.
        $this->label(fn (): string => app(StatusPagePublicationCapabilityService::class)->hasPublicPublicationBackend()
            ? 'Publish Status Update'
            : 'Record Status Update (Internal)');
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
                ->rows(3)
                ->live(onBlur: true)
                ->helperText(
                    'Written for customers, not for engineers. Never include hostnames, private IPs, AWS account or '.
                    'resource identifiers, database names, stack traces, security-investigation detail, or the names '.
                    'of specific firms.'
                ),
            Placeholder::make('public_message_preview')
                ->label('Preview — exactly this text is stored as the public message')
                ->content(fn (Get $get): string => trim((string) $get('public_message')) !== ''
                    ? (string) $get('public_message')
                    : 'Nothing entered yet.'),
            DateTimePicker::make('starts_at')
                ->label('Starts at')
                ->required()
                ->default(now()),
            TextInput::make('incident_correlation_id')
                ->label('Linked incident correlation ID (optional)')
                ->helperText('Optionally link this status update to an internal incident.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Record a new status update');
        $this->modalDescription(fn (): string => app(StatusPagePublicationCapabilityService::class)->disclosure());

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

            $capability = app(StatusPagePublicationCapabilityService::class);

            Notification::make()
                ->title($capability->hasPublicPublicationBackend()
                    ? 'Status update published'
                    : 'Status update recorded internally')
                ->body($capability->hasPublicPublicationBackend()
                    ? null
                    : 'No public status page exists — customers have NOT been notified by this action.')
                ->success()
                ->send();
        });
    }
}
