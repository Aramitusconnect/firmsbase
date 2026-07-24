<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustReconciliationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\TrustReconciliation;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TrustReconciliationsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for trust_reconciliations (database/
 * migrations/2026_08_30_980008_prepare_row_level_security_and_force_rls_on_trust_reconciliations_table.php)
 * is permanently active and behaves correctly.
 *
 * Eighth of Wave 10's ten-table batch — explicitly gated on the §0
 * fail-open fix in TrustReconciliationService::run(). See
 * TrustReconciliationServiceTest for the dedicated end-to-end
 * regression proof that the fail-open bug is genuinely closed (a
 * nonzero real ledger balance asserted against a $0 bank balance
 * correctly reports Discrepancy, not Balanced).
 */
class TrustReconciliationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980008_prepare_row_level_security_and_force_rls_on_trust_reconciliations_table.php';

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

    public function test_trust_reconciliations_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_reconciliations', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_trust_reconciliations_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_reconciliations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_reconciliations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_reconciliations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_reconciliations'::regclass and polname = 'trust_reconciliations_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_missing_tenant_context_cannot_read_trust_reconciliations(): void
    {
        $firm = Firm::factory()->create();
        $this->createReconciliationForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustReconciliation::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_reconciliations(): void
    {
        $firm = Firm::factory()->create();
        $account = $this->createAccountForFirm($firm);
        $performedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_reconciliations')->insert($this->rowAttributes($firm, $account, $performedBy));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $reconciliation = TrustReconciliation::factory()->create();

        $this->assertNotNull($reconciliation->id);

        $persisted = $this->runWithFirmContext($reconciliation->firm_id, fn () => TrustReconciliation::query()->find($reconciliation->id));

        $this->assertNotNull($persisted);
        $this->assertSame($reconciliation->firm_id, $persisted->firm_id);
    }

    public function test_firm_a_context_can_read_its_own_trust_reconciliation(): void
    {
        $firmA = Firm::factory()->create();
        $reconciliationA = $this->createReconciliationForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustReconciliation::query()->pluck('id')->all());

        $this->assertSame([$reconciliationA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_trust_reconciliation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createReconciliationForFirm($firmA);
        $reconciliationB = $this->createReconciliationForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustReconciliation::query()->pluck('id')->all());

        $this->assertNotContains($reconciliationB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_reconciliation(): void
    {
        $firmA = Firm::factory()->create();
        $account = $this->createAccountForFirm($firmA);
        $performedBy = FirmUser::factory()->create(['firm_id' => $firmA->id]);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('trust_reconciliations')->insertGetId($this->rowAttributes($firmA, $account, $performedBy)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_trust_reconciliation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $reconciliationB = $this->createReconciliationForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($reconciliationB) {
            return DB::table('trust_reconciliations')->where('id', $reconciliationB->id)->update(['status' => TrustReconciliationStatus::Discrepancy->value]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustReconciliation::query()->find($reconciliationB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TrustReconciliationStatus::Balanced, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_reconciliation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $reconciliationB = $this->createReconciliationForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($reconciliationB) {
            DB::table('trust_reconciliations')->where('id', $reconciliationB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustReconciliation::query()->find($reconciliationB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_trust_reconciliation_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $account = $this->createAccountForFirm($firmB);
        $performedBy = FirmUser::factory()->create(['firm_id' => $firmB->id]);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $account, $performedBy) {
            DB::table('trust_reconciliations')->insert($this->rowAttributes($firmB, $account, $performedBy));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $reconciliationA = $this->createReconciliationForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($reconciliationA, $firmB) {
            DB::table('trust_reconciliations')->where('id', $reconciliationA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $account = $this->createAccountForFirm($firm);
        $performedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => TrustReconciliation::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'performed_by_firm_user_id' => $performedBy->id,
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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_reconciliations'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'trust_reconciliations'::regclass and polname = 'trust_reconciliations_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_reconciliations'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_trust_reconciliations(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['trust_reconciliations'])), 0, 5);

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

    private function createReconciliationForFirm(Firm $firm): TrustReconciliation
    {
        $account = $this->createAccountForFirm($firm);
        $performedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        return $this->runWithFirmContext($firm, fn () => TrustReconciliation::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'performed_by_firm_user_id' => $performedBy->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, TrustAccount $account, FirmUser $performedBy): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'system_balance_cents' => 10000,
            'asserted_bank_balance_cents' => 10000,
            'discrepancy_cents' => 0,
            'status' => TrustReconciliationStatus::Balanced->value,
            'performed_by_firm_user_id' => $performedBy->id,
            'completed_at' => now(),
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
