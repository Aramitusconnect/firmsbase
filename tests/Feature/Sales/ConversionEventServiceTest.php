<?php

namespace Tests\Feature\Sales;

use App\Models\Organization;
use App\Models\PlatformLead;
use App\Services\ConversionEventService;
use App\Services\DemoEventService;
use App\Services\OpportunityService;
use App\Services\TrialRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the full pipeline: lead -> opportunity -> demo/trial ->
 * conversion/lost, asserting a conversion_events row is written at each
 * transition.
 */
class ConversionEventServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_pipeline_records_a_conversion_event_at_every_stage(): void
    {
        $conversionEventService = new ConversionEventService();
        $opportunityService = new OpportunityService($conversionEventService);
        $demoEventService = new DemoEventService();
        $trialRequestService = new TrialRequestService($conversionEventService);

        $lead = PlatformLead::factory()->create();

        $opportunity = $opportunityService->createFromLead($lead);
        $this->assertDatabaseHas('conversion_events', ['event_type' => 'lead_to_opportunity']);

        $demoEventService->schedule($opportunity, now()->addDay());

        $trial = $trialRequestService->request($opportunity);
        $this->assertDatabaseHas('conversion_events', ['event_type' => 'demo_to_trial']);

        $organization = Organization::factory()->create();
        $trialRequestService->provision($trial, $organization);
        $trialRequestService->activate($trial->fresh());
        $trialRequestService->convert($trial->fresh());
        $this->assertDatabaseHas('conversion_events', ['event_type' => 'trial_to_paid']);

        $opportunityService->markWon($opportunity->fresh());
        $this->assertDatabaseHas('conversion_events', ['event_type' => 'opportunity_won']);
    }

    public function test_lost_pipeline_records_opportunity_lost_event(): void
    {
        $conversionEventService = new ConversionEventService();
        $opportunityService = new OpportunityService($conversionEventService);

        $lead = PlatformLead::factory()->create();
        $opportunity = $opportunityService->createFromLead($lead);

        $opportunityService->markLost($opportunity, 'Budget constraints');

        $this->assertDatabaseHas('conversion_events', ['event_type' => 'opportunity_lost']);
    }
}
