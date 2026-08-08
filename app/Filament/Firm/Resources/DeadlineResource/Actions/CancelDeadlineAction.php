<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DeadlineResource\Actions;

use App\Enums\DeadlineStatus;
use App\Models\Deadline;
use App\Services\DeadlineService;
use App\Services\TaskCrudAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CancelDeadlineAction — calls DeadlineService::cancel() directly,
 * never a hand-set `status` field.
 */
class CancelDeadlineAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancelDeadline';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Cancel');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->visible(function (Deadline $record): bool {
            if (in_array($record->status, [DeadlineStatus::Completed, DeadlineStatus::Cancelled], true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TaskCrudAccessPolicyService::class)->canManageDeadline($firmUser->role);
        });

        $this->action(function (Deadline $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TaskCrudAccessPolicyService::class)->canManageDeadline($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = Deadline::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this deadline.')->danger()->send();

                        return;
                    }

                    app(DeadlineService::class)->cancel($fresh);
                    Notification::make()->title('Deadline cancelled')->success()->send();
                },
            );
        });
    }
}
