<?php

namespace Tests\Feature\Organizations;

use App\Models\Firm;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\CommercialOrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialOrganizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommercialOrganizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommercialOrganizationService();
    }

    public function test_create_organization(): void
    {
        $organization = $this->service->createOrganization(['name' => 'Acme Legal Group']);

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => 'Acme Legal Group']);
    }

    public function test_attach_firm_sets_organization_id(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => null]);

        $attached = $this->service->attachFirm($organization, $firm);

        $this->assertSame($organization->id, $attached->organization_id);
    }

    public function test_detach_firm_clears_organization_id(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);

        $detached = $this->service->detachFirm($firm);

        $this->assertNull($detached->organization_id);
    }

    public function test_set_default_plan(): void
    {
        $organization = Organization::factory()->create();
        $plan = Plan::factory()->create();

        $updated = $this->service->setDefaultPlan($organization, $plan);

        $this->assertSame($plan->id, $updated->default_plan_id);
        $this->assertTrue($updated->defaultPlan->is($plan));
    }
}
