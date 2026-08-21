<?php

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Enums\TaskStatus;
use App\Models\Firm;
use App\Models\Task;
use App\Services\TaskService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * automation:sweep:task-overdue — Mission 5B (5.9). Same "no real
 * mutation call site to hook into, so sweep" rationale as
 * SweepDeadlineEventsCommand: `TaskService::refreshOverdueStatus()`
 * already exists, is idempotent, and correctly derives Overdue from
 * `due_at` (confirmed by direct source read) — it was simply never
 * called by anything in production (confirmed by exhaustive grep),
 * unlike Deadline's own `refreshMissedStatus()`, which a prior mission
 * already wired into SweepDeadlineEventsCommand. This command is the
 * first real caller.
 *
 * Deliberately narrower than SweepDeadlineEventsCommand: no domain
 * event is emitted here — this mission's own scope is only the
 * scheduling/state side ("finding 8"/5.10 note: making a status change
 * actually notify anyone is explicitly Mission 6's job, not this
 * command's). Every Open/InProgress Task for every activated firm is
 * refreshed once daily; refreshOverdueStatus() itself is a no-op for
 * any task not in one of those two statuses, and for one whose due_at
 * has not yet passed, so re-running this sweep never regresses a task
 * that is already Completed/Cancelled/Blocked or not yet due.
 */
final class SweepTaskOverdueStatusCommand extends Command
{
    protected $signature = 'automation:sweep:task-overdue';

    protected $description = 'Refreshes Overdue status for every open/in-progress Task, for every activated firm.';

    public function __construct(
        private readonly TaskService $tasks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->cursor()
            ->each(fn (Firm $firm) => $this->sweepFirm($firm));

        return self::SUCCESS;
    }

    private function sweepFirm(Firm $firm): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            Task::query()
                ->where('firm_id', $firm->id)
                ->whereIn('status', [TaskStatus::Open, TaskStatus::InProgress])
                ->get()
                ->each(fn (Task $task) => $this->tasks->refreshOverdueStatus($task));
        });
    }
}
