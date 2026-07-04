<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanService();
    }

    public function test_create_defaults_to_draft_and_active_flag(): void
    {
        $plan = $this->service->create(['name' => 'Solo Practice', 'price_cents' => 9900]);

        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertTrue($plan->is_active);
        $this->assertSame('Solo Practice', $plan->name);
    }

    public function test_update_changes_editable_fields(): void
    {
        $plan = Plan::factory()->draft()->create(['name' => 'Old Name']);

        $updated = $this->service->update($plan, ['name' => 'New Name', 'price_cents' => 14900]);

        $this->assertSame('New Name', $updated->name);
        $this->assertSame(14900, $updated->price_cents);
    }

    public function test_activate_moves_draft_to_active(): void
    {
        $plan = Plan::factory()->draft()->create();

        $activated = $this->service->activate($plan);

        $this->assertSame(PlanStatus::Active, $activated->status);
    }

    public function test_archive_deactivates_the_plan(): void
    {
        $plan = Plan::factory()->create();

        $archived = $this->service->archive($plan);

        $this->assertSame(PlanStatus::Archived, $archived->status);
        $this->assertFalse($archived->is_active);
    }
}
