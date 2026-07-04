<?php

namespace Tests\Feature\Readiness;

use App\Enums\ReadinessComponentStatus;
use App\Models\Matter;
use App\Models\ReadinessScorecardComponent;
use App\Services\ReadinessScorecardRegistry;
use App\ValueObjects\ReadinessComponentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessScorecardRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_four_default_components_are_registered_out_of_the_box(): void
    {
        $registry = new ReadinessScorecardRegistry();

        $this->assertTrue($registry->isRegistered('intake_complete'));
        $this->assertTrue($registry->isRegistered('documents_approved'));
        $this->assertTrue($registry->isRegistered('tasks_dependencies_ready'));
        $this->assertTrue($registry->isRegistered('attorney_review_status'));
    }

    public function test_evaluate_only_returns_components_that_are_both_registered_and_active_in_the_catalog(): void
    {
        $registry = new ReadinessScorecardRegistry();
        $matter = Matter::factory()->create();

        // No catalog rows exist yet at all — evaluate() must return
        // nothing, even though 4 evaluators are registered in code.
        $this->assertSame([], $registry->evaluate($matter));

        ReadinessScorecardComponent::factory()->create(['component_key' => 'intake_complete', 'status' => ReadinessComponentStatus::Active]);
        ReadinessScorecardComponent::factory()->create(['component_key' => 'documents_approved', 'status' => ReadinessComponentStatus::Inactive]);

        $results = $registry->evaluate($matter);

        $this->assertCount(1, $results);
        $this->assertSame('intake_complete', $results[0]->componentKey);
    }

    /**
     * The required acceptance-criterion proof: a brand-new readiness
     * component can be registered and evaluated with ONLY a data row
     * (readiness_scorecard_components) plus a register() call — no
     * migration, no new column, no new table.
     */
    public function test_a_new_component_can_be_registered_and_evaluated_without_any_schema_change(): void
    {
        $registry = new ReadinessScorecardRegistry();
        $matter = Matter::factory()->create();

        $this->assertFalse($registry->isRegistered('fees_paid'));

        // Purely a data row — no migration was written or run for this.
        ReadinessScorecardComponent::factory()->create([
            'component_key' => 'fees_paid',
            'status' => ReadinessComponentStatus::Active,
            'introduced_in_phase' => 'phase_6_hypothetical',
        ]);

        // Purely code — a new evaluator callable, registered at runtime.
        $registry->register('fees_paid', fn (Matter $m) => new ReadinessComponentResult('fees_paid', true, 'all fees paid (test double)'));

        $this->assertTrue($registry->isRegistered('fees_paid'));

        $results = $registry->evaluate($matter);
        $keys = array_map(fn ($r) => $r->componentKey, $results);

        $this->assertContains('fees_paid', $keys);
    }

    public function test_a_catalog_row_without_a_registered_evaluator_is_silently_skipped(): void
    {
        $registry = new ReadinessScorecardRegistry();
        $matter = Matter::factory()->create();

        ReadinessScorecardComponent::factory()->create([
            'component_key' => 'signatures_complete',
            'status' => ReadinessComponentStatus::Active,
        ]);

        $results = $registry->evaluate($matter);
        $keys = array_map(fn ($r) => $r->componentKey, $results);

        $this->assertNotContains('signatures_complete', $keys);
    }
}
