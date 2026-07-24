<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\FirmUserRole;
use App\Models\AiRetrievalIndex;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use App\Services\AiRetrievalIsolationService;
use App\Services\MatterAccessPolicyService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AiRetrievalIndexesForceRlsActivationTest — Section 39A-5, Wave 1,
 * Checkpoint 1 of 3. Proves the FORCE ROW LEVEL SECURITY activation
 * for ai_retrieval_indexes (database/migrations/2026_08_27_950001_
 * prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/update/delete, correct
 * same-firm access, insert and ownership-reassignment protection under
 * the explicit WITH CHECK clause, and that every one of the 53
 * previously-prepared tables remains forced simultaneously.
 *
 * Wave 1 note: this is one of THREE tables being activated in the same
 * wave (ai_retrieval_indexes, deployment_configs, firm_ai_settings).
 * Each checkpoint's migration/service/test batch lands independently;
 * RowLevelSecurityCoverageMappingService (which still lists
 * ai_retrieval_indexes under MISSING_PREPARED_TABLES at the point this
 * test runs standalone) is updated once by the coordinator after all
 * three checkpoints have landed — NOT by this checkpoint. Consequently,
 * this test deliberately does NOT assert that ai_retrieval_indexes
 * appears in $coverage->preparedTables(), does NOT assert any exact
 * "N prepared tables" count, and does NOT assert it is no longer
 * reported as missing — all of that belongs to the wave-integration
 * update. What IS asserted directly against pg_class/pg_policy (the
 * live database state this migration actually produced) is the row
 * security/policy reality for ai_retrieval_indexes itself, independent
 * of the registry.
 *
 * Deliberately does NOT prove that a bare
 * AiRetrievalIndex::factory()->create() succeeds — AiRetrievalIndexFactory
 * was intentionally NOT given a context-hold create() override for this
 * checkpoint (matching the customer_success_health_scores precedent):
 * tests and callers must establish firm context explicitly, and a bare
 * factory call must fail closed exactly like any other unscoped write.
 * See test_bare_factory_create_without_context_fails_closed below.
 */
class AiRetrievalIndexesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private AiRetrievalIsolationService $isolationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolationService = new AiRetrievalIsolationService(new MatterAccessPolicyService);
    }

    public function test_all_fifty_three_previously_prepared_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_ai_retrieval_indexes_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'ai_retrieval_indexes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_ai_retrieval_indexes_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'ai_retrieval_indexes'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'ai_retrieval_indexes must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'ai_retrieval_indexes'::regclass and polname = 'ai_retrieval_indexes_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The ai_retrieval_indexes_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_ai_retrieval_indexes(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => AiRetrievalIndex::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AiRetrievalIndex::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_ai_retrieval_indexes(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('ai_retrieval_indexes')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'namespace_identifier' => 'firm-ns-'.(string) Str::uuid(),
            'status' => 'provisioned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deliberately does NOT use the model factory's bare create() —
     * AiRetrievalIndexFactory has no context-hold override (reviewed
     * decision, see this class's own docblock), so a bare factory
     * create() must fail exactly like the raw insert above.
     */
    public function test_bare_factory_create_without_context_fails_closed(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        AiRetrievalIndex::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_ai_retrieval_indexes(): void
    {
        $firmA = Firm::factory()->create();
        $indexA = $this->runWithFirmContext($firmA, fn () => AiRetrievalIndex::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$indexA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_ai_retrieval_indexes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => AiRetrievalIndex::factory()->forFirm($firmA)->create());
        $indexB = $this->runWithFirmContext($firmB, fn () => AiRetrievalIndex::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($indexB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_ai_retrieval_index(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('ai_retrieval_indexes')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'namespace_identifier' => 'firm-ns-'.(string) Str::uuid(),
                'status' => 'provisioned',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_ai_retrieval_indexes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $indexB = $this->runWithFirmContext(
            $firmB,
            fn () => AiRetrievalIndex::factory()->forFirm($firmB)->create(['namespace_identifier' => 'firm-ns-original-'.(string) Str::uuid()]),
        );

        $this->runWithFirmContext($firmA, function () use ($indexB) {
            DB::table('ai_retrieval_indexes')->where('id', $indexB->id)->update(['status' => 'disabled']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->find($indexB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('provisioned', $reReadAsFirmB->status->value);
    }

    public function test_firm_a_cannot_delete_firm_b_ai_retrieval_indexes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $indexB = $this->runWithFirmContext($firmB, fn () => AiRetrievalIndex::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($indexB) {
            DB::table('ai_retrieval_indexes')->where('id', $indexB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->find($indexB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B ai_retrieval_indexes.');
    }

    public function test_firm_a_cannot_insert_an_ai_retrieval_index_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('ai_retrieval_indexes')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'namespace_identifier' => 'firm-ns-'.(string) Str::uuid(),
                'status' => 'provisioned',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $indexA = $this->runWithFirmContext($firmA, fn () => AiRetrievalIndex::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($indexA, $firmB) {
            DB::table('ai_retrieval_indexes')->where('id', $indexA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => AiRetrievalIndex::factory()->forFirm($firm)->create());

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
     * Core proof: AiRetrievalIsolationService::provisionFor() (self-
     * wrapped in runWithFirmContext() by this checkpoint) still
     * functions under FORCE and produces a row immediately readable
     * under its own firm's context, with no context leaking afterward.
     */
    public function test_provision_for_still_functions_under_force(): void
    {
        $firm = Firm::factory()->create();

        $index = $this->isolationService->provisionFor($firm);

        $this->assertNotNull($index->id);
        $this->assertSame($firm->id, $index->firm_id);
        $this->assertNoDatabaseTenantContext();

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $firm,
            fn () => AiRetrievalIndex::withoutGlobalScopes()->find($index->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'provisionFor() must produce a row that is readable under its own firm context.');
    }

    /**
     * Core proof: AiRetrievalIsolationService::buildContext() (self-
     * wrapped in runWithFirmContext() by this checkpoint, and itself
     * nesting provisionFor()'s own wrap) still functions under FORCE.
     */
    public function test_build_context_still_functions_under_force(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        FirmUser::factory()->create([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'role' => FirmUserRole::FirmOwner,
        ]);
        $matter = Matter::factory()->forFirm($firm)->create();

        // FirmUserFactory/MatterFactory deliberately leave the
        // PostgreSQL session's database-only tenant context set to the
        // fixture firm afterward (their own established convention —
        // see FirmUserFactory::create()'s docblock). Clear it
        // explicitly so this assertion proves buildContext() itself
        // leaves no context behind, rather than merely restoring that
        // pre-existing fixture leftover.
        (new TenantContextService)->clearDatabaseTenantContext();

        $context = $this->isolationService->buildContext($firm, $user, [$matter]);

        $this->assertSame($firm->id, $context->firmId);
        $this->assertTrue($context->permitsMatter($matter->id));
        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since (unlike a 39A-3-style
     * FORCE-only migration) this migration introduced the policy
     * itself. up() is restored in a finally block so later tests are
     * unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path('database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'ai_retrieval_indexes'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'ai_retrieval_indexes'::regclass and polname = 'ai_retrieval_indexes_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only ai_retrieval_indexes — sampled:
     * a handful of the 53 previously-PREPARED tables, plus one
     * representative still-uncovered (MISSING_PREPARED_TABLES) table —
     * are bit-for-bit identical before and after a down()+up() round
     * trip.
     */
    public function test_migration_round_trip_affects_only_ai_retrieval_indexes(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'customer_success_health_scores', // the most recent prior checkpoint's table
            'deployment_configs', // another Wave 1 table, not yet touched by THIS checkpoint's migration
        ]);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path('database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php');
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the ai_retrieval_indexes migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the ai_retrieval_indexes migration round trip."
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
    }

    public function test_only_this_checkpoints_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowed = [
            'database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php',
            'app/Services/AiRetrievalIsolationService.php',
            'tests/Feature/Ai/Retrieval/AiRetrievalIsolationServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
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
