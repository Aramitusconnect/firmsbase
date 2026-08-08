<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deadline;
use App\Models\User;
use App\Services\TaskCrudAccessPolicyService;

/**
 * DeadlinePolicy — mirrors ClientPolicy's shape MORE than ContactPolicy's:
 * a Deadline is never created via a policy-gated generic Filament
 * CreateRecord form field-by-field — `DeadlineService::create()` is the
 * only creation path (Firm Feature Manifest §3: it auto-creates a
 * linked CalendarEvent in the same transaction, so a raw
 * `Deadline::create()` would silently skip that). `create()` here
 * governs whether the "+ Add Deadline" header Action (which calls
 * DeadlineService::create() internally, never a bare model create) is
 * permitted — not a generic Filament create() ability check on the
 * model.
 *
 * `update()` governs EditDeadline's narrow safe-field-only form
 * (title/jurisdiction/source/reminder_offsets_days) — DeadlineService
 * has no update() method (confirmed by direct source read), and
 * due_at/deadline_type/matter_id/status are deliberately NOT editable
 * fields here: due_at drives the already-created CalendarEvent's own
 * starts_at, and re-deriving that linkage is out of this module's
 * scope; status only ever changes via CompleteDeadlineAction/
 * CancelDeadlineAction, both calling DeadlineService directly.
 */
class DeadlinePolicy
{
    public function __construct(
        private readonly TaskCrudAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canView($firmUser->role);
    }

    public function view(User $user, Deadline $deadline): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $deadline->firm_id
            && $this->accessPolicy->canView($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageDeadline($firmUser->role);
    }

    public function update(User $user, Deadline $deadline): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $deadline->firm_id
            && $this->accessPolicy->canManageDeadline($firmUser->role);
    }
}
