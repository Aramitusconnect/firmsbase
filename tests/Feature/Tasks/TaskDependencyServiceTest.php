<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Models\Firm;
use App\Models\Task;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDependencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskDependencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaskDependencyService();
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $task = Task::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->addDependency($task, $task);
    }

    public function test_adding_a_direct_dependency_marks_the_dependent_task_blocked(): void
    {
        $firm = Firm::factory()->create();
        $a = Task::factory()->create(['firm_id' => $firm->id]);
        $b = Task::factory()->create(['firm_id' => $firm->id]);

        $this->service->addDependency($a, $b);

        $this->assertSame(TaskStatus::Blocked, $a->fresh()->status);
    }

    /**
     * The required cycle-rejection proof: A depends on B, B depends on
     * C. Then trying to make C depend on A (closing the loop
     * A -> B -> C -> A) must be refused BEFORE any row is inserted.
     */
    public function test_a_transitive_three_task_cycle_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $taskA = Task::factory()->create(['firm_id' => $firm->id]);
        $taskB = Task::factory()->create(['firm_id' => $firm->id]);
        $taskC = Task::factory()->create(['firm_id' => $firm->id]);

        $this->service->addDependency($taskA, $taskB); // A depends on B
        $this->service->addDependency($taskB, $taskC); // B depends on C

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->addDependency($taskC, $taskA); // would close the cycle
        } finally {
            $this->assertSame(
                0,
                \App\Models\TaskDependency::query()
                    ->where('task_id', $taskC->id)
                    ->where('blocked_by_task_id', $taskA->id)
                    ->count(),
                'the cycle-closing dependency row must never be inserted'
            );
        }
    }

    public function test_a_direct_two_task_cycle_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $a = Task::factory()->create(['firm_id' => $firm->id]);
        $b = Task::factory()->create(['firm_id' => $firm->id]);

        $this->service->addDependency($a, $b);

        $this->expectException(\RuntimeException::class);
        $this->service->addDependency($b, $a);
    }

    public function test_blocked_status_clears_once_the_blocking_task_completes(): void
    {
        $firm = Firm::factory()->create();
        $a = Task::factory()->create(['firm_id' => $firm->id]);
        $b = Task::factory()->create(['firm_id' => $firm->id]);

        $this->service->addDependency($a, $b);
        $this->assertSame(TaskStatus::Blocked, $a->fresh()->status);

        $b->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
        $this->service->refreshBlockedStatus($a->fresh());

        $this->assertSame(TaskStatus::Open, $a->fresh()->status);
    }

    public function test_remove_dependency_recomputes_blocked_status(): void
    {
        $firm = Firm::factory()->create();
        $a = Task::factory()->create(['firm_id' => $firm->id]);
        $b = Task::factory()->create(['firm_id' => $firm->id]);

        $this->service->addDependency($a, $b);
        $this->service->removeDependency($a, $b);

        $this->assertSame(TaskStatus::Open, $a->fresh()->status);
    }

    public function test_blocked_status_never_overrides_a_completed_task(): void
    {
        $firm = Firm::factory()->create();
        $a = Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Completed, 'completed_at' => now()]);
        $b = Task::factory()->create(['firm_id' => $firm->id]);

        \App\Models\TaskDependency::factory()->between($a, $b)->create();

        $this->service->refreshBlockedStatus($a->fresh());

        $this->assertSame(TaskStatus::Completed, $a->fresh()->status);
    }
}
