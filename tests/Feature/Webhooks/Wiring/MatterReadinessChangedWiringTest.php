<?php

namespace Tests\Feature\Webhooks\Wiring;

use App\Enums\MatterReadinessStatus;
use App\Enums\ReadinessComponentStatus;
use App\Enums\WebhookEventType;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Models\ReadinessScorecardComponent;
use App\Services\MatterReadinessService;
use App\Services\ReadinessScorecardRegistry;
use App\Services\WebhookEventRecorderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * matter.readiness_changed is wired at the single real owner (Phase
 * 14b decision K): MatterReadinessService::recompute(), firing ONLY
 * when previous_status !== new_status — never on every recompute()
 * call, and never sourced from ReadinessScoreEvent (which logs every
 * call unconditionally and lacks satisfied_count/total_count).
 */
class MatterReadinessChangedWiringTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

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

    public function test_matter_readiness_changed_fires_on_the_first_recompute_from_null_to_not_ready(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $score = $this->service->recompute($matter);

        $this->assertSame(MatterReadinessStatus::NotReady, $score->status);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'event_type' => WebhookEventType::MatterReadinessChanged->value,
            'subject_type' => MatterReadinessScore::class,
            'subject_id' => $score->id,
        ]);
    }

    public function test_matter_readiness_changed_does_not_fire_on_a_no_op_recompute(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        // First call: null -> not_ready, fires once.
        $this->service->recompute($matter);
        $this->assertDatabaseCount('webhook_events', 1);

        // Second call with nothing changed: not_ready -> not_ready, must not fire again.
        $this->service->recompute($this->runWithFirmContext($firm, fn () => $matter->fresh()));

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_matter_readiness_changed_fires_again_when_status_actually_changes(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $this->service->recompute($matter); // null -> not_ready
        $this->assertDatabaseCount('webhook_events', 1);

        $this->activateAllDefaultComponents(); // will make every component satisfied for a fresh matter
        $this->service->recompute($this->runWithFirmContext($firm, fn () => $matter->fresh())); // not_ready -> ready

        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertDatabaseHas('webhook_events', ['event_type' => WebhookEventType::MatterReadinessChanged->value]);
    }

    public function test_recorder_exception_does_not_break_recompute(): void
    {
        $this->mock(WebhookEventRecorderService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('simulated recorder failure'));
        });

        $firm = $this->makeWebhookEntitledFirm();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $score = $this->service->recompute($matter);

        $this->assertSame(MatterReadinessStatus::NotReady, $score->status);
        $this->assertDatabaseHas('matter_readiness_scores', ['matter_id' => $matter->id]);
    }
}
