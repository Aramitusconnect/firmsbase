<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustLedgerStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TrustLedgersForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for trust_ledgers (database/migrations/
 * 2026_08_30_980002_prepare_row_level_security_and_force_rls_on_trust_ledgers_table.php)
 * is permanently active and behaves correctly.
 *
 * Second of Wave 10's ten-table batch — see trust_accounts' own
 * activation test for the full batch list/rationale. Lands after
 * trust_accounts since trust_ledgers.trust_account_id references it.
 */
class TrustLedgersForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980002_prepare_row_level_security_and_force_rls_on_trust_ledgers_table.php';

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

    public function test_trust_ledgers_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_ledgers', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()), 'forcedTables() count must equal the number of FORCE-activation migration files on disk exactly.');
    }

    public function test_trust_ledgers_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_ledgers'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_ledgers_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_ledgers'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_ledgers'::regclass and polname = 'trust_ledgers_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The trust_ledgers_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_missing_tenant_context_cannot_read_trust_ledgers(): void
    {
        $firm = Firm::factory()->create();
        $this->createLedgerForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustLedger::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_ledgers(): void
    {
        $firm = Firm::factory()->create();
        $account = $this->createAccountForFirm($firm);
        $client = $this->createClientForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_ledgers')->insert($this->rowAttributes($firm, $account, $client));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $ledger = TrustLedger::factory()->create();

        $this->assertNotNull($ledger->id);
        $this->assertNotNull($ledger->firm_id);

        $persisted = $this->runWithFirmContext(
            $ledger->firm_id,
            fn () => TrustLedger::query()->find($ledger->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($ledger->firm_id, $persisted->firm_id);
    }

    public function test_firm_a_context_can_read_its_own_trust_ledger(): void
    {
        $firmA = Firm::factory()->create();
        $ledgerA = $this->createLedgerForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TrustLedger::query()->pluck('id')->all(),
        );

        $this->assertSame([$ledgerA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_trust_ledger(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createLedgerForFirm($firmA);
        $ledgerB = $this->createLedgerForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TrustLedger::query()->pluck('id')->all(),
        );

        $this->assertNotContains($ledgerB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_ledger(): void
    {
        $firmA = Firm::factory()->create();
        $account = $this->createAccountForFirm($firmA);
        $client = $this->createClientForFirm($firmA);

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('trust_ledgers')->insertGetId($this->rowAttributes($firmA, $account, $client)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_trust_ledger(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ledgerB = $this->createLedgerForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($ledgerB) {
            return DB::table('trust_ledgers')->where('id', $ledgerB->id)->update(['status' => TrustLedgerStatus::Frozen->value]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustLedger::query()->find($ledgerB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TrustLedgerStatus::Active, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_ledger(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ledgerB = $this->createLedgerForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($ledgerB) {
            DB::table('trust_ledgers')->where('id', $ledgerB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustLedger::query()->find($ledgerB->id));
        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B trust_ledgers.');
    }

    public function test_firm_a_cannot_insert_a_trust_ledger_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $account = $this->createAccountForFirm($firmB);
        $client = $this->createClientForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $account, $client) {
            DB::table('trust_ledgers')->insert($this->rowAttributes($firmB, $account, $client));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ledgerA = $this->createLedgerForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($ledgerA, $firmB) {
            DB::table('trust_ledgers')->where('id', $ledgerA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    /**
     * Named, proven residual gap (per the approved Wave 10 design
     * document §4.2 and every migration's own docblock): trust_ledgers
     * has a direct firm_id column and its own RLS policy compares ONLY
     * this row's own firm_id — RLS never inspects a related row's
     * firm_id transitively. If a caller already holds a real
     * trust_account_id/client_id belonging to a DIFFERENT firm than
     * the one currently active in context, a raw insert naming this
     * row's own firm_id as the CURRENT context's firm can still
     * succeed and create a firm_id/trust_account_id mismatch — RLS
     * does not and cannot close this by itself. This is proven here,
     * not merely asserted: the insert below genuinely succeeds despite
     * the cross-firm FK mismatch, demonstrating the accepted gap is
     * real (compensated by the existing, unmodified
     * TenantSafeTrustPolicyService app-layer check, never by RLS).
     */
    public function test_rls_does_not_catch_a_transitive_cross_firm_foreign_key_mismatch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->createAccountForFirm($firmB);
        $clientB = $this->createClientForFirm($firmB);

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $accountB, $clientB) {
            return DB::table('trust_ledgers')->insertGetId($this->rowAttributes($firmA, $accountB, $clientB));
        });

        $this->assertIsInt($insertedId, 'RLS alone does not prevent a trust_ledgers row whose firm_id is correct for the active context but whose trust_account_id/client_id belong to a different firm — this is a residual database-constraint gap, not an RLS guarantee, compensated at the application layer by TenantSafeTrustPolicyService.');

        $mismatched = $this->runWithFirmContext($firmA, fn () => TrustLedger::query()->find($insertedId));
        $this->assertSame($firmA->id, $mismatched->firm_id);
        $this->assertSame($accountB->id, $mismatched->trust_account_id);
        $this->assertNotSame($firmA->id, $accountB->firm_id, 'Confirms the mismatch is real: the referenced trust_account belongs to a different firm than this row claims.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $account = $this->createAccountForFirm($firm);
        $client = $this->createClientForFirm($firm);

        // createAccountForFirm()/createClientForFirm()'s own factory
        // context-hold overrides deliberately leave ambient DB session
        // context set behind them (see TenantContextService's own
        // docblock: a caller that sets context outside a wrapping
        // transaction must clear it itself when done — factories
        // intentionally don't, matching every other factory in this
        // repo). Clear it here so this assertion proves ONLY whether
        // the runWithFirmContext() call below — the actual subject of
        // this proof — cleans up after itself, not an artifact of
        // unrelated fixture setup.
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_ledgers'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'trust_ledgers'::regclass and polname = 'trust_ledgers_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_ledgers'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_trust_ledgers(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['trust_ledgers'])), 0, 5);

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
    }

    private function createAccountForFirm(Firm $firm): TrustAccount
    {
        return $this->runWithFirmContext($firm, fn () => TrustAccount::factory()->create(['firm_id' => $firm->id]));
    }

    private function createClientForFirm(Firm $firm): Client
    {
        return Client::factory()->forFirm($firm)->create();
    }

    private function createLedgerForFirm(Firm $firm): TrustLedger
    {
        $account = $this->createAccountForFirm($firm);
        $client = $this->createClientForFirm($firm);

        return $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, TrustAccount $account, Client $client): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
            'status' => TrustLedgerStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
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
