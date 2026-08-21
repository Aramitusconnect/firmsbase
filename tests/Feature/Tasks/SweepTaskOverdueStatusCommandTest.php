<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Console\Commands\SweepTaskOverdueStatusCommand;
use App\Enums\FirmActivationStatus;
use App\Enums\TaskStatus;
use App\Models\Firm;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SweepTaskOverdueStatusCommandTest — Mission 5B (5.9).
 * `TaskService::refreshOverdueStatus()` already existed, was already
 * idempotent, and already correctly derived Overdue from `due_at` —
 * it was simply never called by any scheduled command (confirmed by
 * this mission's own exhaustive grep), unlike Deadline's own
 * `refreshMissedStatus()`, which a prior mission already wired into
 * `automation:sweep:deadlines`. This proves the new
 * `automation:sweep:task-overdue` command is the first real caller,
 * and that it never touches a task outside its own firm or outside
 * Open/InProgress.
 */
class SweepTaskOverdueStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private function command(): SweepTaskOverdueStatusCommand
    {
        return new SweepTaskOverdueStatusCommand(new TaskService);
    }

    public function test_sweeping_an_overdue_open_task_flips_it_to_overdue(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->overdue()->create([
            'firm_id' => $firm->id,
            'status' => TaskStatus::Open,
        ]));

        $this->command()->handle();

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame(TaskStatus::Overdue, $fresh->status);
    }

    public function test_sweeping_an_in_progress_task_past_due_also_flips_it_to_overdue(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->overdue()->create([
            'firm_id' => $firm->id,
            'status' => TaskStatus::InProgress,
        ]));

        $this->command()->handle();

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame(TaskStatus::Overdue, $fresh->status);
    }

    public function test_sweeping_a_task_not_yet_due_leaves_it_open(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->create([
            'firm_id' => $firm->id,
            'status' => TaskStatus::Open,
            'due_at' => now()->addDays(3),
        ]));

        $this->command()->handle();

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame(TaskStatus::Open, $fresh->status);
    }

    public function test_sweeping_a_completed_task_never_touches_it_even_if_its_due_at_has_passed(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->overdue()->completed()->create(['firm_id' => $firm->id]));

        $this->command()->handle();

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame(TaskStatus::Completed, $fresh->status);
    }

    public function test_a_non_activated_firms_overdue_task_is_never_swept(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft]);

        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->overdue()->create([
            'firm_id' => $firm->id,
            'status' => TaskStatus::Open,
        ]));

        $this->command()->handle();

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame(TaskStatus::Open, $fresh->status, 'A firm not yet Activated must never be swept.');
    }
}
