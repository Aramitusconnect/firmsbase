<?php

namespace Tests\Feature\Implementation;

use App\Enums\ImplementationProjectStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\ImplementationProjectService;
use App\Services\ImplementationTaskService;
use App\Services\TenantContextService;
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
        $this->projectService = new ImplementationProjectService;
        $this->taskService = new ImplementationTaskService;
    }

    public function test_completing_a_task_moves_project_into_in_progress(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->projectService->createForFirm($firm);
        $admin = PlatformAdmin::factory()->create();

        // ImplementationTaskService::complete() now requires the already-known
        // ImplementationProject as an explicit second parameter (implementation_tasks
        // has no firm_id of its own to key a wrap on before a lazy relation load,
        // and a general "look up any implementation_project_id's firm_id" RLS
        // clause is prohibited as an information-leak widening).
        $this->taskService->complete($project->tasks->first(), $project, $admin);

        // implementation_projects now carries FORCE ROW LEVEL SECURITY
        // (Wave 9, see database/migrations/2026_08_29_970004_prepare_row_
        // level_security_and_force_rls_on_implementation_projects_table.php)
        // — complete()'s own wrap has already exited and restored
        // database session context to "none" by this point, so a bare
        // ->fresh() call would return null. Wrap it explicitly, keyed
        // on the firm this project belongs to.
        $this->assertSame(
            ImplementationProjectStatus::InProgress,
            (new TenantContextService)->runWithFirmContext($firm, fn () => $project->fresh()->status)
        );
    }

    public function test_completing_every_required_task_completes_the_project(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->projectService->createForFirm($firm);
        $admin = PlatformAdmin::factory()->create();

        foreach ($project->tasks as $task) {
            $this->taskService->complete($task, $project, $admin);
        }

        // Same FORCE-RLS-driven fix as above: bare ->fresh() with no
        // ambient context active would return null.
        $refreshed = (new TenantContextService)->runWithFirmContext($firm, fn () => $project->fresh());
        $this->assertSame(ImplementationProjectStatus::Completed, $refreshed->status);
        $this->assertNotNull($refreshed->completed_at);
    }

    public function test_blocking_a_task_blocks_the_project(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->projectService->createForFirm($firm);

        $this->taskService->block($project->tasks->first(), $project);

        // Same FORCE-RLS-driven fix as above.
        $this->assertSame(
            ImplementationProjectStatus::Blocked,
            (new TenantContextService)->runWithFirmContext($firm, fn () => $project->fresh()->status)
        );
    }
}
