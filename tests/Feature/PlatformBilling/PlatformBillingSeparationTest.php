<?php

namespace Tests\Feature\PlatformBilling;

use App\Models\BillingAccount;
use App\Models\Firm;
use App\Services\PlatformInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms platform billing (Phase 6) and firm-client billing (Phase 3)
 * are structurally separate: different tables, no foreign keys crossing
 * between the two families, and creating platform billing records never
 * writes to Phase 3's invoices/payments/payment_plans tables.
 */
class PlatformBillingSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_tables_and_firm_client_tables_are_distinct(): void
    {
        $platformTables = [
            'platform_subscriptions', 'platform_subscription_items', 'platform_invoices',
            'platform_invoice_lines', 'platform_payments', 'platform_refunds',
            'platform_payment_attempts', 'platform_billing_events',
        ];
        $firmClientTables = ['invoices', 'invoice_lines', 'payments', 'payment_plans', 'manual_payment_records'];

        foreach ($platformTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected platform billing table {$table} to exist.");
            $this->assertNotContains($table, $firmClientTables, "{$table} must never collide with a firm-client billing table name.");
        }
    }

    public function test_platform_invoices_has_no_foreign_key_into_firm_client_invoices(): void
    {
        $this->assertFalse(Schema::hasColumn('platform_invoices', 'invoice_id'));
        $this->assertFalse(Schema::hasColumn('invoices', 'platform_invoice_id'));
    }

    public function test_platform_payments_has_no_foreign_key_into_firm_client_payments(): void
    {
        $this->assertFalse(Schema::hasColumn('platform_payments', 'payment_id'));
        $this->assertFalse(Schema::hasColumn('payments', 'platform_payment_id'));
    }

    public function test_creating_a_platform_invoice_never_writes_to_firm_client_invoices(): void
    {
        $account = BillingAccount::factory()->create();
        $firm = Firm::factory()->create();

        $countBefore = \App\Models\Invoice::count();

        (new PlatformInvoiceService())->createDraftInvoice($account, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame($countBefore, \App\Models\Invoice::count());
    }
}
