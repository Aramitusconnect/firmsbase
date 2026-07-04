<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Models\Firm;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaskService();
    }

    public function test_create_starts_a_task_open(): void
    {
        $firm = Firm::factory()->create();

        $task = $this->service->create($firm, 'File the I-130 petition');

        $this->assertSame(TaskStatus::Open, $task->status);
    }

    public function test_complete_is_refused_while_blocked(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Blocked]);

        $this->expectException(\RuntimeException::class);
        $this->service->complete($task);
    }

    public function test_refresh_overdue_status_derives_overdue_from_due_at(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Open, 'due_at' => now()->subDay()]);

        $refreshed = $this->service->refreshOverdueStatus($task);

        $this->assertSame(TaskStatus::Overdue, $refreshed->status);
    }

    public function test_refresh_overdue_status_never_overrides_a_terminal_status(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Completed, 'due_at' => now()->subDay(), 'completed_at' => now()]);

        $refreshed = $this->service->refreshOverdueStatus($task);

        $this->assertSame(TaskStatus::Completed, $refreshed->status);
    }

    public function test_overdue_is_never_directly_settable_via_create(): void
    {
        // TaskService::create() only ever writes TaskStatus::Open —
        // there is no parameter that accepts an initial status.
        $firm = Firm::factory()->create();

        $task = $this->service->create($firm, 'Some task', dueAt: now()->subDay());

        $this->assertSame(TaskStatus::Open, $task->status);
    }
}
