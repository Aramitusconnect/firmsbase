<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\DeploymentConfig;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TrustIoltaDisableAcknowledgmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DeploymentConfigsForceRlsActivationTest — Wave 1 (39A-5-successor RLS
 * activation rollout), the deployment_configs checkpoint. Proves the
 * FORCE ROW LEVEL SECURITY activation added by
 * database/migrations/2026_08_27_950002_prepare_row_level_security_and_force_rls_on_deployment_configs_table.php
 * is permanently active for deployment_configs and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation on
 * read/insert/update/delete, correct same-firm access, a bare
 * DeploymentConfig::factory()->create() failing closed, migration
 * up/down/round-trip correctness, and that no other table's FORCE
 * state changed.
 *
 * Wave 1 activates THREE tables (ai_retrieval_indexes,
 * deployment_configs, firm_ai_settings) via three separate,
 * individually-committed migrations/checkpoints. Unlike every prior
 * single-table checkpoint's own activation test,
 * RowLevelSecurityCoverageMappingService is deliberately NOT updated
 * by this checkpoint — the registry move (all three Wave 1 tables from
 * MISSING_PREPARED_TABLES to PREPARED_TABLES) happens in one shared
 * follow-up wave-integration commit once all three land. Consequently
 * this test deliberately does NOT assert that deployment_configs
 * appears in $coverage->preparedTables() (it does not, yet, at the
 * point this test runs standalone) and does NOT assert any exact
 * "N prepared tables" count — both would be true only after the
 * wave-integration commit. What IS asserted here is the table's real,
 * live PostgreSQL state (pg_class/pg_policy), which is already
 * permanently forced regardless of the registry's bookkeeping lag.
 */
class DeploymentConfigsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_27_950002_prepare_row_level_security_and_force_rls_on_deployment_configs_table.php';

    public function test_all_fifty_three_existing_prepared_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_deployment_configs_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'deployment_configs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_deployment_configs_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deployment_configs'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'deployment_configs must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'deployment_configs'::regclass and polname = 'deployment_configs_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The deployment_configs_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_deployment_configs(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => DeploymentConfig::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DeploymentConfig::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_deployment_configs(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('deployment_configs')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deliberately does NOT use a context-hold factory override —
     * DeploymentConfigFactory was intentionally left unmodified by
     * this checkpoint (bare factory writes must fail closed exactly
     * like any other unscoped write, not silently auto-establish
     * context).
     */
    public function test_bare_factory_create_without_context_fails_closed(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DeploymentConfig::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_deployment_config(): void
    {
        $firmA = Firm::factory()->create();
        $configA = $this->runWithFirmContext($firmA, fn () => DeploymentConfig::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DeploymentConfig::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$configA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_deployment_config(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => DeploymentConfig::factory()->forFirm($firmA)->create());
        $configB = $this->runWithFirmContext($firmB, fn () => DeploymentConfig::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DeploymentConfig::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($configB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_deployment_config(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('deployment_configs')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_deployment_config(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $configB = $this->runWithFirmContext($firmB, fn () => DeploymentConfig::factory()->forFirm($firmB)->create(['custom_domain' => 'firmb.example.com']));

        $this->runWithFirmContext($firmA, function () use ($configB) {
            DB::table('deployment_configs')->where('id', $configB->id)->update(['custom_domain' => 'hijacked.example.com']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DeploymentConfig::withoutGlobalScopes()->find($configB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('firmb.example.com', $reReadAsFirmB->custom_domain);
    }

    public function test_firm_a_cannot_delete_firm_b_deployment_config(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $configB = $this->runWithFirmContext($firmB, fn () => DeploymentConfig::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($configB) {
            DB::table('deployment_configs')->where('id', $configB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DeploymentConfig::withoutGlobalScopes()->find($configB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B deployment_configs.');
    }

    public function test_firm_a_cannot_insert_a_deployment_config_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('deployment_configs')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $configA = $this->runWithFirmContext($firmA, fn () => DeploymentConfig::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($configA, $firmB) {
            DB::table('deployment_configs')->where('id', $configA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => DeploymentConfig::factory()->forFirm($firm)->create());

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
     * Core proof: TrustIoltaDisableAcknowledgmentService::recordFirmAcknowledgment()
     * (its update()+fresh() self-wrapped in runWithFirmContext(),
     * derived from the ROW'S OWN firm_id, by this checkpoint) still
     * functions under FORCE and produces a row immediately readable
     * under its own firm's context, with no lingering context
     * afterward.
     */
    public function test_record_firm_acknowledgment_still_functions_under_force_using_the_configs_own_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $config = $this->runWithFirmContext($firm, fn () => DeploymentConfig::factory()->forFirm($firm)->create());
        $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

        // FirmUserFactory::create() deliberately leaves the PostgreSQL
        // session setting active afterward (its own documented,
        // pre-existing convention, unrelated to this checkpoint) — a
        // clean baseline is established here so the assertion below
        // proves THIS service's own context wrapping/restoration,
        // rather than incidentally passing because of a fixture's
        // leftover state.
        (new TenantContextService)->clearDatabaseTenantContext();

        $service = app(TrustIoltaDisableAcknowledgmentService::class);
        $acknowledged = $service->recordFirmAcknowledgment(
            $config,
            $firmUser,
            'We acknowledge operating-only posture.',
            'v1',
        );

        $this->assertTrue($acknowledged->hasFirmAcknowledgedTrustIoltaDisabled());
        $this->assertSame($firm->id, $acknowledged->firm_id);

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $firm,
            fn () => DeploymentConfig::withoutGlobalScopes()->find($config->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'recordFirmAcknowledgment() must produce a row readable under its own firm context.');
        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration
     * introduced the policy itself. up() is restored in a finally
     * block so later tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'deployment_configs'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'deployment_configs'::regclass and polname = 'deployment_configs_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only deployment_configs — every
     * other table's relrowsecurity/relforcerowsecurity state (sampled:
     * every existing PREPARED_TABLES table, plus a representative
     * still-uncovered table) is bit-for-bit identical before and after
     * a down()+up() round trip.
     */
    public function test_migration_round_trip_affects_only_deployment_configs(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'accounting_export_batches'; // a representative still-uncovered table

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the deployment_configs migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the deployment_configs migration round trip."
            );
        }
    }

    /**
     * Every still-uncovered tenant table OTHER than deployment_configs
     * must remain untouched by this checkpoint. deployment_configs
     * itself is deliberately excluded from this loop — the registry
     * still lists it under missingPreparedTables() (the wave-
     * integration commit has not yet moved it), but its LIVE database
     * state is already, and deliberately, forced by this checkpoint.
     */
    public function test_no_other_still_uncovered_tenant_table_was_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if ($table === 'deployment_configs') {
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

    public function test_rls_prepared_not_enforced_remains_tracked(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        // This checkpoint deliberately does NOT move deployment_configs
        // from MISSING_PREPARED_TABLES to PREPARED_TABLES — that is a
        // shared registry file the coordinator updates once in a
        // single wave-integration commit after all three Wave 1 tables
        // (ai_retrieval_indexes, deployment_configs, firm_ai_settings)
        // have individually landed.
        $changed = $this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php');

        $this->assertEmpty($changed, 'RowLevelSecurityCoverageMappingService.php must not be modified by this individual checkpoint — it is updated once in the shared wave-integration commit.');
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
            self::MIGRATION,
            'app/Services/TrustIoltaDisableAcknowledgmentService.php',
            'tests/Feature/Deployment/Configs/DeploymentConfigTest.php',
            'tests/Feature/Deployment/TrustAcknowledgment/TrustIoltaDisableAcknowledgmentServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
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
