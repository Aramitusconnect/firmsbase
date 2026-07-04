<?php

namespace Tests\Feature\Sales;

use App\Enums\PlatformSalesTaskStatus;
use App\Models\PlatformLead;
use App\Services\PlatformSalesTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSalesTaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSalesTaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformSalesTaskService();
    }

    public function test_create_writes_an_open_task_against_a_platform_lead(): void
    {
        $lead = PlatformLead::factory()->create();

        $task = $this->service->create($lead, 'Call back Tuesday');

        $this->assertSame(PlatformSalesTaskStatus::Open, $task->status);
        $this->assertSame(PlatformLead::class, $task->taskable_type);
        $this->assertSame($lead->id, $task->taskable_id);
    }

    public function test_complete_and_cancel(): void
    {
        $lead = PlatformLead::factory()->create();
        $taskA = $this->service->create($lead, 'Follow up A');
        $taskB = $this->service->create($lead, 'Follow up B');

        $completed = $this->service->complete($taskA);
        $cancelled = $this->service->cancel($taskB);

        $this->assertSame(PlatformSalesTaskStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(PlatformSalesTaskStatus::Cancelled, $cancelled->status);
    }

    public function test_platform_sales_tasks_are_not_the_legal_workflow_tasks_table(): void
    {
        $lead = PlatformLead::factory()->create();
        $this->service->create($lead, 'Follow up');

        $this->assertDatabaseCount('platform_sales_tasks', 1);
        $this->assertDatabaseCount('tasks', 0);
    }
}
