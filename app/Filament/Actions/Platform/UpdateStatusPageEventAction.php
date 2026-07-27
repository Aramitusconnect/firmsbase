<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\StatusPageEvent;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\StatusPageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateStatusPageEventAction — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). Row action on
 * PlatformStatusPageEventsPage. Routes exclusively through
 * StatusPageService::update() — appends a further update under the
 * same correlation_id.
 */
class UpdateStatusPageEventAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'updateStatusPageEvent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add Update');
        $this->icon(Heroicon::OutlinedPencilSquare);
        $this->color('gray');

        $this->schema([
            TextInput::make('event_type')->label('Event type')->required(),
            Textarea::make('public_message')->label('Public message')->required()->rows(3),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Add a status page update');

        $this->action(function (StatusPageEvent $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, StatusPageService $statusPageService): void {
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

            $statusPageService->update(
                $record->correlation_id,
                (string) $data['event_type'],
                (string) $data['public_message'],
                $actor,
            );

            Notification::make()->title('Status update added')->success()->send();
        });
    }
}
