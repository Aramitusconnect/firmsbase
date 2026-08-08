<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Actions;

use App\Enums\TimeTrackingSessionStatus;
use App\Filament\Firm\Resources\TimeEntryResource;
use App\Models\TimeTrackingSession;
use App\Services\TenantContextService;
use App\Services\TimeExpenseAccessPolicyService;
use App\Services\TimeTrackingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * StopTimerAction — a header action wired directly to
 * TimeTrackingService::stop(), which folds the session's
 * accumulated_seconds into a whole-second integer and creates exactly
 * one Draft TimeEntry from it (see that service's own docblock). Finds
 * the acting user's own Active session inside the same
 * runWithFirmContext() wrap the mutation itself runs in — no separate,
 * unwrapped existence check.
 */
class StopTimerAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'stopTimer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Stop Timer');
        $this->icon(Heroicon::OutlinedStop);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(TimeExpenseAccessPolicyService::class)->canManageTimeEntry($firmUser->role);
        });

        $this->action(function (): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canManageTimeEntry($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($firmUser): void {
                    $session = TimeTrackingSession::query()
                        ->where('user_id', $firmUser->user_id)
                        ->where('status', TimeTrackingSessionStatus::Active)
                        ->latest('started_at')
                        ->first();

                    if ($session === null) {
                        Notification::make()->title('No active timer to stop')->danger()->send();

                        return;
                    }

                    $entry = app(TimeTrackingService::class)->stop($session);

                    Notification::make()
                        ->title('Timer stopped')
                        ->body('Logged '.TimeEntryResource::formatDuration($entry->seconds).' as a draft time entry.')
                        ->success()
                        ->send();
                },
            );
        });
    }
}
