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
 * CompleteTaskAction — calls TaskService::complete() directly, which
 * itself refuses (RuntimeException) a Blocked task — this action's
 * `visible()` already hides itself for a Blocked task, but the
 * action() closure catches and surfaces that RuntimeException anyway
 * (defense-in-depth against a stale row rendered before another tab
 * added a blocking dependency — the same TOCTOU discipline every other
 * Action in this panel already applies).
 */
class CompleteTaskAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'completeTask';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Complete');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();

        $this->visible(function (Task $record): bool {
            if (! in_array($record->status, [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Overdue], true)) {
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
                        app(TaskService::class)->complete($fresh);
                        Notification::make()->title('Task completed')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not complete task')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
