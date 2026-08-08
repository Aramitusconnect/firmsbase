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
 * StartTaskAction — the only row Action that may transition a Task
 * from Open to InProgress; calls TaskService::start() directly, never
 * a hand-set `status` field (TaskResource's own form never exposes
 * `status` at all). TOCTOU + tenant-context discipline mirrors every
 * other Action in this panel (see RunConflictCheckAction's own
 * docblock for the full, already-confirmed pattern): re-fetches the
 * task fresh by primary key INSIDE runWithFirmContext() before calling
 * the service.
 */
class StartTaskAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'startTask';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Start');
        $this->icon(Heroicon::OutlinedPlay);
        $this->color('info');
        $this->requiresConfirmation();

        $this->visible(function (Task $record): bool {
            if ($record->status !== TaskStatus::Open) {
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

                    try {
                        app(TaskService::class)->start($fresh);
                        Notification::make()->title('Task started')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not start task')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
