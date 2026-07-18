<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\Firm;
use App\Models\FirmAiSettings;
use App\Models\User;
use App\Services\AiUsageRecorderService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * FirmAiSettingsForceRlsActivationTest — Wave 1 (Section 39A-5
 * follow-on), the firm_ai_settings checkpoint of a three-table wave
 * (ai_retrieval_indexes, deployment_configs, firm_ai_settings — each
 * activated in its own separate commit). Proves the FORCE ROW LEVEL
 * SECURITY activation for firm_ai_settings (database/migrations/
 * 2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/insert/update/delete,
 * correct same-firm access, and that every previously-forced table
 * remains forced simultaneously.
 *
 * Unlike CustomerSuccessHealthScoresForceRlsActivationTest, this test
 * deliberately does NOT assert that firm_ai_settings appears in
 * RowLevelSecurityCoverageMappingService::preparedTables(), and does
 * NOT assert any exact "N prepared/missing tables" count — the shared
 * registry (app/Services/RowLevelSecurityCoverageMappingService.php)
 * is intentionally NOT touched by this commit. It will be updated once
 * by the coordinator in a single wave-integration pass after all three
 * of this wave's tables have individually landed. This test instead
 * proves the live database state directly via pg_class/pg_policy,
 * which is unaffected by the registry not yet being updated.
 */
class FirmAiSettingsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_firm_ai_settings_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_ai_settings'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_ai_settings_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_ai_settings'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_ai_settings must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'firm_ai_settings'::regclass and polname = 'firm_ai_settings_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The firm_ai_settings_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_firm_ai_settings(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmAiSettings::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, FirmAiSettings::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_firm_ai_settings(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_ai_settings')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deliberately does NOT rely on any factory context-hold override —
     * FirmAiSettingsFactory was intentionally NOT modified to
     * auto-establish tenant context by this checkpoint, so a bare
     * factory create() must fail exactly like the raw insert above.
     */
    public function test_bare_factory_create_without_context_fails_closed(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        FirmAiSettings::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_firm_ai_settings(): void
    {
        $firmA = Firm::factory()->create();
        $settingsA = $this->runWithFirmContext($firmA, fn () => FirmAiSettings::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmAiSettings::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$settingsA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_ai_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => FirmAiSettings::factory()->forFirm($firmA)->create());
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmAiSettings::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmAiSettings::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($settingsB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_firm_ai_settings_row(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('firm_ai_settings')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_firm_ai_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmAiSettings::factory()->forFirm($firmB)->create(['usage_markup_basis_points' => 500]));

        $this->runWithFirmContext($firmA, function () use ($settingsB) {
            DB::table('firm_ai_settings')->where('id', $settingsB->id)->update(['usage_markup_basis_points' => 1]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmAiSettings::withoutGlobalScopes()->find($settingsB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(500, $reReadAsFirmB->usage_markup_basis_points);
    }

    public function test_firm_a_cannot_delete_firm_b_firm_ai_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmAiSettings::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($settingsB) {
            DB::table('firm_ai_settings')->where('id', $settingsB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmAiSettings::withoutGlobalScopes()->find($settingsB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B firm_ai_settings.');
    }

    public function test_firm_a_cannot_insert_a_firm_ai_settings_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('firm_ai_settings')->insert([
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
        $settingsA = $this->runWithFirmContext($firmA, fn () => FirmAiSettings::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($settingsA, $firmB) {
            DB::table('firm_ai_settings')->where('id', $settingsA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmAiSettings::factory()->forFirm($firm)->create());

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
     * Core proof: AiUsageRecorderService::record() (its entire body
     * self-wrapped in one outer runWithFirmContext() call by this
     * checkpoint) still functions end-to-end under FORCE, with no
     * caller-supplied context beyond the $firm object itself — record()
     * establishes its own context internally and cleans it back up
     * afterward.
     *
     * Context is explicitly cleared right before calling record() (via
     * clearDatabaseTenantContext(), the same explicit-baseline pattern
     * used by test_bare_factory_create_without_context_fails_closed
     * elsewhere in this class) rather than relying on whatever ambient
     * state happens to follow makeAiEntitledFirm(). This is
     * deliberate, not a loosened assertion: makeAiEntitledFirm()
     * itself leaves app.current_firm_id HELD (not cleared) afterward,
     * by design — FirmSettingsFactory::create()'s own pre-existing
     * "context-hold" pattern (Section 39A-3L, Checkpoint 18) sets the
     * PostgreSQL session context via setDatabaseTenantContextForFirmId()
     * and never restores it, precisely so that later test code (e.g.
     * AiApprovalWorkflowServiceTest's own $firm->aiSettings-> reads)
     * can read tenant-owned relations without an extra wrap. Asserting
     * "no context is active" immediately after makeAiEntitledFirm() —
     * before record() is ever invoked — would fail regardless of
     * anything record() does, and is not the guarantee this checkpoint
     * needs to prove. The real guarantee under test is narrower and
     * more precise: record() does not need a caller-supplied context,
     * and whatever context it establishes internally is fully cleaned
     * up by the time it returns — proven here against an explicit,
     * controlled clean baseline instead of an accidental ambient one.
     */
    public function test_record_still_functions_end_to_end_under_force_with_no_caller_supplied_context(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling record()');

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            new AiPromptRequest(
                provider: AiProvider::OpenAi,
                model: 'fake-model-1',
                actionType: AiUsageActionType::Summarization,
                instructionText: 'Summarize the attached notes.',
                documentDerivedText: null,
                matterIds: [],
            ),
            new AiProviderResponse(outputText: 'Summary output.', tokensIn: 10, tokensOut: 5),
        );

        $this->assertNotNull($event->id);
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertNoDatabaseTenantContext('record() must restore context to the clean baseline it was called against');
    }

    /**
     * Same explicit-clean-baseline reasoning as the test above also
     * applies to the high-risk/approval-submit branch (AiApprovalWorkflowService::submit(),
     * only reached for high-risk action types), which record() also
     * invokes from inside its own single outer wrap.
     */
    public function test_record_still_functions_end_to_end_under_force_for_a_high_risk_action(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling record()');

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            new AiPromptRequest(
                provider: AiProvider::OpenAi,
                model: 'fake-model-1',
                actionType: AiUsageActionType::DemandLetter,
                instructionText: 'Draft a demand letter.',
                documentDerivedText: null,
                matterIds: [],
            ),
            new AiProviderResponse(outputText: 'Demand letter draft.', tokensIn: 20, tokensOut: 30),
        );

        $this->assertTrue($event->approval_required);
        $this->assertNoDatabaseTenantContext('record() must restore context to the clean baseline it was called against, including the approval-submit branch');
    }

    /**
     * Core proof: cost calculation (AiUsageRecorderService::computeCostCents(),
     * which reads firm_ai_settings.usage_markup_basis_points via
     * Firm::aiSettings()) still applies the configured markup correctly
     * under FORCE ROW LEVEL SECURITY. See the explicit-clean-baseline
     * note on test_record_still_functions_end_to_end_under_force_with_no_caller_supplied_context
     * above for why context is cleared explicitly before calling
     * record() here too.
     */
    public function test_cost_calculation_with_markup_applies_correctly_under_force(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $this->runWithFirmContext($firm, fn () => $firm->aiSettings->update(['usage_markup_basis_points' => 1000]));
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling record()');

        $event = app(AiUsageRecorderService::class)->record(
            $firm,
            $user,
            new AiPromptRequest(
                provider: AiProvider::OpenAi,
                model: 'fake-model-1',
                actionType: AiUsageActionType::Summarization,
                instructionText: 'Summarize the attached notes.',
                documentDerivedText: null,
                matterIds: [],
            ),
            new AiProviderResponse(outputText: 'Summary output.', tokensIn: 1000, tokensOut: 1000),
        );

        // base = round((2000/100) * 1) = 20; markup 10% => +2 => 22.
        $this->assertSame(22, $event->cost_cents);
        $this->assertNoDatabaseTenantContext('record() must restore context to the clean baseline it was called against');
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
        $migration = require base_path('database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_ai_settings'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'firm_ai_settings'::regclass and polname = 'firm_ai_settings_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only firm_ai_settings — every other
     * table's relrowsecurity/relforcerowsecurity state (sampled: every
     * PREPARED table, plus a representative still-uncovered table) is
     * bit-for-bit identical before and after a down()+up() round trip.
     */
    public function test_migration_round_trip_affects_only_firm_ai_settings(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'accounting_export_batches'; // a representative still-uncovered table

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path('database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php');
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the firm_ai_settings migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the firm_ai_settings migration round trip."
            );
        }
    }

    /**
     * Every other still-uncovered tenant table (i.e. every entry of
     * missingPreparedTables() other than firm_ai_settings itself, which
     * this checkpoint activates ahead of the shared registry being
     * updated — see this class's own docblock) must remain untouched.
     */
    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if ($table === 'firm_ai_settings') {
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
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once all three of this wave\'s tables have landed.'
        );
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
            'database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php',
            'app/Services/AiUsageRecorderService.php',
            'tests/Feature/Ai/Concerns/SetsUpAiEntitledFirm.php',
            'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
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
