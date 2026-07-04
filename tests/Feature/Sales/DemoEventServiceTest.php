<?php

namespace Tests\Feature\Sales;

use App\Enums\DemoEventStatus;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\PlatformAdmin;
use App\Services\DemoEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private DemoEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DemoEventService();
    }

    public function test_schedule_creates_a_demo_event_and_updates_opportunity_status(): void
    {
        $opportunity = Opportunity::factory()->create();

        $demo = $this->service->schedule($opportunity, now()->addDays(2));

        $this->assertSame(DemoEventStatus::Scheduled, $demo->status);
        $this->assertSame(OpportunityStatus::DemoScheduled, $opportunity->fresh()->status);
    }

    public function test_mark_held_records_conducted_by_and_completion(): void
    {
        $opportunity = Opportunity::factory()->create();
        $demo = $this->service->schedule($opportunity, now()->addDay());
        $conductor = PlatformAdmin::factory()->create();

        $held = $this->service->markHeld($demo, $conductor, 'Went well');

        $this->assertSame(DemoEventStatus::Completed, $held->status);
        $this->assertSame($conductor->id, $held->conducted_by);
        $this->assertNotNull($held->held_at);
    }

    public function test_mark_no_show_and_cancel(): void
    {
        $opportunity = Opportunity::factory()->create();
        $demoA = $this->service->schedule($opportunity, now()->addDay());
        $demoB = $this->service->schedule($opportunity, now()->addDays(2));

        $this->assertSame(DemoEventStatus::NoShow, $this->service->markNoShow($demoA)->status);
        $this->assertSame(DemoEventStatus::Cancelled, $this->service->cancel($demoB)->status);
    }
}
