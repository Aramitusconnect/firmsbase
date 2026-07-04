<?php

namespace Tests\Feature\Implementation;

use App\Models\Firm;
use App\Models\ImplementationTask;
use App\Services\ImplementationProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImplementationProjectServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImplementationProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImplementationProjectService();
    }

    public function test_create_for_firm_creates_the_standard_task_set(): void
    {
        $firm = Firm::factory()->create();

        $project = $this->service->createForFirm($firm);

        $this->assertSame($firm->id, $project->firm_id);
        $this->assertCount(count(ImplementationTask::TASK_KEYS), $project->tasks);

        foreach (ImplementationTask::TASK_KEYS as $key) {
            $this->assertTrue($project->tasks->contains(fn (ImplementationTask $t) => $t->task_key === $key));
        }
    }

    public function test_one_project_per_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->service->createForFirm($firm);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->service->createForFirm($firm);
    }

    public function test_mark_go_live_sets_success_review_due_date(): void
    {
        $firm = Firm::factory()->create();
        $project = $this->service->createForFirm($firm);

        $updated = $this->service->markGoLive($project);

        $this->assertNotNull($updated->go_live_at);
        $this->assertNotNull($updated->success_review_due_at);
        $this->assertTrue($updated->success_review_due_at->isSameDay(now()->addDays(30)));
    }
}
