<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskResource\Actions;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskCrudAccessPolicyService;
use App\Services\TaskService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CancelTaskAction — calls TaskService::cancel() directly. Visible for
 * any non-terminal status (including Blocked — cancelling a blocked
 * task is always allowed, unlike completing one).
 */
class CancelTaskAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancelTask';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Cancel');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();

        $this->visible(function (Task $record): bool {
            if (in_array($record->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TaskCrudAccessPolicyService::class)->canManageTask($firmUser->role);
        });

        $this->action(function (Task $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(TaskCrudAccessPolicyService::class)->canManageTask($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = Task::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this task.')->danger()->send();

                        return;
                    }

                    app(TaskService::class)->cancel($fresh);
                    Notification::make()->title('Task cancelled')->success()->send();
                },
            );
        });
    }
}
