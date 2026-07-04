<?php

namespace Tests\Feature\Sales;

use App\Enums\OpportunityStatus;
use App\Enums\PlatformLeadStatus;
use App\Models\PlatformLead;
use App\Services\ConversionEventService;
use App\Services\OpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityServiceTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpportunityService(new ConversionEventService());
    }

    public function test_create_from_lead_qualifies_the_lead_and_records_a_conversion_event(): void
    {
        $lead = PlatformLead::factory()->create();

        $opportunity = $this->service->createFromLead($lead, ['estimated_seats' => 10]);

        $this->assertSame(OpportunityStatus::Open, $opportunity->status);
        $this->assertSame(PlatformLeadStatus::Qualified, $lead->fresh()->status);
        $this->assertDatabaseHas('conversion_events', [
            'platform_lead_id' => $lead->id,
            'opportunity_id' => $opportunity->id,
            'event_type' => 'lead_to_opportunity',
        ]);
    }

    public function test_mark_won_closes_the_opportunity_and_records_a_conversion_event(): void
    {
        $lead = PlatformLead::factory()->create();
        $opportunity = $this->service->createFromLead($lead);

        $won = $this->service->markWon($opportunity);

        $this->assertSame(OpportunityStatus::Won, $won->status);
        $this->assertNotNull($won->closed_at);
        $this->assertDatabaseHas('conversion_events', ['opportunity_id' => $opportunity->id, 'event_type' => 'opportunity_won']);
    }

    public function test_mark_lost_records_the_reason_and_a_conversion_event(): void
    {
        $lead = PlatformLead::factory()->create();
        $opportunity = $this->service->createFromLead($lead);

        $lost = $this->service->markLost($opportunity, 'Chose a competitor');

        $this->assertSame(OpportunityStatus::Lost, $lost->status);
        $this->assertSame('Chose a competitor', $lost->lost_reason);
        $this->assertDatabaseHas('conversion_events', ['opportunity_id' => $opportunity->id, 'event_type' => 'opportunity_lost']);
    }
}
