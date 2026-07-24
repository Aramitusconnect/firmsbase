<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustAccountStatus;
use App\Models\Firm;
use App\Models\TrustAccount;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TrustAccountsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for trust_accounts (database/migrations/
 * 2026_08_30_980001_prepare_row_level_security_and_force_rls_on_trust_accounts_table.php)
 * is permanently active and behaves correctly.
 *
 * First of Wave 10's ten-table, one-batch trust accounting domain
 * activation — see that migration's own docblock for the full batch
 * list, ordering rationale, co-landed service changes, and accepted-gap
 * catalogue.
 */
class TrustAccountsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980001_prepare_row_level_security_and_force_rls_on_trust_accounts_table.php';

    private const THIS_BATCH = [
        'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
        'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
    ];

    // ---------------------------------------------------------------
    // FORCE state / policy proofs
    // ---------------------------------------------------------------

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_trust_accounts_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_accounts', $coverage->forcedTables());
    }

    /**
     * forcedTables() derives its result from every FORCE-activation
     * migration file on disk, deduplicated by table name (see its own
     * docblock). This checkpoint's exact-count proof does NOT hardcode
     * a total (that goes stale within days, per that docblock's own
     * history) — instead it independently counts the migration FILES
     * matching the exact same glob the service itself uses and asserts
     * that count equals forcedTables()'s own count exactly. A mismatch
     * here would mean two migration files declared the same `private
     * const TABLE` value (silently collapsed by array_unique()) — the
     * exact duplicate-table-name regression this rollout has hit and
     * fixed twice before (see the "Fix duplicate table-name insertion"
     * commits for Wave 8 and Wave 9). This is a genuine, no-more-no-
     * less proof: it fails if this batch introduced a duplicate, and
     * it fails if any previously forced table silently disappeared.
     */
    public function test_exact_forced_table_count_matches_the_dynamically_discovered_migration_set(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(
            count($matchingFiles),
            count($coverage->forcedTables()),
            'forcedTables() count must equal the number of FORCE-activation migration files on disk exactly — a mismatch means a duplicate `private const TABLE` value silently collapsed two migrations into one entry.'
        );

        foreach (self::THIS_BATCH as $table) {
            $this->assertContains($table, $coverage->forcedTables(), "{$table} must be present in forcedTables() after this checkpoint.");
        }
    }

    public function test_trust_accounts_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_accounts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_accounts_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_accounts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'trust_accounts must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.');
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_accounts'::regclass and polname = 'trust_accounts_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The trust_accounts_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_trust_accounts(): void
    {
        $firm = Firm::factory()->create();
        $this->createAccountForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustAccount::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_accounts(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_accounts')->insert($this->rowAttributes($firm));
    }

    /**
     * TrustAccountFactory gained a context-hold create() override in
     * this batch — its bare default-creation path is already
     * tenant-consistent, so a bare TrustAccount::factory()->create()
     * must now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $account = TrustAccount::factory()->create();

        $this->assertNotNull($account->id);
        $this->assertNotNull($account->firm_id);

        $persisted = $this->runWithFirmContext(
            $account->firm_id,
            fn () => TrustAccount::query()->find($account->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($account->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_trust_account(): void
    {
        $firmA = Firm::factory()->create();
        $accountA = $this->createAccountForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TrustAccount::query()->pluck('id')->all(),
        );

        $this->assertSame([$accountA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_trust_account(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createAccountForFirm($firmA);
        $accountB = $this->createAccountForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TrustAccount::query()->pluck('id')->all(),
        );

        $this->assertNotContains($accountB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_account(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('trust_accounts')->insertGetId($this->rowAttributes($firmA)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_trust_account(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->createAccountForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($accountB) {
            return DB::table('trust_accounts')->where('id', $accountB->id)->update(['status' => TrustAccountStatus::Suspended->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s trust_accounts row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TrustAccount::query()->find($accountB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TrustAccountStatus::Active, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_account(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->createAccountForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($accountB) {
            DB::table('trust_accounts')->where('id', $accountB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TrustAccount::query()->find($accountB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B trust_accounts.');
    }

    public function test_firm_a_cannot_insert_a_trust_account_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('trust_accounts')->insert($this->rowAttributes($firmB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountA = $this->createAccountForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($accountA, $firmB) {
            DB::table('trust_accounts')->where('id', $accountA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createAccountForFirm($firm);

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

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_accounts'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'trust_accounts'::regclass and polname = 'trust_accounts_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_accounts'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_trust_accounts(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_values(array_diff($coverage->preparedTables(), ['trust_accounts']));
        $otherTables = array_slice($otherTables, 0, 5);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertEquals($before[$table], $after, "{$table}'s RLS state must be unaffected by trust_accounts' own migration round trip.");
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

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

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this checkpoint must not add policies for any other uncovered table."
            );
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php');

        $this->assertEmpty(
            $changed,
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once this batch has landed.'
        );
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    private function createAccountForFirm(Firm $firm): TrustAccount
    {
        return $this->runWithFirmContext($firm, fn () => TrustAccount::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'account_name' => 'Firm IOLTA Trust Account',
            'bank_name_reference' => 'Reference Bank (no real bank integration)',
            'status' => TrustAccountStatus::Active->value,
            'opened_at' => now(),
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
