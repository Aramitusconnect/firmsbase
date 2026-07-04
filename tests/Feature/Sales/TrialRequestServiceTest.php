<?php

namespace Tests\Feature\Sales;

use App\Enums\OpportunityStatus;
use App\Enums\TrialRequestStatus;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Services\ConversionEventService;
use App\Services\TrialRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrialRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrialRequestService(new ConversionEventService());
    }

    public function test_request_creates_a_trial_request_and_activates_opportunity_trial_status(): void
    {
        $opportunity = Opportunity::factory()->create();

        $trial = $this->service->request($opportunity);

        $this->assertSame(TrialRequestStatus::Requested, $trial->status);
        $this->assertSame(OpportunityStatus::TrialActive, $opportunity->fresh()->status);
        $this->assertDatabaseHas('conversion_events', ['opportunity_id' => $opportunity->id, 'event_type' => 'demo_to_trial']);
    }

    public function test_provision_and_activate_and_convert_pipeline(): void
    {
        $opportunity = Opportunity::factory()->create();
        $organization = Organization::factory()->create();
        $trial = $this->service->request($opportunity);

        $provisioned = $this->service->provision($trial, $organization);
        $this->assertSame(TrialRequestStatus::Provisioned, $provisioned->status);
        $this->assertSame($organization->id, $provisioned->organization_id);

        $active = $this->service->activate($provisioned);
        $this->assertSame(TrialRequestStatus::Active, $active->status);

        $converted = $this->service->convert($active);
        $this->assertSame(TrialRequestStatus::Converted, $converted->status);
        $this->assertNotNull($converted->converted_at);
        $this->assertDatabaseHas('conversion_events', ['trial_request_id' => $trial->id, 'event_type' => 'trial_to_paid']);
    }

    public function test_expire_sets_expired_status(): void
    {
        $opportunity = Opportunity::factory()->create();
        $trial = $this->service->request($opportunity);

        $expired = $this->service->expire($trial);

        $this->assertSame(TrialRequestStatus::Expired, $expired->status);
    }
}
