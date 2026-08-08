<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskResource\Pages;

use App\Enums\TaskPriority;
use App\Filament\Firm\Resources\TaskResource;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateTask — deliberately overrides `handleRecordCreation()` to call
 * `TaskService::create()` rather than using
 * `WrapsRecordMutationInFirmContext`'s default (a bare
 * `static::getModel()::create($data)`). Task carries no hard creation
 * restriction the way Client/FirmLead do (Firm Feature Manifest §3),
 * but this mission's own instruction is explicit: never guess/bypass a
 * service's signature when one exists and is the established pattern
 * for this domain (DeadlineService::create() below is the same
 * discipline for the sibling resource). Routing through the service
 * also ensures `status` is always explicitly `Open` and `created_by` is
 * always populated — no create form ever includes either field (see
 * TaskResource's own docblock), so this is not a UI-visible behavior
 * change, only an internal-consistency one.
 *
 * Tenant-context wrap matches WrapsRecordMutationInFirmContext's own
 * documented root cause (Filament's `livewire/update` endpoint carries
 * no ambient `app.current_firm_id`) — TaskService's own
 * `runWithFirmContext()` call is safe/re-entrant nested inside this
 * one.
 */
class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): Task {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                    ? Matter::query()->where('id', $data['matter_id'])->first()
                    : null;
                $client = isset($data['client_id']) && $data['client_id'] !== null
                    ? Client::query()->where('id', $data['client_id'])->first()
                    : null;
                $assignedTo = isset($data['assigned_to']) && $data['assigned_to'] !== null
                    ? User::query()->find($data['assigned_to'])
                    : null;

                return app(TaskService::class)->create(
                    firm: $firm,
                    title: $data['title'],
                    matter: $matter,
                    client: $client,
                    assignedTo: $assignedTo,
                    priority: isset($data['priority']) ? TaskPriority::from($data['priority']) : TaskPriority::Normal,
                    dueAt: isset($data['due_at']) && $data['due_at'] !== null ? Carbon::parse($data['due_at']) : null,
                    description: $data['description'] ?? null,
                    createdBy: $firmUser->user,
                );
            },
        );
    }
}
