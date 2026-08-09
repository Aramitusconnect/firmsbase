<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceWriteOffsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_write_offs_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'invoice_write_offs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_invoice_write_offs(): void
    {
        $firm = Firm::factory()->create();
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->create(['firm_id' => $firm->id]));
        InvoiceWriteOff::factory()->forFirm($firm)->create(['invoice_id' => $invoice->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, InvoiceWriteOff::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_invoice_write_offs(): void
    {
        $firm = Firm::factory()->create();
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('invoice_write_offs')->insert([
            'firm_id' => $firm->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => 1000,
            'reason' => 'test',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_invoice_write_offs(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $invoiceA = $this->runWithFirmContext($firmA, fn () => Invoice::factory()->create(['firm_id' => $firmA->id]));
        $invoiceB = $this->runWithFirmContext($firmB, fn () => Invoice::factory()->create(['firm_id' => $firmB->id]));
        InvoiceWriteOff::factory()->forFirm($firmA)->create(['invoice_id' => $invoiceA->id]);
        $writeOffB = InvoiceWriteOff::factory()->forFirm($firmB)->create(['invoice_id' => $invoiceB->id]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => InvoiceWriteOff::query()->pluck('id')->all(),
        );

        $this->assertNotContains($writeOffB->id, $visibleIds);
    }

    public function test_an_existing_write_off_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->create(['firm_id' => $firm->id]));
        $writeOff = InvoiceWriteOff::factory()->forFirm($firm)->create(['invoice_id' => $invoice->id]);

        $this->runWithFirmContext($firm, function () use ($writeOff) {
            $this->expectException(\LogicException::class);
            $writeOff->update(['amount_cents' => 999]);
        });
    }

    public function test_an_existing_write_off_can_never_be_deleted_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->create(['firm_id' => $firm->id]));
        $writeOff = InvoiceWriteOff::factory()->forFirm($firm)->create(['invoice_id' => $invoice->id]);

        $this->runWithFirmContext($firm, function () use ($writeOff) {
            $this->expectException(\LogicException::class);
            $writeOff->delete();
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_28_100004_prepare_row_level_security_and_force_rls_on_invoice_write_offs_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'invoice_write_offs'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
