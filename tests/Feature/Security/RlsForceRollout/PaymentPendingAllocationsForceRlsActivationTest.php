<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingPaymentAllocation;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PaymentPendingAllocationsForceRlsActivationTest — Mixed-Invoice
 * Revenue Allocation pass. Proves payment_pending_allocations'
 * permanent FORCE ROW LEVEL SECURITY (2026_11_02_100003) behaves
 * correctly: fail-closed with no context, correct cross-firm
 * isolation, and that a legitimate resolution write keeps working.
 */
class PaymentPendingAllocationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private function makePending(Firm $firm): PendingPaymentAllocation
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $payment = Payment::factory()->create(['firm_id' => $firm->id]);
            $invoice = Invoice::factory()->forClient($payment->client)->create(['firm_id' => $firm->id]);

            return PendingPaymentAllocation::factory()->forFirm($firm)->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
            ]);
        });
    }

    public function test_payment_pending_allocations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_pending_allocations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_payment_pending_allocations(): void
    {
        $firm = Firm::factory()->create();
        $this->makePending($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PendingPaymentAllocation::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_pending_allocations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->makePending($firmA);
        $pendingB = $this->makePending($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PendingPaymentAllocation::query()->pluck('id')->all(),
        );

        $this->assertNotContains($pendingB->id, $visibleIds);
    }

    public function test_legitimate_firm_context_writes_keep_working(): void
    {
        $firm = Firm::factory()->create();
        $pending = $this->makePending($firm);

        $this->runWithFirmContext($firm, fn () => $pending->update(['status' => 'resolved']));

        $reRead = $this->runWithFirmContext($firm, fn () => $pending->fresh()->status->value);
        $this->assertSame('resolved', $reRead);
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_11_02_100003_prepare_row_level_security_and_force_rls_on_payment_pending_allocations_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_pending_allocations'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
