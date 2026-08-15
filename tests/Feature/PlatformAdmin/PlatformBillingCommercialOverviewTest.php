<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformPaymentStatus;
use App\Enums\PlatformRefundStatus;
use App\Enums\PlatformRoleCode;
use App\Enums\PlatformSubscriptionStatus;
use App\Enums\TrialRequestStatus;
use App\Filament\Pages\PlatformBillingCommercialOverviewPage;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Models\PlatformPaymentAttempt;
use App\Models\PlatformRefund;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionItem;
use App\Models\TrialRequest;
use App\Models\UsageRollup;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Services\PlatformRoleService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlatformBillingCommercialOverviewTest — Billing & Commercial Control
 * Plane pass. Covers both the read model
 * (PlatformBillingCommercialOverviewService) and the page that renders
 * it.
 *
 * The emphasis is on TRUTH, not coverage-for-its-own-sake: that money
 * derivations are exactly right in integer cents, that unavailable
 * metrics render as unavailable rather than as zero, that capability
 * gaps are disclosed, and that permanent product gaps stay out of the
 * operational attention queue.
 */
final class PlatformBillingCommercialOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function service(): PlatformBillingCommercialOverviewService
    {
        return app(PlatformBillingCommercialOverviewService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function subscribeTo(Plan $plan, PlatformSubscriptionStatus $status): PlatformSubscription
    {
        return PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_interval' => $plan->billing_interval,
        ]);
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformBillingCommercialOverviewPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $this->assertTrue(PlatformBillingCommercialOverviewPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformBillingCommercialOverviewPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformBillingCommercialOverviewPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SalesRep), 'platform_admin')
            ->get(PlatformBillingCommercialOverviewPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_billing_admin_can_reach_the_page(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin')
            ->get(PlatformBillingCommercialOverviewPage::getUrl())
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Revenue derivation — exact integer cents
    // ------------------------------------------------------------------

    public function test_mrr_and_arr_are_zero_with_no_subscriptions(): void
    {
        $revenue = $this->service()->revenue();

        $this->assertSame(0, $revenue['mrr_cents']);
        $this->assertSame(0, $revenue['arr_cents']);
        $this->assertSame(0, $revenue['active']);
    }

    public function test_a_monthly_active_subscription_contributes_its_plan_price_to_mrr(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 29900,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);

        $revenue = $this->service()->revenue();

        $this->assertSame(29900, $revenue['mrr_cents']);
        $this->assertSame(29900 * 12, $revenue['arr_cents']);
    }

    public function test_an_annual_active_subscription_is_normalized_to_monthly_for_mrr(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 120000,
            'billing_interval' => BillingInterval::Annual,
            'status' => PlanStatus::Active,
        ]);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);

        $revenue = $this->service()->revenue();

        $this->assertSame(120000, $revenue['arr_cents'], 'An annual plan price IS the annual figure.');
        $this->assertSame(10000, $revenue['mrr_cents']);
    }

    public function test_arr_is_exact_and_mrr_truncates_only_once_on_the_total(): void
    {
        // 1_00.05 is not representable as whole cents per month: an
        // annual price of 10_005 cents divides to 833.75 cents/month.
        // ARR must stay exact; MRR truncates the TOTAL, once.
        $plan = Plan::factory()->create([
            'price_cents' => 10005,
            'billing_interval' => BillingInterval::Annual,
            'status' => PlanStatus::Active,
        ]);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);

        $revenue = $this->service()->revenue();

        $this->assertSame(10005, $revenue['arr_cents']);
        $this->assertSame(833, $revenue['mrr_cents']);
    }

    public function test_subscription_line_items_are_included_in_recurring_revenue(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 10000,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);
        $subscription = $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);

        PlatformSubscriptionItem::factory()->create([
            'platform_subscription_id' => $subscription->id,
            'quantity' => 3,
            'unit_amount_cents' => 2500,
        ]);

        $revenue = $this->service()->revenue();

        $this->assertSame(10000 + (3 * 2500), $revenue['mrr_cents']);
    }

    public function test_only_active_subscriptions_contribute_to_recurring_revenue(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 50000,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);

        $this->subscribeTo($plan, PlatformSubscriptionStatus::Trialing);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::PastDue);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Cancelled);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Expired);

        $revenue = $this->service()->revenue();

        $this->assertSame(0, $revenue['mrr_cents'], 'Non-active subscriptions must not be counted as revenue.');
        $this->assertSame(1, $revenue['trialing']);
        $this->assertSame(1, $revenue['past_due']);
        $this->assertSame(1, $revenue['cancelled']);
        $this->assertSame(1, $revenue['expired']);
    }

    public function test_a_subscription_cancelling_at_period_end_still_counts_as_active_revenue(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 7500,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);
        $subscription = $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);
        $subscription->update(['cancel_at_period_end' => true]);

        $revenue = $this->service()->revenue();

        $this->assertSame(7500, $revenue['mrr_cents']);
        $this->assertSame(1, $revenue['cancelling']);
    }

    // ------------------------------------------------------------------
    // Trials — conversion rate honesty
    // ------------------------------------------------------------------

    public function test_conversion_rate_is_unavailable_rather_than_zero_when_no_trial_has_ended(): void
    {
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Active]);

        $trials = $this->service()->trials(CarbonImmutable::now());

        $this->assertNull(
            $trials['conversion_rate'],
            'With no terminal-outcome trials there is no denominator; this must be unavailable, not 0.',
        );
    }

    public function test_conversion_rate_uses_terminal_outcome_trials_as_its_denominator(): void
    {
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Converted]);
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Expired]);
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Cancelled]);
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Cancelled]);
        // Still running — must NOT be in the denominator.
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Active]);
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Requested]);

        $trials = $this->service()->trials(CarbonImmutable::now());

        $this->assertSame(4, $trials['terminal']);
        $this->assertSame(25.0, $trials['conversion_rate']);
    }

    public function test_expiring_soon_counts_only_active_trials_inside_the_horizon(): void
    {
        $asOf = CarbonImmutable::parse('2026-08-15 12:00:00');

        TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Active,
            'expires_at' => $asOf->addDays(3),
        ]);
        TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Active,
            'expires_at' => $asOf->addDays(30),
        ]);
        // Already expired in status — not "expiring soon".
        TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Expired,
            'expires_at' => $asOf->addDays(2),
        ]);

        $trials = $this->service()->trials($asOf);

        $this->assertSame(1, $trials['expiring_soon']);
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    public function test_amount_outstanding_sums_only_issued_unpaid_invoices(): void
    {
        $account = BillingAccount::factory()->create();

        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'total_cents' => 15000,
        ]);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::PastDue,
            'total_cents' => 5000,
        ]);
        // Excluded: not issued, already settled, or cancelled.
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Draft,
            'total_cents' => 99900,
        ]);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Paid,
            'total_cents' => 99900,
        ]);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Void,
            'total_cents' => 99900,
        ]);

        $invoices = $this->service()->invoices(CarbonImmutable::now());

        $this->assertSame(20000, $invoices['outstanding_cents']);
        $this->assertSame(1, $invoices['open']);
        $this->assertSame(1, $invoices['past_due_status']);
        $this->assertSame(1, $invoices['draft']);
        $this->assertSame(1, $invoices['paid']);
        $this->assertSame(1, $invoices['void']);
    }

    public function test_overdue_is_counted_by_due_date_independently_of_stored_status(): void
    {
        $asOf = CarbonImmutable::parse('2026-08-15 12:00:00');
        $account = BillingAccount::factory()->create();

        // Open and past its due date — overdue in fact, though its
        // stored status has not been moved to Past Due by anything.
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'due_at' => $asOf->subDay(),
        ]);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'due_at' => $asOf->addDay(),
        ]);

        $invoices = $this->service()->invoices($asOf);

        $this->assertSame(1, $invoices['overdue']);
        $this->assertSame(0, $invoices['past_due_status']);
    }

    // ------------------------------------------------------------------
    // Payments and refunds
    // ------------------------------------------------------------------

    public function test_accounts_with_failures_is_a_distinct_account_count(): void
    {
        $account = BillingAccount::factory()->create();

        PlatformPaymentAttempt::factory()->count(3)->create([
            'billing_account_id' => $account->id,
            'status' => PlatformPaymentAttemptStatus::Failed,
        ]);
        PlatformPaymentAttempt::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformPaymentAttemptStatus::Succeeded,
        ]);

        $payments = $this->service()->payments();

        $this->assertSame(3, $payments['failed_attempts']);
        $this->assertSame(1, $payments['accounts_with_failures']);
    }

    public function test_refund_totals_count_only_completed_refunds(): void
    {
        $account = BillingAccount::factory()->create();
        $payment = PlatformPayment::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformPaymentStatus::Succeeded,
            'amount_cents' => 100000,
        ]);

        PlatformRefund::factory()->create([
            'platform_payment_id' => $payment->id,
            'status' => PlatformRefundStatus::Completed,
            'amount_cents' => 2500,
        ]);
        PlatformRefund::factory()->create([
            'platform_payment_id' => $payment->id,
            'status' => PlatformRefundStatus::Requested,
            'amount_cents' => 9999,
        ]);
        PlatformRefund::factory()->create([
            'platform_payment_id' => $payment->id,
            'status' => PlatformRefundStatus::Failed,
            'amount_cents' => 9999,
        ]);

        $payments = $this->service()->payments();

        $this->assertSame(2500, $payments['refunded_cents']);
        $this->assertSame(1, $payments['refunds_completed']);
        $this->assertSame(1, $payments['refunds_pending']);
        $this->assertSame(1, $payments['refunds_failed']);
    }

    // ------------------------------------------------------------------
    // Usage — attribution, not pricing
    // ------------------------------------------------------------------

    public function test_usage_reports_attribution_scope_and_never_a_pricing_state(): void
    {
        $usage = $this->service()->usage();

        $this->assertArrayHasKey('account_level', $usage);
        $this->assertArrayHasKey('firm_attributed', $usage);
        $this->assertArrayNotHasKey('priced', $usage);
        $this->assertArrayNotHasKey('unpriced', $usage);
        $this->assertArrayNotHasKey('unbilled_cents', $usage);
        $this->assertArrayNotHasKey('invoiced', $usage);
    }

    public function test_a_usage_record_with_no_firm_is_reported_as_account_level(): void
    {
        $account = BillingAccount::factory()->create();

        UsageRollup::factory()->create(['billing_account_id' => $account->id, 'firm_id' => null]);

        $usage = $this->service()->usage();

        $this->assertSame(1, $usage['records']);
        $this->assertSame(1, $usage['account_level']);
        $this->assertSame(0, $usage['firm_attributed']);
    }

    // ------------------------------------------------------------------
    // Requires attention
    // ------------------------------------------------------------------

    public function test_the_attention_queue_is_empty_when_nothing_is_wrong(): void
    {
        $this->assertSame([], $this->service()->requiresAttention(CarbonImmutable::now()));
    }

    public function test_the_attention_queue_surfaces_a_live_subscription_on_an_archived_plan(): void
    {
        $plan = Plan::factory()->create([
            'status' => PlanStatus::Archived,
            'price_cents' => 10000,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);

        $keys = array_column($this->service()->requiresAttention(CarbonImmutable::now()), 'key');

        $this->assertContains('subscriptions_on_non_active_plan', $keys);
    }

    public function test_the_attention_queue_surfaces_a_zero_priced_active_plan(): void
    {
        Plan::factory()->create(['status' => PlanStatus::Active, 'price_cents' => 0]);

        $keys = array_column($this->service()->requiresAttention(CarbonImmutable::now()), 'key');

        $this->assertContains('zero_priced_active_plans', $keys);
    }

    public function test_permanent_product_gaps_are_never_queued_as_operational_alerts(): void
    {
        $keys = array_column($this->service()->requiresAttention(CarbonImmutable::now()), 'key');

        foreach (['payment_gateway', 'credit_domain', 'reseller_domain', 'usage_adjustment'] as $gap) {
            $this->assertNotContains($gap, $keys);
        }
    }

    // ------------------------------------------------------------------
    // Page rendering: truth, not polish
    // ------------------------------------------------------------------

    public function test_money_is_rendered_with_its_currency_and_never_as_raw_cents(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 29900,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);
        $this->subscribeTo($plan, PlatformSubscriptionStatus::Active);

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(PlatformBillingCommercialOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('299.00 USD');
        $response->assertSee('3,588.00 USD');
        $response->assertDontSee('29900');
    }

    public function test_the_page_discloses_every_commercial_capability_gap(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(PlatformBillingCommercialOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('No production payment gateway');
        $response->assertSee('No credit domain');
        $response->assertSee('No usage adjustment ledger');
        $response->assertSee('No plan versioning');
        $response->assertSee('No reseller or partner domain');
        $response->assertSee('No tax or discount capability');
    }

    public function test_the_page_states_the_actual_consequence_of_trial_expiry(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(PlatformBillingCommercialOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('What happens when a trial expires');
        $response->assertSee('Nothing in this codebase disables access');
    }

    public function test_the_page_does_not_present_a_recovery_rate(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(PlatformBillingCommercialOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('No recovery rate, recovered amount, or amount-at-risk is shown');
    }

    public function test_the_page_separates_platform_billing_from_firm_client_billing(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(PlatformBillingCommercialOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('not a law firm');
        $response->assertSee('trust accounting');
    }

    public function test_conversion_rate_renders_as_not_available_rather_than_zero_percent(): void
    {
        TrialRequest::factory()->create(['status' => TrialRequestStatus::Active]);

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(PlatformBillingCommercialOverviewPage::getUrl());
        $response->assertOk();
        $response->assertSee('Not available');
        $response->assertSee('reported as unavailable rather than as 0%');
    }

    // ------------------------------------------------------------------
    // Performance: bounded aggregates, not row iteration
    // ------------------------------------------------------------------

    public function test_the_read_model_uses_a_bounded_number_of_queries_regardless_of_data_volume(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 1000,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);
        $account = BillingAccount::factory()->create();

        PlatformSubscription::factory()->count(25)->create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        PlatformInvoice::factory()->count(25)->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'total_cents' => 1000,
        ]);
        UsageRollup::factory()->count(25)->create(['billing_account_id' => $account->id]);

        $service = $this->service();
        $asOf = CarbonImmutable::now();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->revenue();
        $service->trials($asOf);
        $service->invoices($asOf);
        $service->payments();
        $service->usage();
        $service->catalog();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            10,
            $queryCount,
            'Every overview figure must come from a bounded aggregate — never one query per record.',
        );
        $this->assertSame(25 * 1000, $service->revenue()['mrr_cents']);
    }
}
