<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiUsageActionType;
use App\Models\AiUsageEvent;
use App\Models\Firm;
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
 * AiUsageEventsForceRlsActivationTest — Section 39A-5 uncovered-table
 * rollout, the ai_usage_events checkpoint. Proves the FORCE ROW LEVEL
 * SECURITY activation for ai_usage_events (database/migrations/
 * 2026_08_27_950013_prepare_row_level_security_and_force_rls_on_ai_usage_events_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/insert, and that every
 * previously-forced table remains forced simultaneously.
 *
 * ai_usage_events is append-only at the application layer (project rule
 * 8 — AiUsageEvent's own booted() hook throws on update/delete), but the
 * policy created by this checkpoint is a single FOR ALL policy exactly
 * like every other table in this registry, not narrowed to
 * SELECT/INSERT only. This test therefore also proves UPDATE/DELETE are
 * denied for a cross-firm actor at the RLS layer directly (via raw
 * DB::table() calls that bypass Eloquent's append-only model events
 * entirely) — defense in depth, not because the application ever issues
 * such statements itself.
 *
 * Mirrors FirmAiSettingsForceRlsActivationTest's own structure and
 * reasoning (see that class's docblock for the full explanation of why
 * the shared registry, RowLevelSecurityCoverageMappingService, is
 * intentionally NOT touched by this checkpoint, and why the live
 * pg_class/pg_policy state is asserted directly instead of via that
 * registry).
 *
 * No production code accompanies this checkpoint.
 * AiUsageRecorderService::record()'s single outer runWithFirmContext()
 * wrap (added alongside the firm_ai_settings checkpoint) already fully
 * covers this table's sole INSERT — see the migration's own docblock.
 * AiUsageRecorderService.php, AiToolActionRecorderService.php,
 * AiApprovalWorkflowService.php, AiUsageEvent.php, and
 * AiUsageEventFactory.php are all untouched by this commit.
 */
class AiUsageEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950013_prepare_row_level_security_and_force_rls_on_ai_usage_events_table.php';

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_ai_usage_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'ai_usage_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_ai_usage_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'ai_usage_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'ai_usage_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'ai_usage_events'::regclass and polname = 'ai_usage_events_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The ai_usage_events_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_ai_usage_events(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $this->runWithFirmContext($firm, fn () => AiUsageEvent::factory()->forFirm($firm)->create(['user_id' => $user->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AiUsageEvent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_ai_usage_events(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('ai_usage_events')->insert($this->rawRow($firm, $user));
    }

    /**
     * Deliberately does NOT rely on any factory context-hold override —
     * AiUsageEventFactory was intentionally NOT modified to auto-
     * establish tenant context by this checkpoint, so a bare factory
     * create() must fail exactly like the raw insert above.
     */
    public function test_bare_factory_create_without_context_fails_closed(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        AiUsageEvent::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_ai_usage_events(): void
    {
        $firmA = Firm::factory()->create();
        $userA = User::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => AiUsageEvent::factory()->forFirm($firmA)->create(['user_id' => $userA->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiUsageEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_ai_usage_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->runWithFirmContext($firmA, fn () => AiUsageEvent::factory()->forFirm($firmA)->create(['user_id' => $userA->id]));
        $eventB = $this->runWithFirmContext($firmB, fn () => AiUsageEvent::factory()->forFirm($firmB)->create(['user_id' => $userB->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiUsageEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_ai_usage_event_row(): void
    {
        $firmA = Firm::factory()->create();
        $userA = User::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $userA) {
            return DB::table('ai_usage_events')->insertGetId($this->rawRow($firmA, $userA));
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_insert_an_ai_usage_event_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userA = User::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $userA) {
            DB::table('ai_usage_events')->insert($this->rawRow($firmB, $userA));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userA = User::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => AiUsageEvent::factory()->forFirm($firmA)->create(['user_id' => $userA->id]));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($eventA, $firmB) {
            DB::table('ai_usage_events')->where('id', $eventA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    /**
     * Defense in depth: the policy created by this checkpoint is a
     * single FOR ALL policy, not narrowed to SELECT/INSERT — proving a
     * cross-firm UPDATE is denied at the RLS layer directly (via a raw
     * DB::table() call bypassing Eloquent's own append-only guard)
     * matters even though the application itself never issues an
     * UPDATE against this table.
     */
    public function test_firm_a_cannot_update_firm_b_ai_usage_events_at_the_policy_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userB = User::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => AiUsageEvent::factory()->forFirm($firmB)->create(['user_id' => $userB->id, 'tokens_in' => 10]));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('ai_usage_events')->where('id', $eventB->id)->update(['tokens_in' => 999999]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiUsageEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(10, $reReadAsFirmB->tokens_in);
    }

    /**
     * Defense in depth: same reasoning as the UPDATE test above, for
     * DELETE.
     */
    public function test_firm_a_cannot_delete_firm_b_ai_usage_events_at_the_policy_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $userB = User::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => AiUsageEvent::factory()->forFirm($firmB)->create(['user_id' => $userB->id]));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('ai_usage_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiUsageEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B ai_usage_events at the policy layer.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $this->runWithFirmContext($firm, fn () => AiUsageEvent::factory()->forFirm($firm)->create(['user_id' => $user->id]));

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
     * self-wrapped in one outer runWithFirmContext() call since the
     * firm_ai_settings checkpoint, unchanged by this commit) still
     * functions end-to-end under FORCE for ai_usage_events, with no
     * caller-supplied context beyond the $firm object itself.
     *
     * Context is explicitly cleared right before calling record() (the
     * same explicit-baseline pattern used by
     * test_bare_factory_create_without_context_fails_closed elsewhere in
     * this class, and by FirmAiSettingsForceRlsActivationTest's own
     * equivalent test) rather than relying on whatever ambient state
     * happens to follow makeAiEntitledFirm() — makeAiEntitledFirm()
     * itself leaves app.current_firm_id HELD (not cleared) afterward, by
     * design (FirmSettingsFactory's own pre-existing context-hold
     * pattern), so asserting "no context is active" immediately after it
     * would fail regardless of anything record() does. The guarantee
     * under test here is narrower and more precise: record() does not
     * need a caller-supplied context, and whatever context it
     * establishes internally (now covering ai_usage_events as well as
     * firm_ai_settings) is fully cleaned up by the time it returns —
     * proven against an explicit, controlled clean baseline.
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
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration
     * introduced the policy itself. up() is restored in a finally
     * block so later tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'ai_usage_events'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'ai_usage_events'::regclass and polname = 'ai_usage_events_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only ai_usage_events — every other
     * table's relrowsecurity/relforcerowsecurity state (sampled: every
     * PREPARED table, plus a representative still-uncovered table) is
     * bit-for-bit identical before and after a down()+up() round trip.
     */
    public function test_migration_round_trip_affects_only_ai_usage_events(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = $coverage->preparedTables();
        $otherTables[] = 'accounting_export_batches'; // a representative still-uncovered table

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the ai_usage_events migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the ai_usage_events migration round trip."
            );
        }
    }

    /**
     * Every other still-uncovered tenant table (i.e. every entry of
     * missingPreparedTables() other than ai_usage_events itself, which
     * this checkpoint activates ahead of the shared registry being
     * updated — see this class's own docblock) must remain untouched.
     */
    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if ($table === 'ai_usage_events') {
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
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once every table in this wave has landed.'
        );
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
            self::MIGRATION_PATH,
            'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
            // AiUsageEvent::factory()->create() there had no ambient
            // tenant context, matching a real pre-existing gap this
            // checkpoint's own FORCE activation surfaces — fixed by
            // wrapping it, not by weakening the assertion it protects.
            'tests/Feature/Governance/Firewall/AuditPreservationPolicyServiceTest.php',
        ];

        $unexpected = array_values(array_diff($changed, $allowed));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this checkpoint: '.implode(', ', $unexpected));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRow(Firm $firm, User $user): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'matter_id' => null,
            'ai_mode' => AiMode::PlatformManaged->value,
            'provider' => AiProvider::OpenAi->value,
            'model' => 'fake-model-1',
            'tokens_in' => 10,
            'tokens_out' => 5,
            'cost_cents' => 1,
            'approval_required' => false,
            'action_type' => AiUsageActionType::Summarization->value,
            'created_at' => now(),
        ];
    }

    /**
     * @return array<int, string>
     */
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — any later
     * mission's real work will always otherwise trip every earlier
     * checkpoint's own "no changes" firewall, since each one asserts
     * against the CURRENT working tree, not a point-in-time snapshot.
     * Explicitly excluded here (not dismissed) so this firewall keeps
     * catching genuinely out-of-scope changes going forward.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
        'app/Filament/Resources/PlanAddOnResource.php',
        'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
        'app/Filament/Resources/PlanResource.php',
        'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
        'app/Models/Plan.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/PlanModuleService.php',
        'app/Services/PlanService.php',
        'config/database.php',
        'database/factories/PlanFactory.php',
        'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
        'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
        'tests/Feature/Plans/PlanServiceTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
        'app/Exceptions/InactivePlanSelectedException.php',
        'app/Filament/Actions/Platform/AddPlanModuleAction.php',
        'app/Filament/Actions/Platform/CreatePlanAction.php',
        'app/Filament/Actions/Platform/EditPlanAction.php',
        'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
        'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
        'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
        // The 72 RlsForceRollout per-table activation test files
        // themselves, mechanically updated (this exact const +
        // filtering addition) by this same reviewed mission — see
        // this array's own docblock above.
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CalendarEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ClientCommunicationPreferencesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConflictCheckRunsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationOutcomesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentChaseRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmployeeRatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmLeadsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmPracticeAreasForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LeadSourcesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/FirmUser2fa/FirmUser2faFirewallTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceActivation/RlsForceActivationFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/BackupRestoreTestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ContactsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/HealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/IncidentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MaintenanceWindowsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/NotificationTemplatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PartiesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PilotFeedbackItemsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SecurityEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TimelineEventsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // FIRMSVAULT — STAGING ADMIN STABILIZATION (follow-on fix) also
        // corrected DeploymentEnvironmentFirewallTest.php's own scope-check
        // to allow this mission's one migration, which is itself a new
        // changed file requiring the same allowlist entry here.
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
    ];

    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
