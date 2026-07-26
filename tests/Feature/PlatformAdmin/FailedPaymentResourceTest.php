<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\FailedPaymentResource;
use App\Filament\Resources\FailedPaymentResource\Pages\ListFailedPayments;
use App\Models\BillingAccount;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\PlatformPayment;
use App\Models\PlatformPaymentAttempt;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FailedPaymentResourceTest — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Navigation
 * visibility, route-level authorization, scoping to Failed attempts
 * only, filters, deterministic ordering, bounded pagination, no-N+1,
 * and — the load-bearing property — POSITIVE PROOF that no mutating (or
 * any) Filament Action exists anywhere in this resource, mirroring
 * ConflictResourceTest's established pattern.
 */
final class FailedPaymentResourceTest extends TestCase
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
        $this->assertFalse(FailedPaymentResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(FailedPaymentResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_failed_payments_list(): void
    {
        $this->get(FailedPaymentResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(FailedPaymentResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Failing Firm Account']);
        $attempt = PlatformPaymentAttempt::factory()->forBillingAccount($account)->failed()->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(FailedPaymentResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Failing Firm Account');
        $listResponse->assertSee('simulated_decline');

        $viewResponse = $this->actingAs($admin, 'platform_admin')
            ->get(FailedPaymentResource::getUrl('view', ['record' => $attempt]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('simulated_decline');
    }

    // --- Scoping: only Failed attempts, never Succeeded ---

    public function test_only_failed_attempts_are_listed_never_succeeded_ones(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $failed = PlatformPaymentAttempt::factory()->failed()->create();
        $succeeded = PlatformPaymentAttempt::factory()->create(['status' => PlatformPaymentAttemptStatus::Succeeded]);

        $test = Livewire::test(ListFailedPayments::class);
        $test->assertCanSeeTableRecords([$failed]);
        $test->assertCanNotSeeTableRecords([$succeeded]);
    }

    // --- Honesty disclosure ---

    public function test_the_list_page_discloses_why_no_retry_or_waive_action_exists(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(FailedPaymentResource::getUrl());
        $response->assertOk();
        $response->assertSee('FakeStripeGateway');
    }

    public function test_the_view_page_discloses_related_failed_payment_records(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $invoice = PlatformInvoice::factory()->create();
        $attempt = PlatformPaymentAttempt::factory()
            ->forBillingAccount($invoice->billingAccount)
            ->failed()
            ->create(['platform_invoice_id' => $invoice->id]);

        PlatformPayment::factory()->create([
            'platform_invoice_id' => $invoice->id,
            'billing_account_id' => $invoice->billing_account_id,
            'status' => 'failed',
            'gateway_payment_ref' => 'fake_pi_related_failure',
        ]);

        $response = $this->get(FailedPaymentResource::getUrl('view', ['record' => $attempt]));
        $response->assertOk();
        $response->assertSee('fake_pi_related_failure');
    }

    // --- Empty state ---

    public function test_the_list_page_shows_an_empty_state_with_no_failed_payments(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(FailedPaymentResource::getUrl());
        $response->assertOk();
        $response->assertSee('No failed payments found');
    }

    // --- Filters ---

    public function test_failure_reason_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $declined = PlatformPaymentAttempt::factory()->failed()->create(['failure_reason' => 'card_declined']);
        $insufficient = PlatformPaymentAttempt::factory()->failed()->create(['failure_reason' => 'insufficient_funds']);

        $test = Livewire::test(ListFailedPayments::class);
        $test->filterTable('failure_reason', 'card_declined');

        $test->assertCanSeeTableRecords([$declined]);
        $test->assertCanNotSeeTableRecords([$insufficient]);
    }

    public function test_date_range_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $inRange = PlatformPaymentAttempt::factory()->failed()->create(['attempted_at' => now()->parse('2026-01-05')]);
        $outOfRange = PlatformPaymentAttempt::factory()->failed()->create(['attempted_at' => now()->parse('2026-06-05')]);

        $test = Livewire::test(ListFailedPayments::class);
        $test->filterTable('date_range', ['from' => '2026-01-01', 'until' => '2026-01-31']);

        $test->assertCanSeeTableRecords([$inRange]);
        $test->assertCanNotSeeTableRecords([$outOfRange]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_attempted_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedAttemptedAt = now()->parse('2026-02-01 00:00:00');
        PlatformPaymentAttempt::factory()->count(5)->failed()->create(['attempted_at' => $sharedAttemptedAt]);

        $first = Livewire::test(ListFailedPayments::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListFailedPayments::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied attempted_at rows must order identically across repeated calls.');
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformPaymentAttempt::factory()->count(30)->failed()->create();

        $test = Livewire::test(ListFailedPayments::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- No-N+1 ---

    public function test_listing_many_failed_payments_does_not_n_plus_one(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformPaymentAttempt::factory()->failed()->create();

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(FailedPaymentResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        PlatformPaymentAttempt::factory()->count(9)->failed()->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(FailedPaymentResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount, 'Adding 9 more failed attempts must not add ~9 extra queries.');
    }

    // --- Positive proof: NO Filament Action of any kind exists anywhere ---

    public function test_the_resource_class_registers_no_filament_action_at_all(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/FailedPaymentResource.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('requiresConfirmation', $source);
        $this->assertStringNotContainsString('recordActions(', $source);
    }

    public function test_no_page_class_registers_a_filament_action(): void
    {
        foreach (['ListFailedPayments.php', 'ViewFailedPayment.php'] as $file) {
            $source = file_get_contents(app_path("Filament/Resources/FailedPaymentResource/Pages/{$file}"));
            $this->assertStringNotContainsString('Action::make(', $source, "{$file} must never register any Filament Action.");
            $this->assertStringNotContainsString('->action(', $source, "{$file} must never register any Filament Action.");
            $this->assertStringNotContainsString('getHeaderActions', $source, "{$file} must never define header actions.");
        }
    }

    public function test_neither_the_resource_nor_the_service_layer_ever_calls_a_retry_or_waive_method(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/FailedPaymentResource.php'));

        $this->assertStringNotContainsString('->attemptPayment(', $resourceSource);
        $this->assertStringNotContainsString('->markWaived(', $resourceSource);
        $this->assertStringNotContainsString('use App\Services\PlatformPaymentService;', $resourceSource);
    }
}
