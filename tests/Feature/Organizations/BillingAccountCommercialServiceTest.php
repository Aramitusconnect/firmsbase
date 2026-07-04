<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Services\BillingAccountCommercialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAccountCommercialServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingAccountCommercialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingAccountCommercialService();
    }

    public function test_create_billing_account_as_the_bill_to_entity(): void
    {
        $account = $this->service->createBillingAccount(['name' => 'Acme Billing', 'billing_email' => 'billing@acme.test']);

        $this->assertDatabaseHas('billing_accounts', ['id' => $account->id, 'name' => 'Acme Billing']);
        $this->assertNull($account->organization_id);
    }

    public function test_create_billing_account_can_be_linked_to_an_organization_up_front(): void
    {
        $organization = Organization::factory()->create();

        $account = $this->service->createBillingAccount(['name' => 'Org Billing'], $organization);

        $this->assertSame($organization->id, $account->organization_id);
    }

    public function test_attach_to_organization(): void
    {
        $account = $this->service->createBillingAccount(['name' => 'Standalone Billing']);
        $organization = Organization::factory()->create();

        $attached = $this->service->attachToOrganization($account, $organization);

        $this->assertSame($organization->id, $attached->organization_id);
    }

    public function test_detach_from_organization(): void
    {
        $organization = Organization::factory()->create();
        $account = $this->service->createBillingAccount(['name' => 'Org Billing'], $organization);

        $detached = $this->service->detachFromOrganization($account);

        $this->assertNull($detached->organization_id);
    }
}
