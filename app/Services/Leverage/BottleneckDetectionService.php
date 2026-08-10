<?php

namespace App\Services\Leverage;

use App\Enums\DeadlineStatus;
use App\Enums\DocumentRequestItemStatus;
use App\Enums\FirmUserStatus;
use App\Enums\TaskStatus;
use App\Models\Deadline;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Task;

/**
 * BottleneckDetectionService — Leverage Ratio Optimizer, item 18. Every
 * signal here is derived from structured, already-existing workflow
 * data (Task/Deadline/DocumentRequestItem status + timestamps) —
 * deliberately NEVER inferred from free-text activity, per the master
 * spec's own explicit instruction.
 */
class BottleneckDetectionService
{
    private const OVERDUE_TASK_BOTTLENECK_FLOOR = 5;

    private const DEADLINE_CONCENTRATION_FLOOR = 5;

    private const STALLED_DOCUMENT_REQUEST_DAYS = 14;

    private const NON_TERMINAL_DOCUMENT_ITEM_STATUSES = [
        DocumentRequestItemStatus::Requested,
        DocumentRequestItemStatus::Viewed,
        DocumentRequestItemStatus::Submitted,
        DocumentRequestItemStatus::UnderReview,
        DocumentRequestItemStatus::NeedsReplacement,
    ];

    /**
     * Staff members with an overdue-task backlog at or above the
     * bottleneck floor.
     *
     * @return array<int, array{user_id: int, overdue_task_count: int}>
     */
    public function staffWithOverdueTaskBacklog(Firm $firm): array
    {
        return Task::query()
            ->where('firm_id', $firm->id)
            ->where('status', TaskStatus::Overdue->value)
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to as user_id, count(*) as overdue_task_count')
            ->groupBy('assigned_to')
            ->havingRaw('count(*) >= ?', [self::OVERDUE_TASK_BOTTLENECK_FLOOR])
            ->get()
            ->map(fn ($row) => ['user_id' => (int) $row->user_id, 'overdue_task_count' => (int) $row->overdue_task_count])
            ->all();
    }

    /**
     * Attorneys whose own Matters carry a heavy concentration of
     * upcoming/due deadlines — see StaffUtilizationService's own
     * docblock on why Matter.assigned_attorney_id is the attribution
     * point (Deadline itself carries no assignee column).
     *
     * @return array<int, array{user_id: int, deadline_count: int}>
     */
    public function deadlineConcentration(Firm $firm): array
    {
        return Deadline::query()
            ->where('deadlines.firm_id', $firm->id)
            ->whereIn('deadlines.status', [DeadlineStatus::Upcoming->value, DeadlineStatus::Due->value])
            ->join('matters', 'matters.id', '=', 'deadlines.matter_id')
            ->whereNotNull('matters.assigned_attorney_id')
            ->selectRaw('matters.assigned_attorney_id as user_id, count(*) as deadline_count')
            ->groupBy('matters.assigned_attorney_id')
            ->havingRaw('count(*) >= ?', [self::DEADLINE_CONCENTRATION_FLOOR])
            ->get()
            ->map(fn ($row) => ['user_id' => (int) $row->user_id, 'deadline_count' => (int) $row->deadline_count])
            ->all();
    }

    /**
     * Document request items sitting in a non-terminal status for
     * longer than the staleness floor — "days stalled" derived from
     * updated_at (the item's own last real status change), never
     * stored separately.
     *
     * @return array<int, array{document_request_item_id: int, matter_id: ?int, status: string, days_stalled: int}>
     */
    public function stalledDocumentRequestItems(Firm $firm): array
    {
        $statusValues = array_map(fn (DocumentRequestItemStatus $s) => $s->value, self::NON_TERMINAL_DOCUMENT_ITEM_STATUSES);
        $cutoff = now()->subDays(self::STALLED_DOCUMENT_REQUEST_DAYS);

        return DocumentRequestItem::query()
            ->whereHas('documentRequest', fn ($q) => $q->where('firm_id', $firm->id))
            ->whereIn('status', $statusValues)
            ->where('updated_at', '<=', $cutoff)
            ->with('documentRequest:id,matter_id')
            ->get()
            ->map(fn (DocumentRequestItem $item) => [
                'document_request_item_id' => $item->id,
                'matter_id' => $item->documentRequest?->matter_id,
                'status' => $item->status->value,
                'days_stalled' => (int) $item->updated_at->diffInDays(now()),
            ])
            ->all();
    }

    /**
     * Firm-wide count of Tasks with no assignee at all — a structural
     * bottleneck signal distinct from any one staff member's backlog.
     */
    public function unassignedTaskCount(Firm $firm): int
    {
        return Task::query()
            ->where('firm_id', $firm->id)
            ->whereNull('assigned_to')
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->count();
    }

    /**
     * @return array<int, FirmUser>
     */
    public function activeFirmUsers(Firm $firm): array
    {
        return FirmUser::query()
            ->where('firm_id', $firm->id)
            ->where('status', FirmUserStatus::Active)
            ->get()
            ->all();
    }
}
