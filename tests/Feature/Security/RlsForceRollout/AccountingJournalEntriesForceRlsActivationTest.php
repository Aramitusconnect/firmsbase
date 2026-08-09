<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AccountingJournalSourceType;
use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AccountingJournalEntriesForceRlsActivationTest — proves the new
 * accounting_journal_entries table's permanent FORCE ROW LEVEL
 * SECURITY (2026_10_25_100003) behaves correctly: fail-closed with no
 * context, correct cross-firm isolation, and that the append-only
 * model guard is independent of and complementary to RLS.
 */
class AccountingJournalEntriesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_journal_entries_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_journal_entries'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_accounting_journal_entries(): void
    {
        $firm = Firm::factory()->create();
        AccountingJournalEntry::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AccountingJournalEntry::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_accounting_journal_entries(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('accounting_journal_entries')->insert([
            'firm_id' => $firm->id,
            'entry_date' => now()->toDateString(),
            'description' => 'No context insert',
            'source_type' => AccountingJournalSourceType::Adjustment->value,
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_cannot_read_firm_b_accounting_journal_entries(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        AccountingJournalEntry::factory()->forFirm($firmA)->create();
        $entryB = AccountingJournalEntry::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AccountingJournalEntry::query()->pluck('id')->all(),
        );

        $this->assertNotContains($entryB->id, $visibleIds);
    }

    public function test_firm_a_context_cannot_insert_an_entry_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('accounting_journal_entries')->insert([
                'firm_id' => $firmB->id,
                'entry_date' => now()->toDateString(),
                'description' => 'Cross-firm insert attempt',
                'source_type' => AccountingJournalSourceType::Adjustment->value,
                'created_at' => now(),
            ]);
        });
    }

    public function test_an_existing_entry_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $entry = AccountingJournalEntry::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($entry) {
            $this->expectException(\LogicException::class);
            $entry->update(['description' => 'Edited']);
        });
    }

    public function test_an_existing_entry_can_never_be_deleted_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $entry = AccountingJournalEntry::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($entry) {
            $this->expectException(\LogicException::class);
            $entry->delete();
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_25_100003_prepare_row_level_security_and_force_rls_on_accounting_journal_entries_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_journal_entries'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
