<?php

namespace Tests\Feature\Implementation;

use App\Enums\ImplementationProjectStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\ImplementationProjectService;
use App\Services\ImplementationTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImplementationTaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImplementationProjectService $projectService;
    private ImplementationTaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectService = new ImplementationProjectService();
        $this->taskService = new ImplementationTaskService();
    }

    public function test_completing_a_task_moves_project_into_in_progress(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->projectService->createForFirm($firm);
        $admin = PlatformAdmin::factory()->create();

        $this->taskService->complete($project->tasks->first(), $admin);

        $this->assertSame(ImplementationProjectStatus::InProgress, $project->fresh()->status);
    }

    public function test_completing_every_required_task_completes_the_project(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->projectService->createForFirm($firm);
        $admin = PlatformAdmin::factory()->create();

        foreach ($project->tasks as $task) {
            $this->taskService->complete($task, $admin);
        }

        $this->assertSame(ImplementationProjectStatus::Completed, $project->fresh()->status);
        $this->assertNotNull($project->fresh()->completed_at);
    }

    public function test_blocking_a_task_blocks_the_project(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->projectService->createForFirm($firm);

        $this->taskService->block($project->tasks->first());

        $this->assertSame(ImplementationProjectStatus::Blocked, $project->fresh()->status);
    }
}
