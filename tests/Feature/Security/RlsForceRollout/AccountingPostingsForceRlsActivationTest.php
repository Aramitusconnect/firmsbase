<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\AccountingJournalEntry;
use App\Models\AccountingPosting;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AccountingPostingsForceRlsActivationTest — proves the new
 * accounting_postings table's permanent FORCE ROW LEVEL SECURITY
 * (2026_10_25_100004) behaves correctly, mirroring
 * AccountingJournalEntriesForceRlsActivationTest.
 */
class AccountingPostingsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_postings_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_postings'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_missing_tenant_context_cannot_read_accounting_postings(): void
    {
        $firm = Firm::factory()->create();
        AccountingPosting::factory()->forFirm($firm)->create([
            'accounting_journal_entry_id' => $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::factory()->forFirm($firm)->create()->id),
            'chart_of_account_id' => $this->runWithFirmContext($firm, fn () => ChartOfAccount::factory()->forFirm($firm)->create()->id),
        ]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AccountingPosting::query()->count());
    }

    public function test_firm_a_context_cannot_read_firm_b_accounting_postings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $entryA = $this->runWithFirmContext($firmA, fn () => AccountingJournalEntry::factory()->forFirm($firmA)->create());
        $accountA = $this->runWithFirmContext($firmA, fn () => ChartOfAccount::factory()->forFirm($firmA)->create());
        AccountingPosting::factory()->forFirm($firmA)->create([
            'accounting_journal_entry_id' => $entryA->id,
            'chart_of_account_id' => $accountA->id,
        ]);

        $entryB = $this->runWithFirmContext($firmB, fn () => AccountingJournalEntry::factory()->forFirm($firmB)->create());
        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->forFirm($firmB)->create());
        $postingB = AccountingPosting::factory()->forFirm($firmB)->create([
            'accounting_journal_entry_id' => $entryB->id,
            'chart_of_account_id' => $accountB->id,
        ]);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AccountingPosting::query()->pluck('id')->all(),
        );

        $this->assertNotContains($postingB->id, $visibleIds);
    }

    public function test_an_existing_posting_can_never_be_updated_even_under_full_firm_context(): void
    {
        $firm = Firm::factory()->create();
        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::factory()->forFirm($firm)->create());
        $account = $this->runWithFirmContext($firm, fn () => ChartOfAccount::factory()->forFirm($firm)->create());
        $posting = AccountingPosting::factory()->forFirm($firm)->create([
            'accounting_journal_entry_id' => $entry->id,
            'chart_of_account_id' => $account->id,
            'debit_cents' => 1000,
        ]);

        $this->runWithFirmContext($firm, function () use ($posting) {
            $this->expectException(\LogicException::class);
            $posting->update(['debit_cents' => 2000]);
        });
    }

    public function test_migration_down_fully_disables_row_level_security(): void
    {
        $migration = require base_path('database/migrations/2026_10_25_100004_prepare_row_level_security_and_force_rls_on_accounting_postings_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_postings'");

            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);
        } finally {
            $migration->up();
        }
    }
}
