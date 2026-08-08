<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskResource\Actions;

use App\Models\Task;
use App\Services\TaskCrudAccessPolicyService;
use App\Services\TaskDependencyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * AddTaskDependencyAction — the ONLY UI path that may create a
 * task_dependencies row; calls TaskDependencyService::addDependency()
 * directly, NEVER `TaskDependency::create()` (this mission's explicit
 * rule — that service is "the ONLY place task_dependencies rows are
 * created," rejecting cycles via BFS before ever inserting, per its
 * own docblock).
 *
 * The "blocked by" Select is deliberately NOT a plain page-mount-time
 * options() list (unlike TaskResource::form()'s matter_id/client_id/
 * assigned_to selects): this is a modal Action, whose schema is built
 * via Filament's shared `livewire/update` endpoint (mountAction()), not
 * the page's own initial HTTP GET — see
 * WrapsRecordMutationInFirmContext's docblock for why that endpoint
 * carries no ambient `app.current_firm_id`. The options callback below
 * wraps its own query in an explicit `runWithFirmContext()`, matching
 * AddClientAction's `lead_source_id` Select. It also excludes the
 * record's own id (self-dependency is already rejected by the service
 * with a dedicated message, but excluding it from the list is a better
 * UX than letting the user pick it and then explaining why it failed).
 *
 * Cycle rejection: TaskDependencyService::addDependency() throws a
 * RuntimeException when the chosen task would create a cycle (it
 * already (transitively) depends on this task). That exception is
 * caught here and surfaced as a danger Notification with the service's
 * own descriptive message — this action never silently swallows a
 * cycle rejection, and never lets the exception bubble into a raw
 * Livewire error page.
 */
class AddTaskDependencyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addTaskDependency';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add Dependency');
        $this->icon(Heroicon::OutlinedLink);
        $this->color('gray');
        $this->modalHeading('Block This Task On Another Task');
        $this->modalDescription('The selected task must be completed (or cancelled) before this task can be completed. Adding a dependency that would create a cycle is rejected.');
        $this->modalSubmitActionLabel('Add Dependency');

        $this->schema(fn (Task $record) => [
            Select::make('blocked_by_task_id')
                ->label('Blocked By')
                ->options(function () use ($record): array {
                    $firmUser = Auth::user()?->activeFirmUser();

                    if ($firmUser === null) {
                        return [];
                    }

                    return app(TenantContextService::class)->runWithFirmContext(
                        (int) $firmUser->firm_id,
                        fn (): array => Task::query()
                            ->where('id', '!=', $record->id)
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all(),
                    );
                })
                ->searchable()
                ->required(),
        ]);

        $this->visible(function (Task $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(TaskCrudAccessPolicyService::class)->canManageTaskDependency($firmUser->role);
        });

        $this->action(function (array $data, Task $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to this task.')->danger()->send();

                return;
            }

            if (! app(TaskCrudAccessPolicyService::class)->canManageTaskDependency($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not sequence task dependencies.')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $data, $firmUser): void {
                    $task = Task::query()->where('id', $record->id)->firstOrFail();
                    $blockedByTask = Task::query()->where('id', $data['blocked_by_task_id'])->first();

                    if ($blockedByTask === null || (int) $firmUser->firm_id !== (int) $blockedByTask->firm_id) {
                        Notification::make()->title('That task could not be found.')->danger()->send();

                        return;
                    }

                    try {
                        app(TaskDependencyService::class)->addDependency($task, $blockedByTask);

                        Notification::make()
                            ->title('Dependency added')
                            ->body("This task is now blocked by \"{$blockedByTask->title}\".")
                            ->success()
                            ->send();
                    } catch (\InvalidArgumentException|\RuntimeException $e) {
                        Notification::make()
                            ->title('Could not add dependency')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                },
            );
        });
    }
}
