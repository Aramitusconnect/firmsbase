<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskResource\Pages;

use App\Filament\Firm\Concerns\WrapsRecordMutationInFirmContext;
use App\Filament\Firm\Resources\TaskResource;
use Filament\Resources\Pages\EditRecord;

/**
 * EditTask — plain field edit via WrapsRecordMutationInFirmContext's
 * default `handleRecordUpdate()` (raw `Task::update()`), same as
 * ContactResource's EditContact. Safe because the edit form (see
 * TaskResource::form()) never includes `status` — every field it does
 * expose (title/description/matter_id/client_id/assigned_to/priority/
 * due_at) carries no invariant a service layer would need to protect
 * (TaskService::assign() has no side effect beyond the same
 * `assigned_to` column write this form already performs). Status
 * transitions remain exclusively the row Actions'
 * (StartTaskAction/CompleteTaskAction/CancelTaskAction/
 * AddTaskDependencyAction) job.
 */
class EditTask extends EditRecord
{
    use WrapsRecordMutationInFirmContext;

    protected static string $resource = TaskResource::class;
}
