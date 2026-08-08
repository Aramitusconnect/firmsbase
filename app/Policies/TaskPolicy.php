<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskCrudAccessPolicyService;

/**
 * TaskPolicy — mirrors ContactPolicy's shape (Task has no creation
 * restriction the way Client/FirmLead do — Firm Feature Manifest §3:
 * "Simple, manual-entry-friendly"). `create()`/`update()` govern the
 * plain CRUD form fields only (title/description/matter_id/client_id/
 * assigned_to/priority/due_at) — `status` is never present as an
 * editable field on either form (see TaskResource's own docblock);
 * status transitions are separate row Actions
 * (StartTaskAction/CompleteTaskAction/CancelTaskAction) each calling
 * TaskService directly, gated the same way.
 *
 * Every instance-scoped method re-confirms firm_id match as
 * defense-in-depth — never a substitute for `tasks`' own FORCE ROW
 * LEVEL SECURITY, which remains the real tenant-isolation boundary.
 */
class TaskPolicy
{
    public function __construct(
        private readonly TaskCrudAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, Task $task): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $task->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageTask($firmUser->role);
    }

    public function update(User $user, Task $task): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $task->firm_id
            && $this->accessPolicy->canManageTask($firmUser->role);
    }
}
