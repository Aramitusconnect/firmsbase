<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\PaymentReversalType;
use App\Models\Firm;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentReversalsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_reversals_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_reversals'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_payment_reversals(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));
        PaymentReversal::factory()->forFirm($firm)->create(['payment_id' => $payment->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PaymentReversal::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_reversals(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_reversals')->insert([
            'firm_id' => $firm->id,
            'payment_id' => $payment->id,
            'reversal_type' => PaymentReversalType::Refund->value,
            'amount_cents' => 1000,
            'reason' => 'test',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_reversals(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentA = $this->runWithFirmContext($firmA, fn () => Payment::factory()->create(['firm_id' => $firmA->id]));
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->create(['firm_id' => $firmB->id]));
        PaymentReversal::factory()->forFirm($firmA)->create(['payment_id' => $paymentA->id]);
        $reversalB = PaymentReversal::factory()->forFirm($firmB)->create(['payment_id' => $paymentB->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentReversal::query()->pluck('id')->all(),
        );

        $this->assertNotContains($reversalB->id, $visibleIds);
    }

    public function test_an_existing_reversal_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));
        $reversal = PaymentReversal::factory()->forFirm($firm)->create(['payment_id' => $payment->id]);

        $this->runWithFirmContext($firm, function () use ($reversal) {
            $this->expectException(\LogicException::class);
            $reversal->update(['amount_cents' => 999]);
        });
    }

    public function test_an_existing_reversal_can_never_be_deleted_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->create(['firm_id' => $firm->id]));
        $reversal = PaymentReversal::factory()->forFirm($firm)->create(['payment_id' => $payment->id]);

        $this->runWithFirmContext($firm, function () use ($reversal) {
            $this->expectException(\LogicException::class);
            $reversal->delete();
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_28_100002_prepare_row_level_security_and_force_rls_on_payment_reversals_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_reversals'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
