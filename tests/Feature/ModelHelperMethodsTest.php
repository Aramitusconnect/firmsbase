<?php

namespace Tests\Feature;

use App\Enums\DocumentChaseRuleStatus;
use App\Enums\ReadinessComponentStatus;
use App\Models\DocumentChaseRule;
use App\Models\ReadinessScorecardComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Small helper-method checks that don't warrant their own dedicated
 * test class per model.
 */
class ModelHelperMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_chase_rule_is_active_reflects_status(): void
    {
        $active = DocumentChaseRule::factory()->create(['status' => DocumentChaseRuleStatus::Active]);
        $paused = DocumentChaseRule::factory()->create(['status' => DocumentChaseRuleStatus::Paused]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($paused->isActive());
    }

    public function test_readiness_scorecard_component_is_active_reflects_status(): void
    {
        $active = ReadinessScorecardComponent::factory()->create(['status' => ReadinessComponentStatus::Active]);
        $inactive = ReadinessScorecardComponent::factory()->inactive()->create();

        $this->assertTrue($active->isActive());
        $this->assertFalse($inactive->isActive());
    }

    public function test_readiness_scorecard_component_key_is_globally_unique(): void
    {
        ReadinessScorecardComponent::factory()->create(['component_key' => 'intake_complete']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ReadinessScorecardComponent::factory()->create(['component_key' => 'intake_complete']);
    }
}
