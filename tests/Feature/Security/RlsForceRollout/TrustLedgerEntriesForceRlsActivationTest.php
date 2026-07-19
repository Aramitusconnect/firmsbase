<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustLedgerEntryType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TrustLedgerEntriesForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for trust_ledger_entries (database/
 * migrations/2026_08_30_980005_prepare_row_level_security_and_force_rls_on_trust_ledger_entries_table.php)
 * is permanently active and behaves correctly.
 *
 * Fifth of Wave 10's ten-table batch — the highest-tier table (Tier 3),
 * third member of the shared lock unit. Its model does NOT use
 * BelongsToTenant AND is append-only via a booted() guard — this file
 * proves both: RLS alone isolates cross-firm reads, and the append-only
 * guard still blocks update/delete independently of and in addition to
 * RLS.
 */
class TrustLedgerEntriesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980005_prepare_row_level_security_and_force_rls_on_trust_ledger_entries_table.php';

    private const THIS_BATCH = [
        'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
        'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
    ];

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_trust_ledger_entries_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_ledger_entries', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_trust_ledger_entries_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_ledger_entries'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_ledger_entries_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_ledger_entries'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_ledger_entries'::regclass and polname = 'trust_ledger_entries_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_trust_ledger_entry_model_does_not_use_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(TrustLedgerEntry::class);

        $this->assertArrayNotHasKey(\App\Models\Concerns\BelongsToTenant::class, $traits);
    }

    // ---------------------------------------------------------------
    // Append-only guard — independent of and complementary to RLS
    // ---------------------------------------------------------------

    public function test_append_only_guard_still_blocks_update_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $entry = $this->createEntryForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $entry->update(['amount_cents' => 1]));
    }

    public function test_append_only_guard_still_blocks_delete_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $entry = $this->createEntryForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $entry->delete());
    }

    public function test_missing_tenant_context_cannot_read_trust_ledger_entries(): void
    {
        $firm = Firm::factory()->create();
        $this->createEntryForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustLedgerEntry::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_ledger_entries(): void
    {
        $firm = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_ledger_entries')->insert($this->rowAttributes($firm, $ledger));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $entry = TrustLedgerEntry::factory()->create();

        $this->assertNotNull($entry->id);

        $persisted = $this->runWithFirmContext($entry->firm_id, fn () => TrustLedgerEntry::query()->find($entry->id));

        $this->assertNotNull($persisted);
        $this->assertSame($entry->firm_id, $persisted->firm_id);
    }

    public function test_rls_alone_isolates_cross_firm_reads_despite_the_missing_belongs_to_tenant_scope(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryA = $this->createEntryForFirm($firmA);
        $entryB = $this->createEntryForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustLedgerEntry::query()->pluck('id')->all());

        $this->assertSame([$entryA->id], $visibleIds);
        $this->assertNotContains($entryB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_ledger_entry(): void
    {
        $firmA = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('trust_ledger_entries')->insertGetId($this->rowAttributes($firmA, $ledger)));

        $this->assertIsInt($insertedId);
    }

    /**
     * A raw update() bypasses the model's own booted() guard entirely
     * (that guard hooks Eloquent's updating event, not the DB layer) —
     * this test proves RLS's WITH CHECK also independently blocks a
     * cross-firm raw update, a second, structurally different
     * protection than the append-only guard above.
     */
    public function test_firm_a_cannot_update_firm_b_trust_ledger_entry_via_raw_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryB = $this->createEntryForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($entryB) {
            return DB::table('trust_ledger_entries')->where('id', $entryB->id)->update(['amount_cents' => 1]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustLedgerEntry::query()->find($entryB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(10000, $reReadAsFirmB->amount_cents);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_ledger_entry(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryB = $this->createEntryForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($entryB) {
            DB::table('trust_ledger_entries')->where('id', $entryB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustLedgerEntry::query()->find($entryB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_trust_ledger_entry_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $ledger) {
            DB::table('trust_ledger_entries')->insert($this->rowAttributes($firmB, $ledger));
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => TrustLedgerEntry::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
        ]));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_ledger_entries'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'trust_ledger_entries'::regclass and polname = 'trust_ledger_entries_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_ledger_entries'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_trust_ledger_entries(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['trust_ledger_entries'])), 0, 5);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertEquals($before[$table], $after);
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, self::THIS_BATCH, true)) {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse((bool) $row->relrowsecurity, "{$table} must not gain RLS from this checkpoint.");
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php'));
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php'));
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $this->assertEmpty($this->changedOrUntrackedPaths($relativeDir));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    private function createAccountForFirm(Firm $firm): TrustAccount
    {
        return $this->runWithFirmContext($firm, fn () => TrustAccount::factory()->create(['firm_id' => $firm->id]));
    }

    private function createLedgerForFirm(Firm $firm): TrustLedger
    {
        $account = $this->createAccountForFirm($firm);
        $client = Client::factory()->forFirm($firm)->create();

        return $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
        ]));
    }

    private function createEntryForFirm(Firm $firm): TrustLedgerEntry
    {
        $ledger = $this->createLedgerForFirm($firm);

        return $this->runWithFirmContext($firm, fn () => TrustLedgerEntry::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, TrustLedger $ledger): array
    {
        return [
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit->value,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
