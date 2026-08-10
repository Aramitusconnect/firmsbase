<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\FinalizePlatformInvoiceAction;
use App\Filament\Actions\Platform\VoidPlatformInvoiceAction;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Filament\Resources\PlatformInvoiceResource\Pages\ListPlatformInvoices;
use App\Filament\Resources\PlatformInvoiceResource\Pages\ViewPlatformInvoice;
use App\Filament\Resources\PlatformInvoiceResource\RelationManagers\InvoiceLinesRelationManager;
use App\Models\BillingAccount;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformInvoiceResourceTest — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Navigation
 * visibility, route-level authorization, filters, deterministic
 * ordering, bounded pagination, MoneyDisplay rendering, no-N+1, the
 * invoice lines RelationManager, and the Finalize/Void actions' full
 * lifecycle (including the "Mark Paid" absence proof).
 */
final class PlatformInvoiceResourceTest extends TestCase
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
        $this->assertFalse(PlatformInvoiceResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformInvoiceResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformInvoiceResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_invoices_list(): void
    {
        $this->get(PlatformInvoiceResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformInvoiceResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Acme Legal']);
        $invoice = PlatformInvoice::factory()->forBillingAccount($account)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(PlatformInvoiceResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Acme Legal');

        $viewResponse = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Acme Legal');
    }

    // --- MoneyDisplay spot-check ---

    public function test_money_columns_render_via_money_display_not_raw_integers(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformInvoice::factory()->totals(24900, 100)->create();

        $response = $this->get(PlatformInvoiceResource::getUrl());
        $response->assertOk();
        $response->assertSee('249.00');
        $response->assertSee('1.00');
        $response->assertSee('250.00');
        $response->assertDontSee('24900');
    }

    // --- Empty state ---

    public function test_the_list_page_shows_an_empty_state_with_no_invoices(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformInvoiceResource::getUrl());
        $response->assertOk();
        $response->assertSee('No invoices found');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $open = PlatformInvoice::factory()->open()->create();
        $draft = PlatformInvoice::factory()->create(['status' => PlatformInvoiceStatus::Draft]);

        $test = Livewire::test(ListPlatformInvoices::class);
        $test->filterTable('status', PlatformInvoiceStatus::Open->value);

        $test->assertCanSeeTableRecords([$open]);
        $test->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_period_date_range_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $inRange = PlatformInvoice::factory()->create(['period_starts_at' => now()->parse('2026-01-05')]);
        $outOfRange = PlatformInvoice::factory()->create(['period_starts_at' => now()->parse('2026-06-05')]);

        $test = Livewire::test(ListPlatformInvoices::class);
        $test->filterTable('period', ['from' => '2026-01-01', 'until' => '2026-01-31']);

        $test->assertCanSeeTableRecords([$inRange]);
        $test->assertCanNotSeeTableRecords([$outOfRange]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_even_when_every_other_column_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedDueAt = now()->parse('2026-03-01 00:00:00');
        $account = BillingAccount::factory()->create();
        $invoices = PlatformInvoice::factory()->count(5)->forBillingAccount($account)->create(['due_at' => $sharedDueAt]);

        $first = Livewire::test(ListPlatformInvoices::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListPlatformInvoices::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied due_at rows must order identically across repeated calls.');
        $this->assertSame($invoices->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformInvoice::factory()->count(30)->create();

        $test = Livewire::test(ListPlatformInvoices::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- No-N+1 ---

    public function test_listing_many_invoices_does_not_n_plus_one(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        PlatformInvoice::factory()->create();

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(PlatformInvoiceResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        PlatformInvoice::factory()->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(PlatformInvoiceResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount, 'Adding 9 more invoices must not add ~9 extra queries.');
    }

    // --- Invoice lines relation manager ---

    public function test_the_view_page_shows_invoice_lines_including_firm_attribution(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $invoice = PlatformInvoice::factory()->create();
        PlatformInvoiceLine::factory()->forInvoice($invoice)->create(['description' => 'Base plan line', 'firm_id' => null]);

        // The View page itself loads fine...
        $response = $this->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]));
        $response->assertOk();

        // ...and the RelationManager tab (a separate Livewire mount,
        // matching ConflictsRelationManagerTest's own established
        // pattern) genuinely renders the line.
        $test = Livewire::test(InvoiceLinesRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => ViewPlatformInvoice::class,
        ]);
        $test->assertOk();
        $test->assertSee('Base plan line');
    }

    // --- Finalize action lifecycle ---

    public function test_finalize_action_moves_a_draft_invoice_to_open_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $invoice = PlatformInvoice::factory()->create(['status' => PlatformInvoiceStatus::Draft]);

        $test = Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->uuid]);
        $test->mountAction(FinalizePlatformInvoiceAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $invoice->refresh();
        $this->assertSame(PlatformInvoiceStatus::Open, $invoice->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'invoice_finalized')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
        $this->assertSame('platform_billing', $row->category);
    }

    public function test_finalize_action_is_not_visible_for_an_already_open_invoice(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $invoice = PlatformInvoice::factory()->open()->create();

        $test = Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->uuid]);
        $test->assertActionHidden(FinalizePlatformInvoiceAction::getDefaultName());
    }

    public function test_finalize_action_is_denied_for_a_billing_admin(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $invoice = PlatformInvoice::factory()->create(['status' => PlatformInvoiceStatus::Draft]);

        $test = Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->uuid]);
        $test->mountAction(FinalizePlatformInvoiceAction::getDefaultName());
        $test->callMountedAction();

        $invoice->refresh();
        $this->assertSame(PlatformInvoiceStatus::Draft, $invoice->status, 'A BillingAdmin must not be able to finalize an invoice (canManagePlatformBilling excludes BillingAdmin).');
    }

    public function test_finalize_action_is_denied_for_a_read_only_auditor_even_with_super_admin(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($actor, 'platform_admin');

        $invoice = PlatformInvoice::factory()->create(['status' => PlatformInvoiceStatus::Draft]);

        $test = Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->uuid]);
        $test->mountAction(FinalizePlatformInvoiceAction::getDefaultName());
        $test->callMountedAction();

        $invoice->refresh();
        $this->assertSame(PlatformInvoiceStatus::Draft, $invoice->status, 'A read_only_auditor must never mutate an invoice, regardless of also holding SuperAdmin.');
    }

    // --- Void action lifecycle ---

    public function test_void_action_voids_an_open_invoice_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($actor, 'platform_admin');

        $invoice = PlatformInvoice::factory()->open()->create();

        $test = Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->uuid]);
        $test->mountAction(VoidPlatformInvoiceAction::getDefaultName());
        $test->setActionData(['reason' => 'Issued in error']);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $invoice->refresh();
        $this->assertSame(PlatformInvoiceStatus::Void, $invoice->status);
        $this->assertNotNull($invoice->voided_at);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'invoice_voided')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_void_action_is_not_visible_for_a_paid_invoice(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $invoice = PlatformInvoice::factory()->create(['status' => PlatformInvoiceStatus::Paid, 'paid_at' => now()]);

        $test = Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->uuid]);
        $test->assertActionHidden(VoidPlatformInvoiceAction::getDefaultName());
    }

    public function test_void_action_is_denied_for_a_sales_rep(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $invoice = PlatformInvoice::factory()->open()->create();

        // A SalesRep cannot even reach the View page (canAccessPlatformBilling
        // excludes SalesRep) — confirming the route-level gate rather than
        // mounting the Livewire component directly.
        $this->actingAs($actor, 'platform_admin')
            ->get(PlatformInvoiceResource::getUrl('view', ['record' => $invoice]))
            ->assertForbidden();

        $invoice->refresh();
        $this->assertSame(PlatformInvoiceStatus::Open, $invoice->status);
    }

    // --- "Mark Paid" absence proof ---

    public function test_no_mark_paid_action_or_call_exists_anywhere_in_this_resource(): void
    {
        foreach ([
            app_path('Filament/Resources/PlatformInvoiceResource.php'),
            app_path('Filament/Resources/PlatformInvoiceResource/Pages/ListPlatformInvoices.php'),
            app_path('Filament/Resources/PlatformInvoiceResource/Pages/ViewPlatformInvoice.php'),
            app_path('Filament/Resources/PlatformInvoiceResource/RelationManagers/InvoiceLinesRelationManager.php'),
        ] as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('->markPaid(', $source, "{$file} must never call markPaid().");
            $this->assertStringNotContainsString("Action::make('markPaid')", $source, "{$file} must never register a markPaid Action.");
        }
    }

    // --- Exactly two mutating Actions, never more ---

    public function test_exactly_two_mutating_actions_are_registered_and_they_are_finalize_and_void(): void
    {
        $viewSource = file_get_contents(app_path('Filament/Resources/PlatformInvoiceResource/Pages/ViewPlatformInvoice.php'));

        $this->assertStringContainsString('FinalizePlatformInvoiceAction::make()', $viewSource);
        $this->assertStringContainsString('VoidPlatformInvoiceAction::make()', $viewSource);

        $listSource = file_get_contents(app_path('Filament/Resources/PlatformInvoiceResource/Pages/ListPlatformInvoices.php'));
        $this->assertStringNotContainsString('Action::make(', $listSource, 'No mutating action belongs on the List page — see PlatformAdministratorResource\'s own convention.');

        $relationManagerSource = file_get_contents(app_path('Filament/Resources/PlatformInvoiceResource/RelationManagers/InvoiceLinesRelationManager.php'));
        $this->assertStringNotContainsString('->action(', $relationManagerSource, 'The invoice lines RelationManager must stay read-only.');
    }
}
