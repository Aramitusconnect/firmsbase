<?php

namespace Tests\Feature\Commissions;

use App\Enums\CommissionEventType;
use App\Models\BillingAccount;
use App\Models\CommissionPlan;
use App\Models\OrgLicense;
use App\Services\CommissionEligibilityService;
use App\Services\CommissionEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms commission_events keys to billing_account_id and Phase 6
 * platform billing records only, and that organization expansion
 * attributes exactly once to the billing account.
 */
class CommissionEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommissionEventService(new CommissionEligibilityService());
    }

    public function test_commission_events_key_to_billing_account_id(): void
    {
        $billingAccount = BillingAccount::factory()->create();
        $commissionPlan = CommissionPlan::factory()->create();
        $orgLicense = OrgLicense::factory()->create();

        $event = $this->service->attributeOnce(
            $billingAccount,
            $commissionPlan,
            $orgLicense,
            CommissionEventType::NewBusiness,
            10000,
        );

        $this->assertSame($billingAccount->id, $event->billing_account_id);
        $this->assertDatabaseHas('commission_events', ['billing_account_id' => $billingAccount->id]);
    }

    public function test_commission_events_never_reference_firm_client_billing_columns(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('commission_events');

        foreach (['invoice_id', 'payment_id', 'payment_plan_id', 'manual_payment_record_id'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $columns);
        }
    }

    public function test_organization_expansion_attributes_once_to_the_billing_account(): void
    {
        $billingAccount = BillingAccount::factory()->create();
        $commissionPlan = CommissionPlan::factory()->create();
        $orgLicense = OrgLicense::factory()->create();

        $first = $this->service->attributeOnce($billingAccount, $commissionPlan, $orgLicense, CommissionEventType::Expansion, 5000);
        $second = $this->service->attributeOnce($billingAccount, $commissionPlan, $orgLicense, CommissionEventType::Expansion, 5000);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\CommissionEvent::query()
            ->where('billing_account_id', $billingAccount->id)
            ->where('attributable_id', $orgLicense->id)
            ->where('event_type', CommissionEventType::Expansion->value)
            ->count());
    }
}
