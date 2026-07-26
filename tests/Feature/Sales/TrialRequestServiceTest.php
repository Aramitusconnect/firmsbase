<?php

namespace Tests\Feature\Sales;

use App\Enums\OpportunityStatus;
use App\Enums\TrialRequestStatus;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Services\ConversionEventService;
use App\Services\TenantContextService;
use App\Services\TrialRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrialRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrialRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrialRequestService(new ConversionEventService);
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

    // ------------------------------------------------------------
    // Phase 3 FirmsVault Admin Control Center additions — actor +
    // audit plumbing on provision()/activate()/expire()/convert().
    // ------------------------------------------------------------

    public function test_provision_activate_expire_convert_without_an_actor_write_no_audit_events(): void
    {
        $opportunity = Opportunity::factory()->create();
        $organization = Organization::factory()->create();
        $trial = $this->service->request($opportunity);

        $provisioned = $this->service->provision($trial, $organization);
        $this->service->activate($provisioned);
        $expiredTrial = $this->service->request(Opportunity::factory()->create());
        $this->service->expire($expiredTrial);
        $convertedTrial = $this->service->provision($this->service->request(Opportunity::factory()->create()), $organization);
        $this->service->convert($convertedTrial);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->whereIn('event_type', ['trial_provisioned', 'trial_activated', 'trial_expired', 'trial_converted'])
                ->count()
        );
        $this->assertSame(0, $count, 'No actor supplied to any of the four transitions means no audit event and no behavior change from before this addition.');
    }

    public function test_provision_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $opportunity = Opportunity::factory()->create();
        $organization = Organization::factory()->create();
        $trial = $this->service->request($opportunity);

        $provisioned = $this->service->provision($trial, $organization, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'trial_provisioned')->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id);
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('platform_billing', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($provisioned->id, $metadata['trial_request_id']);
        $this->assertSame($organization->id, $metadata['organization_id']);
    }

    public function test_activate_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $opportunity = Opportunity::factory()->create();
        $organization = Organization::factory()->create();
        $trial = $this->service->request($opportunity);
        $provisioned = $this->service->provision($trial, $organization);

        $activated = $this->service->activate($provisioned, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'trial_activated')->first()
        );

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
        $metadata = json_decode($row->metadata, true);
        $this->assertSame($activated->id, $metadata['trial_request_id']);
    }

    public function test_expire_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $opportunity = Opportunity::factory()->create();
        $trial = $this->service->request($opportunity);

        $expired = $this->service->expire($trial, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'trial_expired')->first()
        );

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
        $metadata = json_decode($row->metadata, true);
        $this->assertSame($expired->id, $metadata['trial_request_id']);
    }

    public function test_convert_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $opportunity = Opportunity::factory()->create();
        $organization = Organization::factory()->create();
        $trial = $this->service->request($opportunity);
        $provisioned = $this->service->provision($trial, $organization);
        $active = $this->service->activate($provisioned);

        $converted = $this->service->convert($active, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'trial_converted')->first()
        );

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);
        $metadata = json_decode($row->metadata, true);
        $this->assertSame($converted->id, $metadata['trial_request_id']);
        $this->assertSame($organization->id, $metadata['organization_id']);
    }
}
