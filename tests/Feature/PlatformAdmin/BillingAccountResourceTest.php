<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformRoleCode;
use App\Enums\PlatformSubscriptionStatus;
use App\Filament\Resources\BillingAccountResource;
use App\Filament\Resources\BillingAccountResource\Pages\ListBillingAccounts;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\PlatformPaymentAttempt;
use App\Models\PlatformSubscription;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BillingAccountResourceTest — Billing & Commercial Control Plane pass.
 *
 * Covers authorization, the commercial aggregates (which must agree
 * between the list table and the detail page), the read-only guarantee,
 * the filters, and — most importantly — the payment-instrument and
 * credit disclosures: this page must never render a stored payment
 * method, a payment-method health indicator, or a credit balance,
 * because none of those exist in this platform.
 */
final class BillingAccountResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    // --- Authorization ---

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(BillingAccountResource::canAccess());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(BillingAccountResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(BillingAccountResource::getUrl())
            ->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SalesRep), 'platform_admin')
            ->get(BillingAccountResource::getUrl())
            ->assertForbidden();
    }

    public function test_a_billing_admin_can_list_billing_accounts(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin')
            ->get(BillingAccountResource::getUrl())
            ->assertOk();
    }

    // --- Commercial aggregates ---

    public function test_the_outstanding_balance_counts_only_issued_unpaid_invoices(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Northwind Legal']);

        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'total_cents' => 12500,
        ]);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::PastDue,
            'total_cents' => 2500,
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

        $aggregated = BillingAccountResource::withCommercialAggregates(
            BillingAccount::query()->whereKey($account->id)
        )->firstOrFail();

        $this->assertSame(15000, (int) $aggregated->outstanding_cents);
        $this->assertSame(2, (int) $aggregated->open_invoices_count);
    }

    public function test_the_list_and_the_detail_page_report_the_same_outstanding_balance(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Northwind Legal']);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'total_cents' => 15000,
        ]);

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $list = $this->get(BillingAccountResource::getUrl());
        $list->assertOk();
        $list->assertSee('150.00 USD');

        $detail = $this->get(BillingAccountResource::getUrl('view', ['record' => $account]));
        $detail->assertOk();
        $detail->assertSee('150.00 USD');
    }

    public function test_money_is_never_rendered_as_raw_cents(): void
    {
        $account = BillingAccount::factory()->create();
        PlatformInvoice::factory()->create([
            'billing_account_id' => $account->id,
            'status' => PlatformInvoiceStatus::Open,
            'total_cents' => 12345,
        ]);

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(BillingAccountResource::getUrl());
        $response->assertOk();
        $response->assertSee('123.45 USD');
        $response->assertDontSee('12345');
    }

    public function test_live_subscription_count_excludes_ended_subscriptions(): void
    {
        $account = BillingAccount::factory()->create();
        $plan = Plan::factory()->create();

        foreach ([
            PlatformSubscriptionStatus::Active,
            PlatformSubscriptionStatus::Trialing,
            PlatformSubscriptionStatus::PastDue,
            PlatformSubscriptionStatus::Cancelled,
            PlatformSubscriptionStatus::Expired,
        ] as $status) {
            PlatformSubscription::factory()->create([
                'billing_account_id' => $account->id,
                'plan_id' => $plan->id,
                'status' => $status,
            ]);
        }

        $aggregated = BillingAccountResource::withCommercialAggregates(
            BillingAccount::query()->whereKey($account->id)
        )->firstOrFail();

        $this->assertSame(3, (int) $aggregated->live_subscriptions_count);
    }

    // --- Filters ---

    public function test_the_outstanding_balance_filter_narrows_the_list(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $owing = BillingAccount::factory()->create();
        $settled = BillingAccount::factory()->create();

        PlatformInvoice::factory()->create([
            'billing_account_id' => $owing->id,
            'status' => PlatformInvoiceStatus::Open,
        ]);
        PlatformInvoice::factory()->create([
            'billing_account_id' => $settled->id,
            'status' => PlatformInvoiceStatus::Paid,
        ]);

        $test = Livewire::test(ListBillingAccounts::class);
        $test->filterTable('has_outstanding_balance');

        $test->assertCanSeeTableRecords([$owing]);
        $test->assertCanNotSeeTableRecords([$settled]);
    }

    public function test_the_failed_payment_attempt_filter_narrows_the_list(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $failing = BillingAccount::factory()->create();
        $healthy = BillingAccount::factory()->create();

        PlatformPaymentAttempt::factory()->create([
            'billing_account_id' => $failing->id,
            'status' => PlatformPaymentAttemptStatus::Failed,
        ]);
        PlatformPaymentAttempt::factory()->create([
            'billing_account_id' => $healthy->id,
            'status' => PlatformPaymentAttemptStatus::Succeeded,
        ]);

        $test = Livewire::test(ListBillingAccounts::class);
        $test->filterTable('has_failed_payment_attempt');

        $test->assertCanSeeTableRecords([$failing]);
        $test->assertCanNotSeeTableRecords([$healthy]);
    }

    public function test_the_no_live_subscription_filter_narrows_the_list(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $subscribed = BillingAccount::factory()->create();
        $unsubscribed = BillingAccount::factory()->create();

        PlatformSubscription::factory()->create([
            'billing_account_id' => $subscribed->id,
            'plan_id' => Plan::factory()->create()->id,
            'status' => PlatformSubscriptionStatus::Active,
        ]);

        $test = Livewire::test(ListBillingAccounts::class);
        $test->filterTable('no_live_subscription');

        $test->assertCanSeeTableRecords([$unsubscribed]);
        $test->assertCanNotSeeTableRecords([$subscribed]);
    }

    // --- Payment-instrument and credit safety ---

    public function test_the_payment_method_reference_is_never_rendered(): void
    {
        $account = BillingAccount::factory()->create([
            'payment_method_ref' => 'pm_supersecret_reference_value',
        ]);

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $this->get(BillingAccountResource::getUrl())
            ->assertOk()
            ->assertDontSee('pm_supersecret_reference_value');

        $this->get(BillingAccountResource::getUrl('view', ['record' => $account]))
            ->assertOk()
            ->assertDontSee('pm_supersecret_reference_value');
    }

    public function test_the_detail_page_states_that_no_payment_instrument_exists(): void
    {
        $account = BillingAccount::factory()->create();

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(BillingAccountResource::getUrl('view', ['record' => $account]));
        $response->assertOk();
        $response->assertSee('No payment instrument is shown for this account');
        $response->assertSee('no payment-method health can be');
    }

    public function test_the_detail_page_states_that_credits_do_not_exist_rather_than_showing_a_zero_balance(): void
    {
        $account = BillingAccount::factory()->create();

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(BillingAccountResource::getUrl('view', ['record' => $account]));
        $response->assertOk();
        $response->assertSee('credits do not exist in this platform');
        $response->assertSee('This is a missing capability, not a zero balance');
    }

    public function test_the_detail_page_states_why_no_mark_paid_exists(): void
    {
        $account = BillingAccount::factory()->create();

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(BillingAccountResource::getUrl('view', ['record' => $account]));
        $response->assertOk();
        $response->assertSee('no field distinguishing a gateway-confirmed payment from an');
    }

    public function test_the_detail_page_lists_only_the_subscription_operations_that_actually_exist(): void
    {
        $account = BillingAccount::factory()->create();

        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $response = $this->get(BillingAccountResource::getUrl('view', ['record' => $account]));
        $response->assertOk();
        $response->assertSee('There is no plan change, scheduled');
        $response->assertSee('or proration');
    }

    // --- Read-only guarantee ---

    public function test_the_resource_exposes_no_create_edit_or_delete_route(): void
    {
        $pages = array_keys(BillingAccountResource::getPages());

        $this->assertSame(['index', 'view'], $pages);
    }

    public function test_the_resource_registers_no_mutating_action_and_never_calls_the_commercial_service(): void
    {
        foreach ([
            app_path('Filament/Resources/BillingAccountResource.php'),
            app_path('Filament/Resources/BillingAccountResource/Pages/ListBillingAccounts.php'),
            app_path('Filament/Resources/BillingAccountResource/Pages/ViewBillingAccount.php'),
        ] as $file) {
            $source = file_get_contents($file);

            $this->assertStringNotContainsString('recordActions(', $source);
            $this->assertStringNotContainsString('headerActions(', $source);
            $this->assertStringNotContainsString('toolbarActions(', $source);
            $this->assertStringNotContainsString('use App\Services\BillingAccountCommercialService;', $source);
        }
    }

    /**
     * Structural guard: `payment_method_ref` must never be bound to a
     * column, infolist entry, filter, or search anywhere in this
     * Resource. Asserted against the binding constructs specifically —
     * the classes' own docblocks legitimately name the column when
     * explaining why it is withheld.
     */
    public function test_the_payment_method_reference_is_never_bound_to_any_display_or_search_construct(): void
    {
        foreach ([
            app_path('Filament/Resources/BillingAccountResource.php'),
            app_path('Filament/Resources/BillingAccountResource/Pages/ListBillingAccounts.php'),
            app_path('Filament/Resources/BillingAccountResource/Pages/ViewBillingAccount.php'),
        ] as $file) {
            $source = file_get_contents($file);

            foreach (['TextColumn', 'TextEntry', 'SelectFilter', 'Filter'] as $construct) {
                $this->assertStringNotContainsString(
                    $construct."::make('payment_method_ref'",
                    $source,
                );
            }
        }
    }
}
