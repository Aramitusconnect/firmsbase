<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PaymentAllocationsForceRlsActivationTest — proves the new
 * payment_allocations table's permanent FORCE ROW LEVEL SECURITY
 * (2026_10_27_100002) behaves correctly: fail-closed with no context,
 * correct cross-firm isolation, and that the append-only model guard
 * is independent of and complementary to RLS.
 */
class PaymentAllocationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_allocations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_allocations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_payment_allocations(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));
        PaymentAllocation::factory()->forFirm($firm)->create(['payment_id' => $payment->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PaymentAllocation::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_allocations(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_allocations')->insert([
            'firm_id' => $firm->id,
            'payment_id' => $payment->id,
            'amount_cents' => 1000,
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_allocations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentA = $this->runWithFirmContext($firmA, fn () => Payment::factory()->create(['firm_id' => $firmA->id]));
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->create(['firm_id' => $firmB->id]));
        PaymentAllocation::factory()->forFirm($firmA)->create(['payment_id' => $paymentA->id]);
        $allocationB = PaymentAllocation::factory()->forFirm($firmB)->create(['payment_id' => $paymentB->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentAllocation::query()->pluck('id')->all(),
        );

        $this->assertNotContains($allocationB->id, $visibleIds);
    }

    public function test_an_existing_allocation_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));
        $allocation = PaymentAllocation::factory()->forFirm($firm)->create(['payment_id' => $payment->id]);

        $this->runWithFirmContext($firm, function () use ($allocation) {
            $this->expectException(\LogicException::class);
            $allocation->update(['amount_cents' => 999]);
        });
    }

    public function test_an_existing_allocation_can_never_be_deleted_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));
        $allocation = PaymentAllocation::factory()->forFirm($firm)->create(['payment_id' => $payment->id]);

        $this->runWithFirmContext($firm, function () use ($allocation) {
            $this->expectException(\LogicException::class);
            $allocation->delete();
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_27_100002_prepare_row_level_security_and_force_rls_on_payment_allocations_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_allocations'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
