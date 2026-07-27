<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * FlagIncidentNotificationNeededAction — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Header action on
 * ViewPlatformIncident. Routes exclusively through
 * IncidentService::flagNotificationNeeded().
 */
class FlagIncidentNotificationNeededAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'flagIncidentNotificationNeeded';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Flag Notification Needed');
        $this->icon(Heroicon::OutlinedBell);
        $this->color('gray');

        $this->schema([
            Checkbox::make('notification_needed')->label('Customer notification needed'),
        ]);

        $this->fillForm(fn (IncidentEvent $record): array => ['notification_needed' => $record->notification_needed]);

        $this->requiresConfirmation();
        $this->modalHeading('Update customer notification flag');

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

            $incidentService->flagNotificationNeeded(
                null,
                $record->correlation_id,
                (bool) ($data['notification_needed'] ?? false),
                null,
                $actor,
            );

            Notification::make()->title('Notification-needed flag updated')->success()->send();
        });
    }
}
