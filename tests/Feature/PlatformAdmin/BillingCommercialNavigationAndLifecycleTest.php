<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Enums\PlatformRoleCode;
use App\Enums\PlatformSubscriptionStatus;
use App\Enums\TrialRequestStatus;
use App\Filament\Pages\PlatformBillingCommercialOverviewPage;
use App\Filament\Pages\PlatformInternalSalesCommissionsPage;
use App\Filament\Pages\PlatformResellersPage;
use App\Filament\Pages\PlatformUsageChargesPage;
use App\Filament\Resources\BillingAccountResource;
use App\Filament\Resources\FailedPaymentResource;
use App\Filament\Resources\PlanAddOnResource;
use App\Filament\Resources\PlanResource;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Filament\Resources\PlatformRefundResource;
use App\Filament\Resources\PlatformSubscriptionResource;
use App\Filament\Resources\PlatformSubscriptionResource\Pages\ListPlatformSubscriptions;
use App\Filament\Resources\TrialRequestResource;
use App\Filament\Resources\TrialRequestResource\Pages\ListTrialRequests;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionItem;
use App\Models\TrialRequest;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Services\PlatformRoleService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BillingCommercialNavigationAndLifecycleTest — Billing & Commercial
 * Control Plane pass.
 *
 * Two things this suite exists to hold in place:
 *
 * 1. NAVIGATION TRUTH. Every Billing & Commercial nav item must be
 *    named for what its backend actually is, and the group must be
 *    ordered coherently rather than alphabetically-by-accident. A label
 *    is an assertion about capability; "Credits and Refunds" over a
 *    refunds-only table, or "Resellers" over an employee commission
 *    table, are false ones.
 *
 * 2. LIFECYCLE TRUTH. Subscriptions and trials must state the
 *    operations that actually exist and must not imply the ones that do
 *    not.
 */
final class BillingCommercialNavigationAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function billingAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::BillingAdmin);

        return $admin;
    }

    // ------------------------------------------------------------------
    // Navigation naming and ordering
    // ------------------------------------------------------------------

    public function test_every_billing_item_is_in_the_billing_and_commercial_group(): void
    {
        foreach ([
            PlatformBillingCommercialOverviewPage::class,
            PlanResource::class,
            PlanAddOnResource::class,
            BillingAccountResource::class,
            PlatformSubscriptionResource::class,
            TrialRequestResource::class,
            PlatformInvoiceResource::class,
            FailedPaymentResource::class,
            PlatformUsageChargesPage::class,
            PlatformRefundResource::class,
            PlatformResellersPage::class,
            PlatformInternalSalesCommissionsPage::class,
        ] as $class) {
            $this->assertSame(
                'Billing & Commercial',
                $class::getNavigationGroup(),
                $class.' must stay inside the Billing & Commercial group.',
            );
        }
    }

    public function test_the_navigation_group_is_ordered_overview_catalog_customers_billing_channels(): void
    {
        $expectedOrder = [
            PlatformBillingCommercialOverviewPage::class,
            PlanResource::class,
            PlanAddOnResource::class,
            BillingAccountResource::class,
            PlatformSubscriptionResource::class,
            TrialRequestResource::class,
            PlatformInvoiceResource::class,
            FailedPaymentResource::class,
            PlatformUsageChargesPage::class,
            PlatformRefundResource::class,
            PlatformResellersPage::class,
            PlatformInternalSalesCommissionsPage::class,
        ];

        $sorts = array_map(
            fn (string $class): ?int => $class::getNavigationSort(),
            $expectedOrder,
        );

        $this->assertNotContains(
            null,
            $sorts,
            'Every Billing & Commercial item must declare an explicit navigation sort — otherwise the group '.
            'orders by registration accident.',
        );

        $sorted = $sorts;
        sort($sorted);

        $this->assertSame($sorted, $sorts, 'Billing & Commercial navigation is out of the intended order.');
        $this->assertSame(count(array_unique($sorts)), count($sorts), 'Two Billing items share a navigation sort.');
    }

    public function test_no_navigation_label_asserts_a_capability_this_platform_lacks(): void
    {
        // Refunds exist; credits do not. Reseller readiness is a
        // disclosure; employee commission is its own thing.
        $this->assertSame('Refunds', PlatformRefundResource::getNavigationLabel());
        $this->assertStringNotContainsStringIgnoringCase('credit', PlatformRefundResource::getNavigationLabel());

        $this->assertSame('Reseller Readiness', PlatformResellersPage::getNavigationLabel());
        $this->assertSame('Internal Sales Commissions', PlatformInternalSalesCommissionsPage::getNavigationLabel());
    }

    // ------------------------------------------------------------------
    // Subscription lifecycle truth
    // ------------------------------------------------------------------

    public function test_the_subscription_list_states_only_the_lifecycle_operations_that_exist(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlatformSubscriptionResource::getUrl());
        $response->assertOk();
        $response->assertSee('cancel at period end and cancel immediately');
        $response->assertSee('no plan change, scheduled plan change, pause, resume, resume-cancellation, or proration');
    }

    public function test_the_subscription_list_distinguishes_platform_billing_from_firm_client_billing(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlatformSubscriptionResource::getUrl());
        $response->assertOk();
        $response->assertSee('not a firm');
        $response->assertSee('client payment plans');
    }

    public function test_the_subscription_amount_is_the_plan_price_plus_its_line_items(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create([
            'price_cents' => 20000,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);
        $subscription = PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        PlatformSubscriptionItem::factory()->create([
            'platform_subscription_id' => $subscription->id,
            'quantity' => 2,
            'unit_amount_cents' => 5000,
        ]);

        $response = $this->get(PlatformSubscriptionResource::getUrl());
        $response->assertOk();
        $response->assertSee('300.00 USD');
        $response->assertDontSee('30000');
    }

    public function test_the_cancelling_at_period_end_filter_narrows_the_list(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create();
        $account = BillingAccount::factory()->create();

        $cancelling = PlatformSubscription::factory()->create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
            'cancel_at_period_end' => true,
        ]);
        $continuing = PlatformSubscription::factory()->create([
            'billing_account_id' => $account->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
            'cancel_at_period_end' => false,
        ]);

        $test = Livewire::test(ListPlatformSubscriptions::class);
        $test->filterTable('cancel_at_period_end', true);

        $test->assertCanSeeTableRecords([$cancelling]);
        $test->assertCanNotSeeTableRecords([$continuing]);
    }

    // ------------------------------------------------------------------
    // Trial lifecycle truth
    // ------------------------------------------------------------------

    public function test_the_trial_list_states_what_expiry_actually_does(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(TrialRequestResource::getUrl());
        $response->assertOk();
        $response->assertSee('sets its status to Expired and records an audit event');
        $response->assertSee('does not disable access');
        $response->assertSee('nothing');
        $response->assertSee('expires trials automatically');
    }

    public function test_the_trial_list_states_that_trials_originate_from_the_sales_pipeline(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(TrialRequestResource::getUrl());
        $response->assertOk();
        $response->assertSee('originate from the sales pipeline');
        $response->assertSee('never');
    }

    public function test_days_remaining_counts_down_for_a_running_trial(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $trial = TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Active,
            'expires_at' => CarbonImmutable::now()->addDays(5)->addHour(),
        ]);

        Livewire::test(ListTrialRequests::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('days_remaining', '5', $trial);
    }

    /**
     * A countdown against a trial that already converted (or expired,
     * or was cancelled) is meaningless, and a negative number there
     * would read as an overdue alarm rather than as history. The column
     * must render nothing at all for those records.
     */
    public function test_days_remaining_is_blank_for_a_trial_that_already_ended(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $converted = TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Converted,
            'expires_at' => CarbonImmutable::now()->subDays(30),
        ]);
        $expired = TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Expired,
            'expires_at' => CarbonImmutable::now()->subDays(30),
        ]);

        Livewire::test(ListTrialRequests::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('days_remaining', null, $converted)
            ->assertTableColumnStateSet('days_remaining', null, $expired);
    }

    public function test_the_expiring_soon_filter_uses_the_same_horizon_as_the_overview(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $now = CarbonImmutable::now();
        $horizon = PlatformBillingCommercialOverviewService::TRIAL_EXPIRY_HORIZON_DAYS;

        $soon = TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Active,
            'expires_at' => $now->addDays($horizon - 1),
        ]);
        $later = TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Active,
            'expires_at' => $now->addDays($horizon + 10),
        ]);
        $alreadyExpired = TrialRequest::factory()->create([
            'status' => TrialRequestStatus::Expired,
            'expires_at' => $now->addDays(1),
        ]);

        $test = Livewire::test(ListTrialRequests::class);
        $test->filterTable('expiring_soon');

        $test->assertCanSeeTableRecords([$soon]);
        $test->assertCanNotSeeTableRecords([$later, $alreadyExpired]);
    }

    public function test_the_awaiting_provisioning_filter_narrows_the_list(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $requested = TrialRequest::factory()->create(['status' => TrialRequestStatus::Requested]);
        $active = TrialRequest::factory()->create(['status' => TrialRequestStatus::Active]);

        $test = Livewire::test(ListTrialRequests::class);
        $test->filterTable('awaiting_provisioning');

        $test->assertCanSeeTableRecords([$requested]);
        $test->assertCanNotSeeTableRecords([$active]);
    }

    public function test_the_trial_resource_never_fabricates_a_requested_plan(): void
    {
        // trial_requests has no plan_id and no derivable plan relation —
        // a "Requested plan" column here would have to invent one.
        $source = file_get_contents(app_path('Filament/Resources/TrialRequestResource.php'));

        $this->assertStringNotContainsString("make('plan", $source);
        $this->assertStringNotContainsString("make('requested_plan", $source);
        $this->assertStringNotContainsString('plan_id', $source);
    }
}
