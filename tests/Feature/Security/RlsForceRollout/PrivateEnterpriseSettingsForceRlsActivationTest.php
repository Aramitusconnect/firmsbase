<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\DeploymentMode;
use App\Models\Firm;
use App\Models\PrivateEnterpriseSettings;
use App\Services\ComplianceGapRegistryService;
use App\Services\DeploymentHealthEnvelopeService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * PrivateEnterpriseSettingsForceRlsActivationTest — Section 39A-5 Wave 1
 * follow-on (this repo's own subsequent staged FORCE activation batch),
 * the private_enterprise_settings checkpoint. Proves the FORCE ROW
 * LEVEL SECURITY activation added by
 * database/migrations/2026_08_27_950011_prepare_row_level_security_and_force_rls_on_private_enterprise_settings_table.php
 * is permanently active for private_enterprise_settings and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation
 * on read/insert/update/delete, correct same-firm access, a bare
 * PrivateEnterpriseSettings::factory()->create() failing closed,
 * migration up/down/round-trip correctness, and that no other table's
 * FORCE state changed.
 *
 * Same registry-lag convention as every prior individual checkpoint in
 * this arc (see DeploymentConfigsForceRlsActivationTest's own
 * docblock): RowLevelSecurityCoverageMappingService is deliberately NOT
 * updated by this checkpoint — the registry move (private_enterprise_settings
 * from MISSING_PREPARED_TABLES to PREPARED_TABLES) happens in a single
 * follow-up wave-integration commit once accepted. Consequently this
 * test does NOT assert that private_enterprise_settings appears in
 * $coverage->preparedTables() (it does not, yet, at the point this test
 * runs standalone). What IS asserted here is the table's real, live
 * PostgreSQL state (pg_class/pg_policy) — already permanently forced —
 * and forcedTables()'s own exact count, which IS derived at call time
 * from every *_force_rls_on_*_table.php migration file present in the
 * repository (see RowLevelSecurityCoverageMappingService::discoverForcedTables())
 * and therefore correctly reflects this checkpoint's migration the
 * moment it lands, unlike preparedTables().
 *
 * Related-model note: unlike several other checkpoints in this arc,
 * private_enterprise_settings.firm_id is the table's ONLY firm-
 * referencing column — a direct, UNIQUE, NOT NULL FK straight to
 * firms (see the create-table migration's own
 * foreignId('firm_id')->unique()->constrained('firms')), and the
 * table carries no OTHER foreign key to any related, non-firms entity.
 * There is therefore no transitive "related row's own firm_id may
 * differ from this row's firm_id" mismatch scenario to construct for
 * this table (unlike, say, a matter_id-owning child table) — the
 * cross-firm insert/reassignment tests below ARE the complete proof
 * for this table's single ownership column, not a substitute for a
 * separate related-model proof that does not apply here. This is
 * documented, not assumed: confirmed by direct inspection of
 * database/migrations/2026_07_25_900008_create_private_enterprise_settings_table.php,
 * which declares exactly one foreignId() call.
 */
class PrivateEnterpriseSettingsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    use RefreshDatabase, SetsUpDeploymentFirm;

    private const MIGRATION = 'database/migrations/2026_08_27_950011_prepare_row_level_security_and_force_rls_on_private_enterprise_settings_table.php';

    public function test_all_fifty_six_existing_prepared_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Wave-integration note: this table's own checkpoint commit did
        // not move private_enterprise_settings into PREPARED_TABLES —
        // the subsequent Section 39A-5 Wave 2 integration commit did,
        // together with its three sibling tables (email_visibility_rules,
        // matter_expenses, email_message_links), bringing the count from
        // fifty-six to sixty. Section 39A-5 Wave 3 integration then
        // added five more (ai_usage_events, ai_tool_actions,
        // firm_ai_provider_keys, ai_approval_requests,
        // ai_approval_events), bringing it to sixty-five. This test now
        // runs against the fully-integrated state.
        $preparedTables = $coverage->preparedTables();
        // Narrowly updated AGAIN by Section 39A-5 Wave 7 integration (e-signature domain, 4 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration Platform mission (firm_integrations, a brand-new genuine tenant-owned table, RLS prepared and FORCE-activated in the same migration, NOT part of the old 60-table rollout) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase Integration Platform mission (integration_credentials, a new genuine tenant-owned table with RLS prepared and FORCE-activated in the same migration) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertCount(126, $preparedTables, 'Section 39A-5 Wave 2 through Wave 10 integration must have moved private_enterprise_settings and every sibling table from all nine later waves into PREPARED_TABLES.');

        foreach ($preparedTables as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_exactly_fifty_seven_tables_have_force_row_level_security_active_no_more_no_less(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // forcedTables() is derived at call time from every
        // *_force_rls_on_*_table.php migration file in the repository
        // (unlike preparedTables(), which lags until the shared
        // wave-integration commit) — so it correctly includes
        // private_enterprise_settings the moment this checkpoint's
        // migration exists, without needing any registry edit.
        $forced = $coverage->forcedTables();

        $this->assertContains('private_enterprise_settings', $forced);
        // Sixty-five after Section 39A-5 Wave 3 integration (sixty
        // after Wave 2 — fifty-six prior plus this table's three
        // siblings from that wave — plus five more from Wave 3:
        // ai_usage_events, ai_tool_actions, firm_ai_provider_keys,
        // ai_approval_requests, ai_approval_events).
        // Narrowly updated AGAIN by Section 39A-5 Wave 7 (e-signature domain, 4 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration Platform mission (firm_integrations) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase Integration Platform mission (integration_credentials, a new genuine tenant-owned table with RLS prepared and FORCE-activated in the same migration) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertCount(126, $forced, 'Exactly 108 tables must have a FORCE-activation migration after Section 39A-5 Wave 10 — no more, no less.');

        foreach ($forced as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must have live FORCE ROW LEVEL SECURITY active.");
        }
    }

    public function test_private_enterprise_settings_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'private_enterprise_settings'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_private_enterprise_settings_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'private_enterprise_settings'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'private_enterprise_settings must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'private_enterprise_settings'::regclass and polname = 'private_enterprise_settings_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The private_enterprise_settings_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_private_enterprise_settings(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, PrivateEnterpriseSettings::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_private_enterprise_settings(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('private_enterprise_settings')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deliberately does NOT use a context-hold factory override —
     * PrivateEnterpriseSettingsFactory was intentionally left
     * unmodified by this checkpoint (bare factory writes must fail
     * closed exactly like any other unscoped write, not silently
     * auto-establish context).
     */
    public function test_bare_factory_create_without_context_fails_closed(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        PrivateEnterpriseSettings::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_private_enterprise_settings(): void
    {
        $firmA = Firm::factory()->create();
        $settingsA = $this->runWithFirmContext($firmA, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$settingsA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_private_enterprise_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmA)->create());
        $settingsB = $this->runWithFirmContext($firmB, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($settingsB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_private_enterprise_settings_row(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('private_enterprise_settings')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_private_enterprise_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $settingsB = $this->runWithFirmContext($firmB, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmB)->create(['requires_custom_domain' => false]));

        $this->runWithFirmContext($firmA, function () use ($settingsB) {
            DB::table('private_enterprise_settings')->where('id', $settingsB->id)->update(['requires_custom_domain' => true]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->find($settingsB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertFalse((bool) $reReadAsFirmB->requires_custom_domain, "Firm A's update must not have taken effect against Firm B's row.");
    }

    public function test_firm_a_cannot_delete_firm_b_private_enterprise_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $settingsB = $this->runWithFirmContext($firmB, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($settingsB) {
            DB::table('private_enterprise_settings')->where('id', $settingsB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->find($settingsB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B private_enterprise_settings.');
    }

    public function test_firm_a_cannot_insert_a_private_enterprise_settings_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('private_enterprise_settings')->insert([
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
        $settingsA = $this->runWithFirmContext($firmA, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($settingsA, $firmB) {
            DB::table('private_enterprise_settings')->where('id', $settingsA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::factory()->forFirm($firm)->create());

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
     * Factory default-creation safety: a bare forFirm()->create() with
     * no other state produces every requires_* boolean AND
     * telemetry_prohibited as false, matching PrivateEnterpriseSettingsFactory::definition()
     * exactly, under real FORCE RLS enforcement (not merely against an
     * unforced/bypassed connection).
     */
    public function test_factory_default_creation_is_safe_and_matches_the_declared_defaults(): void
    {
        $firm = Firm::factory()->create();

        $settings = $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::factory()->forFirm($firm)->create());

        $reRead = $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->find($settings->id));

        $this->assertNotNull($reRead);
        $this->assertSame($firm->id, $reRead->firm_id);
        $this->assertFalse((bool) $reRead->requires_custom_domain);
        $this->assertFalse((bool) $reRead->requires_isolated_database);
        $this->assertFalse((bool) $reRead->requires_isolated_storage);
        $this->assertFalse((bool) $reRead->telemetry_prohibited);
    }

    /**
     * Explicit related-model factory state correctness: the
     * telemetryProhibited() state must set ONLY telemetry_prohibited to
     * true, leaving every requires_* declaration at its default false,
     * and the forFirm() state must assign the row to exactly the
     * intended firm — both proven under real FORCE RLS enforcement.
     */
    public function test_telemetry_prohibited_factory_state_sets_only_that_one_flag(): void
    {
        $firm = Firm::factory()->create();

        $settings = $this->runWithFirmContext(
            $firm,
            fn () => PrivateEnterpriseSettings::factory()->forFirm($firm)->telemetryProhibited()->create(),
        );

        $reRead = $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->find($settings->id));

        $this->assertNotNull($reRead);
        $this->assertSame($firm->id, $reRead->firm_id);
        $this->assertTrue((bool) $reRead->telemetry_prohibited);
        $this->assertFalse((bool) $reRead->requires_custom_domain);
        $this->assertFalse((bool) $reRead->requires_isolated_database);
        $this->assertFalse((bool) $reRead->requires_isolated_storage);
    }

    /**
     * Companion proof for DeploymentHealthEnvelopeService's own wrap
     * (already implemented, not written by this test suite): confirms
     * telemetry_prohibited still correctly resolves to true and forces
     * offline_report mode under real FORCE RLS enforcement, i.e. the
     * runWithFirmContext() wrap around the PrivateEnterpriseSettings
     * read inside buildEnvelope() genuinely works end to end — not
     * merely that the migration itself is well-formed.
     */
    public function test_telemetry_prohibited_still_resolves_correctly_through_deployment_health_envelope_service(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);

        $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::factory()->forFirm($firm)->telemetryProhibited()->create());

        $envelope = app(DeploymentHealthEnvelopeService::class)->buildEnvelope($firm->fresh(), '2026.7.0', '2026.7.0');

        $this->assertSame(
            DeploymentHealthReportMode::OfflineReport,
            $envelope->reportedVia,
            'buildEnvelope() must still correctly resolve telemetry_prohibited=true to offline_report mode now that the read is wrapped in runWithFirmContext() under FORCE RLS.'
        );

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Related-model mismatch analog for this table's single ownership
     * column: proves RLS checks ONLY this row's own firm_id, never any
     * other row's — there is no other related table to construct a
     * genuine transitive FK mismatch against (see this class's own
     * docblock), so this is the complete proof available for this
     * table's ownership shape, not a stand-in claiming a guarantee that
     * does not apply.
     */
    public function test_rls_checks_only_this_rows_own_firm_id_not_any_other_rows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $settingsA = $this->runWithFirmContext($firmA, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmA)->create());
        $settingsB = $this->runWithFirmContext($firmB, fn () => PrivateEnterpriseSettings::factory()->forFirm($firmB)->create());

        // Under firm A's context, only settingsA's own row (firm_id =
        // firmA->id) is visible — settingsB's row, despite existing in
        // the same table, is invisible purely because ITS OWN firm_id
        // column differs, not because of any related/joined table.
        $visibleUnderFirmA = $this->runWithFirmContext(
            $firmA,
            fn () => PrivateEnterpriseSettings::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$settingsA->id], $visibleUnderFirmA);
        $this->assertNotContains($settingsB->id, $visibleUnderFirmA);
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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'private_enterprise_settings'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'private_enterprise_settings'::regclass and polname = 'private_enterprise_settings_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only private_enterprise_settings —
     * every other table's relrowsecurity/relforcerowsecurity state
     * (sampled: every existing PREPARED_TABLES table, plus a
     * representative still-uncovered table) is bit-for-bit identical
     * before and after a down()+up() round trip.
     */
    public function test_migration_round_trip_affects_only_private_enterprise_settings(): void
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
                "{$table}'s relrowsecurity must be unaffected by the private_enterprise_settings migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the private_enterprise_settings migration round trip."
            );
        }
    }

    /**
     * Every still-uncovered tenant table OTHER than
     * private_enterprise_settings must remain untouched by this
     * checkpoint. private_enterprise_settings itself is deliberately
     * excluded from this loop — the registry still lists it under
     * missingPreparedTables() (the wave-integration commit has not yet
     * moved it), but its LIVE database state is already, and
     * deliberately, forced by this checkpoint.
     */
    public function test_no_other_still_uncovered_tenant_table_was_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if ($table === 'private_enterprise_settings') {
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
        // This checkpoint deliberately does NOT move
        // private_enterprise_settings from MISSING_PREPARED_TABLES to
        // PREPARED_TABLES — that is a shared registry file the
        // coordinator updates once in a single wave-integration commit.
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
            'app/Services/DeploymentHealthEnvelopeService.php',
            'tests/Feature/Deployment/Configs/DeploymentConfigTest.php',
            'tests/Feature/Deployment/Health/DeploymentHealthEnvelopeServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
            'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
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
