<?php

namespace App\Services\Leverage;

use App\Enums\DeadlineStatus;
use App\Enums\FirmUserStatus;
use App\Enums\MatterStatus;
use App\Enums\TaskStatus;
use App\Models\Deadline;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

/**
 * StaffUtilizationService — Leverage Ratio Optimizer, item 17.
 * Deliberately reports ACTUAL WORKLOAD metrics only — recorded hours,
 * billable/nonbillable split, active matter count, task load — never
 * a fabricated "utilization %", since no working-hours/capacity
 * configuration exists anywhere in this codebase (confirmed by this
 * pass's own audit) and inventing one was judged not worth the schema
 * addition for this pass. If a Firm's own capacity expectations are
 * added in a future pass, a true utilization percentage becomes
 * possible; until then, real workload numbers are the honest,
 * deterministic deliverable item 17 itself explicitly permits
 * ("if no capacity model exists: report actual workload metrics
 * first").
 */
class StaffUtilizationService
{
    /**
     * @return array<string, mixed>
     */
    public function workloadFor(Firm $firm, FirmUser $firmUser, ?Carbon $since = null): array
    {
        $since ??= now()->subDays(30);

        $entries = TimeEntry::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $firmUser->user_id)
            ->where('worked_on', '>=', $since->toDateString())
            ->get(['seconds', 'is_billable']);

        $billableSeconds = (int) $entries->where('is_billable', true)->sum('seconds');
        $nonBillableSeconds = (int) $entries->where('is_billable', false)->sum('seconds');

        $activeMatterCount = Matter::query()
            ->where('firm_id', $firm->id)
            ->where('assigned_attorney_id', $firmUser->user_id)
            ->whereNotIn('status', [MatterStatus::Closed, MatterStatus::Archived])
            ->count();

        $openTaskCount = Task::query()
            ->where('firm_id', $firm->id)
            ->where('assigned_to', $firmUser->user_id)
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])
            ->count();

        $overdueTaskCount = Task::query()
            ->where('firm_id', $firm->id)
            ->where('assigned_to', $firmUser->user_id)
            ->where('status', TaskStatus::Overdue->value)
            ->count();

        $deadlineLoad = $this->deadlineLoad($firm, $firmUser);

        return [
            'user_id' => $firmUser->user_id,
            'role' => $firmUser->role,
            'since' => $since->toDateString(),
            'billable_hours' => round($billableSeconds / 3600, 1),
            'non_billable_hours' => round($nonBillableSeconds / 3600, 1),
            'recorded_hours' => round(($billableSeconds + $nonBillableSeconds) / 3600, 1),
            'active_matter_count' => $activeMatterCount,
            'open_task_count' => $openTaskCount,
            'overdue_task_count' => $overdueTaskCount,
            'deadline_load' => $deadlineLoad,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workloadForFirm(Firm $firm, ?Carbon $since = null): array
    {
        return FirmUser::query()
            ->where('firm_id', $firm->id)
            ->where('status', FirmUserStatus::Active)
            ->get()
            ->map(fn (FirmUser $firmUser) => $this->workloadFor($firm, $firmUser, $since))
            ->all();
    }

    /**
     * Deadlines carry no direct assignee column of their own (confirmed
     * by audit) — load is attributed via the deadline's own Matter's
     * assigned_attorney_id, the one real, existing responsibility link.
     */
    private function deadlineLoad(Firm $firm, FirmUser $firmUser): int
    {
        return Deadline::query()
            ->where('firm_id', $firm->id)
            ->whereIn('status', [DeadlineStatus::Upcoming, DeadlineStatus::Due])
            ->whereHas('matter', fn ($q) => $q->where('assigned_attorney_id', $firmUser->user_id))
            ->count();
    }
}
