<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRefundStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\PlatformRefundResource;
use App\Filament\Resources\PlatformRefundResource\Pages\ListPlatformRefunds;
use App\Models\BillingAccount;
use App\Models\PlatformAdmin;
use App\Models\PlatformPayment;
use App\Models\PlatformRefund;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformRefundResourceTest — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Navigation
 * visibility, route-level authorization, filters, deterministic
 * ordering, bounded pagination, no-N+1, MoneyDisplay rendering, the
 * Credits disclosure section, the "Issue Refund" absence disclosure,
 * and — the load-bearing property — POSITIVE PROOF that no mutating (or
 * any) Filament Action exists anywhere in this resource.
 */
final class PlatformRefundResourceTest extends TestCase
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

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(PlatformRefundResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformRefundResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_refunds_list(): void
    {
        $this->get(PlatformRefundResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformRefundResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Refunded Firm']);
        $payment = PlatformPayment::factory()->forBillingAccount($account)->create();
        $refund = PlatformRefund::factory()->forPayment($payment)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(PlatformRefundResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Refunded Firm');

        $viewResponse = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformRefundResource::getUrl('view', ['record' => $refund]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Refunded Firm');
    }

    // --- MoneyDisplay spot-check ---

    public function test_amount_column_renders_via_money_display_not_a_raw_integer(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformRefund::factory()->create(['amount_cents' => 12345]);

        $response = $this->get(PlatformRefundResource::getUrl());
        $response->assertOk();
        $response->assertSee('123.45');
        $response->assertDontSee('12345');
    }

    // --- Empty state ---

    public function test_the_list_page_shows_an_empty_state_with_no_refunds(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformRefundResource::getUrl());
        $response->assertOk();
        $response->assertSee('No refunds found');
    }

    // --- "Issue Refund" absence disclosure ---

    public function test_the_list_page_discloses_why_no_issue_refund_action_exists(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformRefundResource::getUrl());
        $response->assertOk();
        $response->assertSee('FakeStripeGateway');
    }

    public function test_the_view_page_discloses_the_issue_refund_limitation(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $refund = PlatformRefund::factory()->create();

        $response = $this->get(PlatformRefundResource::getUrl('view', ['record' => $refund]));
        $response->assertOk();
        $response->assertSee('money-movement step always');
        // Note: the exact rendered fragment is "the actual money-movement
        // step always calls a StripeGateway" — asserted via the
        // "money-movement step always" substring above, which is stable
        // even if surrounding wording is later tweaked.
    }

    // --- Credits disclosure (empty-state style, on the View page) ---

    public function test_the_view_page_honestly_discloses_no_credit_system_exists(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $refund = PlatformRefund::factory()->create();

        $response = $this->get(PlatformRefundResource::getUrl('view', ['record' => $refund]));
        $response->assertOk();
        $response->assertSee('No credit-balance or credit-ledger system exists');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $completed = PlatformRefund::factory()->create(['status' => PlatformRefundStatus::Completed]);
        $failed = PlatformRefund::factory()->create(['status' => PlatformRefundStatus::Failed]);

        $test = Livewire::test(ListPlatformRefunds::class);
        $test->filterTable('status', PlatformRefundStatus::Completed->value);

        $test->assertCanSeeTableRecords([$completed]);
        $test->assertCanNotSeeTableRecords([$failed]);
    }

    public function test_date_range_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $inRange = PlatformRefund::factory()->create(['requested_at' => now()->parse('2026-01-05')]);
        $outOfRange = PlatformRefund::factory()->create(['requested_at' => now()->parse('2026-06-05')]);

        $test = Livewire::test(ListPlatformRefunds::class);
        $test->filterTable('date_range', ['from' => '2026-01-01', 'until' => '2026-01-31']);

        $test->assertCanSeeTableRecords([$inRange]);
        $test->assertCanNotSeeTableRecords([$outOfRange]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_requested_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedRequestedAt = now()->parse('2026-05-01 00:00:00');
        PlatformRefund::factory()->count(5)->create(['requested_at' => $sharedRequestedAt]);

        $first = Livewire::test(ListPlatformRefunds::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListPlatformRefunds::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied requested_at rows must order identically across repeated calls.');
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformRefund::factory()->count(30)->create();

        $test = Livewire::test(ListPlatformRefunds::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- No-N+1 ---

    public function test_listing_many_refunds_does_not_n_plus_one(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformRefund::factory()->create();

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(PlatformRefundResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        PlatformRefund::factory()->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(PlatformRefundResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount, 'Adding 9 more refunds must not add ~9 extra queries.');
    }

    // --- Positive proof: NO Filament Action of any kind exists anywhere ---

    public function test_the_resource_class_registers_no_filament_action_at_all(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/PlatformRefundResource.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('recordActions(', $source);
    }

    public function test_no_page_class_registers_a_filament_action(): void
    {
        foreach (['ListPlatformRefunds.php', 'ViewPlatformRefund.php'] as $file) {
            $source = file_get_contents(app_path("Filament/Resources/PlatformRefundResource/Pages/{$file}"));
            $this->assertStringNotContainsString('Action::make(', $source, "{$file} must never register any Filament Action.");
            $this->assertStringNotContainsString('->action(', $source, "{$file} must never register any Filament Action.");
            $this->assertStringNotContainsString('getHeaderActions', $source, "{$file} must never define header actions.");
        }
    }

    public function test_neither_the_resource_nor_pages_ever_call_refund_or_credit_methods(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/PlatformRefundResource.php'));
        $viewSource = file_get_contents(app_path('Filament/Resources/PlatformRefundResource/Pages/ViewPlatformRefund.php'));

        $this->assertStringNotContainsString('->refund(', $resourceSource.$viewSource);
        $this->assertStringNotContainsString('use App\Services\PlatformRefundService;', $resourceSource.$viewSource);
    }
}
