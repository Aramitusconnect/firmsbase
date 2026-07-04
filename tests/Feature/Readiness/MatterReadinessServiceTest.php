<?php

namespace Tests\Feature\Readiness;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\MatterReadinessStatus;
use App\Enums\ReadinessComponentStatus;
use App\Enums\TaskStatus;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Matter;
use App\Models\ReadinessScorecardComponent;
use App\Models\Task;
use App\Services\MatterReadinessService;
use App\Services\ReadinessScorecardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MatterReadinessService(new ReadinessScorecardRegistry());
    }

    private function activateAllDefaultComponents(): void
    {
        foreach (['intake_complete', 'documents_approved', 'tasks_dependencies_ready', 'attorney_review_status'] as $key) {
            ReadinessScorecardComponent::factory()->create(['component_key' => $key, 'status' => ReadinessComponentStatus::Active]);
        }
    }

    public function test_recompute_with_no_active_components_yields_not_ready(): void
    {
        $matter = Matter::factory()->create();

        $score = $this->service->recompute($matter);

        $this->assertSame(MatterReadinessStatus::NotReady, $score->status);
        $this->assertSame(0, $score->total_count);
    }

    public function test_recompute_is_ready_when_every_active_component_is_satisfied(): void
    {
        $this->activateAllDefaultComponents();
        $matter = Matter::factory()->create(['assigned_attorney_id' => \App\Models\User::factory()->create()->id, 'status' => \App\Enums\MatterStatus::ReadyForReview]);

        $score = $this->service->recompute($matter);

        $this->assertSame(MatterReadinessStatus::Ready, $score->status);
        $this->assertSame(4, $score->total_count);
        $this->assertSame(4, $score->satisfied_count);
    }

    public function test_recompute_is_partially_ready_when_some_components_fail(): void
    {
        $this->activateAllDefaultComponents();
        $matter = Matter::factory()->create(['assigned_attorney_id' => null]); // attorney_review_status fails

        $request = DocumentRequest::factory()->create(['matter_id' => $matter->id]);
        DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'is_required' => true,
            'status' => DocumentRequestItemStatus::Approved,
        ]);
        Task::factory()->create(['matter_id' => $matter->id, 'status' => TaskStatus::Completed, 'completed_at' => now()]);

        $score = $this->service->recompute($matter);

        $this->assertSame(MatterReadinessStatus::PartiallyReady, $score->status);
        $this->assertLessThan($score->total_count, $score->satisfied_count);
    }

    public function test_recompute_logs_a_readiness_score_event_with_previous_and_new_status(): void
    {
        $this->activateAllDefaultComponents();
        $matter = Matter::factory()->create();

        $this->service->recompute($matter);
        $this->service->recompute($matter);

        $this->assertSame(2, \App\Models\ReadinessScoreEvent::query()->where('matter_id', $matter->id)->count());
    }

    public function test_recompute_upserts_a_single_row_per_matter(): void
    {
        $this->activateAllDefaultComponents();
        $matter = Matter::factory()->create();

        $this->service->recompute($matter);
        $this->service->recompute($matter);

        $this->assertSame(1, \App\Models\MatterReadinessScore::query()->where('matter_id', $matter->id)->count());
    }

    public function test_documents_approved_fails_while_a_required_item_is_still_outstanding(): void
    {
        $this->activateAllDefaultComponents();
        $matter = Matter::factory()->create();
        $request = DocumentRequest::factory()->create(['matter_id' => $matter->id]);
        DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'is_required' => true,
            'status' => DocumentRequestItemStatus::Requested,
        ]);

        $score = $this->service->recompute($matter);

        $componentResult = collect($score->breakdown_json)->firstWhere('component_key', 'documents_approved');
        $this->assertFalse($componentResult['satisfied']);
    }
}
