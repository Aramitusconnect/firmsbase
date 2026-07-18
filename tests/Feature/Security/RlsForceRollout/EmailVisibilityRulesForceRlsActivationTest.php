<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\EmailAccount;
use App\Models\EmailVisibilityRule;
use App\Models\Firm;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EmailVisibilityRulesForceRlsActivationTest — Section 39A-5 Wave 1
 * (independent checkpoint). Proves the FORCE ROW LEVEL SECURITY
 * activation for email_visibility_rules (database/migrations/
 * 2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/update/delete, correct
 * same-firm access, insert and ownership-reassignment protection under
 * the explicit WITH CHECK clause, and that every previously-prepared
 * table remains forced simultaneously.
 *
 * Wave 1 note: this checkpoint lands independently of the other Wave 1
 * tables (ai_retrieval_indexes, deployment_configs, firm_ai_settings).
 * RowLevelSecurityCoverageMappingService (which still lists
 * email_visibility_rules under MISSING_PREPARED_TABLES at the point this
 * test runs standalone) is updated once by the coordinator after every
 * checkpoint in this wave has landed — NOT by this checkpoint.
 * Consequently, this test deliberately does NOT assert that
 * email_visibility_rules appears in $coverage->preparedTables(), does
 * NOT assert any exact "N prepared tables" count, and does NOT assert it
 * is no longer reported as missing — all of that belongs to the
 * wave-integration update. What IS asserted directly against
 * pg_class/pg_policy (the live database state this migration actually
 * produced) is the row security/policy reality for
 * email_visibility_rules itself, independent of the registry.
 *
 * EmailVisibilityRuleFactory was intentionally NOT given its own
 * context-hold create() override for this checkpoint (no production
 * writer exists for this table today — EmailVisibilityPolicyService::
 * resolveScope()/canView() have zero callers anywhere in the
 * repository, confirmed by direct search — so there is nothing yet to
 * wire tenant context into). Empirically, a bare
 * EmailVisibilityRule::factory()->create() call still succeeds today
 * regardless — not due to any gap in this table's own policy, but
 * because its definition() nests a FirmUser::factory()->create() call
 * (for created_by_firm_user_id), and FirmUserFactory::create() carries
 * its own, pre-existing, deliberate context-hold convention (Section
 * 39A-3B) that establishes and leaves app.current_firm_id set as a
 * side effect. See test_bare_factory_create_succeeds_only_via_nested_
 * firm_user_factorys_own_context_hold below, which documents this
 * precisely. The genuine, unambiguous fail-closed proof (no context
 * anywhere in scope => write rejected) is
 * test_missing_tenant_context_cannot_write_email_visibility_rules,
 * which uses a raw insert with no nested factory chain involved.
 */
class EmailVisibilityRulesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_previously_prepared_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_email_visibility_rules_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'email_visibility_rules'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_email_visibility_rules_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'email_visibility_rules'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'email_visibility_rules must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'email_visibility_rules'::regclass and polname = 'email_visibility_rules_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The email_visibility_rules_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_email_visibility_rules(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firm)->create()
        )->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, EmailVisibilityRule::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_email_visibility_rules(): void
    {
        $firm = Firm::factory()->create();
        $account = $this->runWithFirmContext($firm, fn () => EmailAccount::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('email_visibility_rules')->insert([
            'firm_id' => $firm->id,
            'email_account_id' => $account->id,
            'matter_id' => null,
            'visibility_scope' => 'owner_only',
            'created_by_firm_user_id' => $account->connected_by_firm_user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * NOTE: this does NOT prove "bare factory create fails closed" —
     * empirically, it does not, and that is not a gap in this
     * checkpoint. EmailVisibilityRuleFactory's bare definition()
     * nests a FirmUser::factory()->create() call (for
     * created_by_firm_user_id), and FirmUserFactory::create() carries
     * a pre-existing, deliberate context-hold convention (Section
     * 39A-3B, unrelated to this checkpoint): it establishes
     * app.current_firm_id for the firm it just created FirmUser under,
     * and deliberately leaves it set afterward for the common
     * "create then read" test pattern. That ambient context is then
     * incidentally still active when EmailVisibilityRuleFactory's own
     * insert runs, so a bare create() succeeds today — with a fully
     * consistent, correct firm_id (not a cross-firm leak), purely
     * because of that unrelated nested factory's side effect, not
     * because of any hole in this table's own RLS policy.
     *
     * The actual fail-closed guarantee (no context anywhere in scope
     * => write rejected) is proven directly and unambiguously by
     * test_missing_tenant_context_cannot_write_email_visibility_rules
     * above, via a raw DB::table()->insert() with no nested factory
     * chain to incidentally establish context. This test instead
     * documents and locks in the observed factory-chain behavior so a
     * future change to FirmUserFactory's convention doesn't silently
     * alter it unnoticed.
     */
    public function test_bare_factory_create_succeeds_only_via_nested_firm_user_factorys_own_context_hold(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $rule = EmailVisibilityRule::factory()->create();

        $this->assertNotNull($rule->id);

        $firmUser = \App\Models\FirmUser::find($rule->created_by_firm_user_id);
        $this->assertNotNull($firmUser);
        $this->assertSame(
            $rule->firm_id,
            $firmUser->firm_id,
            'The row created via the incidental ambient context must still be internally firm-consistent.'
        );
    }

    public function test_firm_a_context_can_read_its_own_email_visibility_rules(): void
    {
        $firmA = Firm::factory()->create();
        $ruleA = $this->runWithFirmContext($firmA, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firmA)->create()
        )->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailVisibilityRule::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$ruleA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_email_visibility_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firmA)->create()
        )->create());
        $ruleB = $this->runWithFirmContext($firmB, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firmB)->create()
        )->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => EmailVisibilityRule::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($ruleB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_email_visibility_rule(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            $account = EmailAccount::factory()->forFirm($firmA)->create();

            return DB::table('email_visibility_rules')->insertGetId([
                'firm_id' => $firmA->id,
                'email_account_id' => $account->id,
                'matter_id' => null,
                'visibility_scope' => 'owner_only',
                'created_by_firm_user_id' => $account->connected_by_firm_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_email_visibility_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailVisibilityRule::factory()->forAccount(
                EmailAccount::factory()->forFirm($firmB)->create()
            )->create(['visibility_scope' => 'owner_only']),
        );

        $this->runWithFirmContext($firmA, function () use ($ruleB) {
            DB::table('email_visibility_rules')->where('id', $ruleB->id)->update(['visibility_scope' => 'firm_wide']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailVisibilityRule::withoutGlobalScopes()->find($ruleB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('owner_only', $reReadAsFirmB->visibility_scope->value);
    }

    public function test_firm_a_cannot_delete_firm_b_email_visibility_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleB = $this->runWithFirmContext($firmB, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firmB)->create()
        )->create());

        $this->runWithFirmContext($firmA, function () use ($ruleB) {
            DB::table('email_visibility_rules')->where('id', $ruleB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => EmailVisibilityRule::withoutGlobalScopes()->find($ruleB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B email_visibility_rules.');
    }

    public function test_firm_a_cannot_insert_an_email_visibility_rule_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->runWithFirmContext($firmB, fn () => EmailAccount::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $accountB) {
            DB::table('email_visibility_rules')->insert([
                'firm_id' => $firmB->id,
                'email_account_id' => $accountB->id,
                'matter_id' => null,
                'visibility_scope' => 'owner_only',
                'created_by_firm_user_id' => $accountB->connected_by_firm_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleA = $this->runWithFirmContext($firmA, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firmA)->create()
        )->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($ruleA, $firmB) {
            DB::table('email_visibility_rules')->where('id', $ruleA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => EmailVisibilityRule::factory()->forAccount(
            EmailAccount::factory()->forFirm($firm)->create()
        )->create());

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

    /**
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration introduced
     * the policy itself. up() is restored in a finally block so later
     * tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path('database/migrations/2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'email_visibility_rules'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'email_visibility_rules'::regclass and polname = 'email_visibility_rules_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only email_visibility_rules —
     * sampled: a handful of the previously-PREPARED tables, plus one
     * representative still-uncovered (MISSING_PREPARED_TABLES) table —
     * are bit-for-bit identical before and after a down()+up() round
     * trip.
     */
    public function test_migration_round_trip_affects_only_email_visibility_rules(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'customer_success_health_scores', // the most recent prior checkpoint's table
            'matter_expenses', // representative still-missing table, not touched by THIS checkpoint's migration
        ]);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path('database/migrations/2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php');
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the email_visibility_rules migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the email_visibility_rules migration round trip."
            );
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_only_this_checkpoints_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowed = [
            'database/migrations/2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php',
            'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        ];

        $unexpected = array_values(array_diff($changed, $allowed));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this checkpoint: '.implode(', ', $unexpected));
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
