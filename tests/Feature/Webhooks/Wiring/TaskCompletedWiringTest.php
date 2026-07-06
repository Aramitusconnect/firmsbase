<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\TaskStatus;
use App\Enums\WebhookEventType;
use App\Models\Task;
use App\Services\TaskService;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * task.completed is wired at the single real owner (Phase 14b decision
 * H): TaskService::complete(), confirmed via exhaustive grep to be the
 * only place App\Models\Task ever transitions to TaskStatus::Completed.
 */
class TaskCompletedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

    public function test_task_completed_fires_exactly_once_on_successful_completion(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $task = Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Open]);
        $service = new TaskService();

        $service->complete($task);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::TaskCompleted->value,
            'subject_type' => Task::class,
            'subject_id' => $task->id,
        ]);
    }

    public function test_task_completed_does_not_fire_when_completion_is_refused_while_blocked(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $task = Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Blocked]);
        $service = new TaskService();

        try {
            $service->complete($task);
            $this->fail('Expected a RuntimeException for a blocked task.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(TaskStatus::Blocked, $task->fresh()->status);
    }

    public function test_recorder_exception_does_not_break_task_completion(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $task = Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Open]);
        $service = new TaskService();

        $service->complete($task);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }
}
