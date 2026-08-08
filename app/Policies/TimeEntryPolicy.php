<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeExpenseAccessPolicyService;

/**
 * TimeEntryPolicy — mirrors TaskPolicy/DeadlinePolicy's shape.
 * `create()` governs the "+ Add Time Entry" header action (which calls
 * TimeEntryApprovalService::createManualEntry() internally — see
 * CreateTimeEntry's own docblock — never a bare `TimeEntry::create()`)
 * and the Start Timer action (TimeTrackingService::start()).
 *
 * `update()` additionally requires `status === Draft` — TimeEntry
 * carries no dedicated "edit" service method (confirmed by direct
 * source read of TimeEntryApprovalService), but ExpenseService::
 * editWhileDraft()'s sibling business rule ("only a draft may be
 * edited") applies with equal force here: once submitted, `seconds`/
 * `is_billable`/`matter_id`/etc. feed directly into
 * TimeEntryApprovalService::approve()'s billing-rate snapshot and,
 * eventually, invoicing — editing a submitted/approved/rejected/
 * invoiced entry outside that lifecycle would silently desynchronize
 * it. Status itself is never an editable field (see TimeEntryResource's
 * own docblock) — all transitions are row Actions
 * (SubmitTimeEntryAction/ApproveTimeEntryAction/RejectTimeEntryAction)
 * calling TimeEntryApprovalService directly.
 */
class TimeEntryPolicy
{
    public function __construct(
        private readonly TimeExpenseAccessPolicyService $accessPolicy,
    ) {}

    public function viewAny(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canViewTimeEntry($firmUser->role);
    }

    public function view(User $user, TimeEntry $timeEntry): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $timeEntry->firm_id
            && $this->accessPolicy->canViewTimeEntry($firmUser->role);
    }

    public function create(User $user): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null && $this->accessPolicy->canManageTimeEntry($firmUser->role);
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        $firmUser = $user->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $timeEntry->firm_id
            && $this->accessPolicy->canManageTimeEntry($firmUser->role)
            && $timeEntry->status === TimeEntryStatus::Draft;
    }
}
