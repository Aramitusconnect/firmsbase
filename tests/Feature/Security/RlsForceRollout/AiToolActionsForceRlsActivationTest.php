<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AiProvider;
use App\Enums\AiToolActionStatus;
use App\Enums\AiUsageActionType;
use App\Models\AiToolAction;
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
 * AiToolActionsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for ai_tool_actions (database/migrations/
 * 2026_08_27_950014_prepare_row_level_security_and_force_rls_on_
 * ai_tool_actions_table.php) is permanently active and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation
 * on read/update/delete, correct same-firm access, insert and
 * ownership-reassignment protection under the explicit WITH CHECK
 * clause, that every previously-prepared table remains forced
 * simultaneously, and that the shared writer path
 * (AiToolActionRecorderService::recordFromResponse(), called only from
 * AiUsageRecorderService::record()) still functions correctly end-to-end
 * under FORCE with zero production service code changes.
 *
 * This is an independent checkpoint (matching the ai_retrieval_indexes/
 * deployment_configs/firm_ai_settings/matter_expenses 39A-5 shape):
 * ai_tool_actions still appears in RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() at the point this test runs — the registry is
 * updated once by the coordinator in a later, separate wave-integration
 * commit, not by this checkpoint. Consequently this test does NOT
 * assert ai_tool_actions appears in $coverage->preparedTables(), does
 * NOT assert any exact "N prepared tables" count, and does NOT assert
 * it is no longer reported as missing. What IS asserted directly
 * against pg_class/pg_policy (the live database state this migration
 * actually produced) is the row security/policy reality for
 * ai_tool_actions itself, independent of the registry.
 *
 * Zero production service code change: AiToolActionRecorderService::
 * recordFromResponse()'s only call site is already inside
 * AiUsageRecorderService::record()'s existing single outer
 * runWithFirmContext() wrap — that method's own docblock already
 * documents this wrap is comprehensive for the ai_tool_actions writes
 * performed inside it and that a later wave activating FORCE ROW LEVEL
 * SECURITY on this table must NOT re-wrap the method again. Neither
 * app/Services/AiToolActionRecorderService.php nor
 * app/Services/AiUsageRecorderService.php is touched by this checkpoint
 * (both files are SHARED writers for sibling tables being implemented
 * in parallel) — see the explicit collision-guard assertions below.
 *
 * Known, stated (not hidden) residual gap: this migration/test batch
 * does NOT close the transitive cross-firm foreign-key gap between
 * ai_tool_actions.firm_id and the real firm_id of the row matter_id/
 * ai_usage_event_id point at — see the migration's own docblock. RLS on
 * ai_tool_actions alone cannot see into matters/ai_usage_events to
 * cross-check this. AiToolActionFactory's definition()/forFirm() tie
 * ai_usage_event_id to the same authoritative firm by default so the
 * factory itself never manufactures the invalid shape (see the
 * regression tests below), but this is a factory-level mitigation, not
 * a database-level guarantee.
 *
 * Regression fixed as part of this checkpoint: two assertions in
 * tests/Feature/Ai/PromptInjection/PromptInjectionResistanceTest.php
 * (test_a_genuine_instruction_driven_tool_request_is_recorded_and_not_from_document_text
 * and test_tool_action_is_blocked_when_the_request_did_not_allow_tool_actions)
 * called assertDatabaseHas('ai_tool_actions', ...) immediately after
 * AiUsageRecorderService::record() returns — i.e. with NO tenant
 * context active, since record() clears its own context before
 * returning. assertDatabaseHas() issues a raw, context-free query, and
 * once ai_tool_actions has FORCE ROW LEVEL SECURITY active, that raw
 * query would see zero rows regardless of what record() actually wrote.
 * Both assertions are now wrapped in $this->runWithFirmContext($firm, ...)
 * using the same $firm already in scope, with the asserted values
 * unchanged — see that file's own diff and the allowlist entry below.
 */
class AiToolActionsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    // ---------------------------------------------------------------
    // FORCE state / policy proofs
    // ---------------------------------------------------------------

    public function test_all_previously_prepared_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_ai_tool_actions_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'ai_tool_actions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_ai_tool_actions_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'ai_tool_actions'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'ai_tool_actions must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'ai_tool_actions'::regclass and polname = 'ai_tool_actions_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The ai_tool_actions_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_ai_tool_actions(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => AiToolAction::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AiToolAction::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_ai_tool_actions(): void
    {
        $firm = Firm::factory()->create();
        $usageEvent = AiUsageEvent::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('ai_tool_actions')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'matter_id' => null,
            'ai_usage_event_id' => $usageEvent->id,
            'tool_name' => 'draft_summary_tool',
            'was_constrained' => false,
            'status' => AiToolActionStatus::Executed->value,
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    //
    // Read/update/delete isolation is proven via DB::table() rather
    // than the Eloquent model: AiToolAction::booted() throws a
    // LogicException on any Eloquent-path update/delete (this table is
    // append-only, project rule 10) before RLS is ever reached, so the
    // Eloquent path cannot be used to prove the *database-level*
    // cross-firm denial these tests target.
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_ai_tool_actions(): void
    {
        $firmA = Firm::factory()->create();
        $actionA = $this->runWithFirmContext($firmA, fn () => AiToolAction::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiToolAction::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$actionA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_ai_tool_actions(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => AiToolAction::factory()->forFirm($firmA)->create());
        $actionB = $this->runWithFirmContext($firmB, fn () => AiToolAction::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiToolAction::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($actionB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_ai_tool_action(): void
    {
        $firmA = Firm::factory()->create();
        $usageEvent = $this->runWithFirmContext($firmA, fn () => AiUsageEvent::factory()->forFirm($firmA)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $usageEvent) {
            return DB::table('ai_tool_actions')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'matter_id' => null,
                'ai_usage_event_id' => $usageEvent->id,
                'tool_name' => 'draft_summary_tool',
                'was_constrained' => false,
                'status' => AiToolActionStatus::Executed->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_ai_tool_action(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actionB = $this->runWithFirmContext(
            $firmB,
            fn () => AiToolAction::factory()->forFirm($firmB)->create(['tool_name' => 'original_tool']),
        );

        $affected = $this->runWithFirmContext($firmA, function () use ($actionB) {
            return DB::table('ai_tool_actions')->where('id', $actionB->id)->update(['was_constrained' => true]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s ai_tool_actions row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiToolAction::withoutGlobalScopes()->find($actionB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertFalse($reReadAsFirmB->was_constrained);
    }

    public function test_firm_a_cannot_delete_firm_b_ai_tool_action(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actionB = $this->runWithFirmContext($firmB, fn () => AiToolAction::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($actionB) {
            DB::table('ai_tool_actions')->where('id', $actionB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiToolAction::withoutGlobalScopes()->find($actionB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B ai_tool_actions.');
    }

    public function test_firm_a_cannot_insert_an_ai_tool_action_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $usageEventB = $this->runWithFirmContext($firmB, fn () => AiUsageEvent::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $usageEventB) {
            DB::table('ai_tool_actions')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'matter_id' => null,
                'ai_usage_event_id' => $usageEventB->id,
                'tool_name' => 'draft_summary_tool',
                'was_constrained' => false,
                'status' => AiToolActionStatus::Executed->value,
                'created_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actionA = $this->runWithFirmContext($firmA, fn () => AiToolAction::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($actionA, $firmB) {
            DB::table('ai_tool_actions')->where('id', $actionA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => AiToolAction::factory()->forFirm($firm)->create());

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
    // Shared-writer end-to-end proof — AiUsageRecorderService::record()
    // (unchanged by this checkpoint) still functions correctly, with
    // record()'s existing single outer runWithFirmContext() wrap
    // already covering AiToolActionRecorderService::recordFromResponse().
    // ---------------------------------------------------------------

    /**
     * Core proof: calling AiUsageRecorderService::record() for real, with
     * no test-supplied context, produces ai_tool_actions row(s) with the
     * correct firm_id, readable back under that firm's own context, with
     * no context leak afterward. See this class's own docblock for why
     * assertDatabaseHas()/assertDatabaseCount() (raw, context-free
     * queries) cannot be used here — this test reads the row back
     * through an explicit runWithFirmContext() wrap instead.
     */
    public function test_record_still_functions_end_to_end_under_force_and_produces_isolated_ai_tool_actions_rows(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling record()');

        $request = new AiPromptRequest(
            provider: AiProvider::OpenAi,
            model: 'fake-model-1',
            actionType: AiUsageActionType::ToolAction,
            instructionText: "Please look up the case status.\nREQUEST_TOOL: lookup_case_status",
            documentDerivedText: 'Ordinary, non-adversarial document content.',
            matterIds: [],
            allowToolActions: true,
        );

        $response = new AiProviderResponse(
            outputText: 'Looked up the case status.',
            tokensIn: 10,
            tokensOut: 5,
            requestedToolActions: ['lookup_case_status'],
        );

        $event = app(AiUsageRecorderService::class)->record($firm, $user, $request, $response);

        $this->assertNotNull($event->id);
        $this->assertNoDatabaseTenantContext('record() must restore context to the clean baseline it was called against');

        $recordedActions = $this->runWithFirmContext(
            $firm,
            fn () => AiToolAction::withoutGlobalScopes()->where('ai_usage_event_id', $event->id)->get(),
        );

        $this->assertCount(1, $recordedActions, 'record() must have recorded exactly one ai_tool_actions row for the one requested tool action.');
        $this->assertSame($firm->id, $recordedActions->first()->firm_id);
        $this->assertSame('lookup_case_status', $recordedActions->first()->tool_name);
        $this->assertSame(AiToolActionStatus::Executed, $recordedActions->first()->status);
        $this->assertFalse($recordedActions->first()->was_constrained);
        $this->assertNoDatabaseTenantContext('reading the row back under its own firm context must not leave context active afterward');
    }

    /**
     * Same shared writer, the Blocked-status branch: allowToolActions is
     * false but the (simulated) adapter still returned a requested tool
     * action — recordFromResponse() must still function under FORCE and
     * mark the row Blocked, not Executed.
     */
    public function test_record_still_functions_end_to_end_under_force_for_a_blocked_tool_action(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling record()');

        $request = new AiPromptRequest(
            provider: AiProvider::OpenAi,
            model: 'fake-model-1',
            actionType: AiUsageActionType::ToolAction,
            instructionText: 'instruction',
            documentDerivedText: null,
            matterIds: [],
            allowToolActions: false,
        );

        $response = new AiProviderResponse(
            outputText: 'output',
            tokensIn: 5,
            tokensOut: 5,
            requestedToolActions: ['some_tool'],
        );

        $event = app(AiUsageRecorderService::class)->record($firm, $user, $request, $response);

        $this->assertNoDatabaseTenantContext('record() must restore context to the clean baseline it was called against');

        $recordedActions = $this->runWithFirmContext(
            $firm,
            fn () => AiToolAction::withoutGlobalScopes()->where('ai_usage_event_id', $event->id)->get(),
        );

        $this->assertCount(1, $recordedActions);
        $this->assertSame(AiToolActionStatus::Blocked, $recordedActions->first()->status);
        $this->assertTrue($recordedActions->first()->was_constrained);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare AiToolAction::factory()->create() must
     * succeed even from outside any already-active tenant context (the
     * factory's context-hold create() override, mirroring
     * MatterExpenseFactory's exact mechanism — required by this
     * checkpoint's approved design), and its nested ai_usage_event must
     * belong to the SAME firm as firm_id — the factory's own root-cause
     * fix for the cross-firm mismatch a naive two-independent-factories
     * default would otherwise produce.
     *
     * This also confirms the context the factory's create() override
     * establishes is scoped correctly to only the row's own firm (no
     * accidental broader/global context-hold leak): the row is verified
     * readable under precisely its own firm_id's context and not before
     * that context is explicitly established.
     */
    public function test_bare_factory_create_is_safe_and_internally_consistent_with_no_accidental_context_leak(): void
    {
        $action = AiToolAction::factory()->create();

        $this->assertNotNull($action->id);
        $this->assertNotNull($action->firm_id);

        $persisted = $this->runWithFirmContext(
            $action->firm_id,
            fn () => AiToolAction::withoutGlobalScopes()->with('usageEvent')->find($action->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($action->firm_id, $persisted->usageEvent->firm_id, 'Bare factory default must not produce a cross-firm ai_usage_event mismatch.');
    }

    /**
     * Regression proof for the factory fix itself: forFirm() must tie
     * the nested ai_usage_event_id to the SAME firm passed to forFirm(),
     * not to an independently-randomized Firm::factory() firm.
     */
    public function test_factory_for_firm_ties_ai_usage_event_to_the_same_firm(): void
    {
        $firm = Firm::factory()->create();

        $action = $this->runWithFirmContext($firm, fn () => AiToolAction::factory()->forFirm($firm)->create());

        $usageEvent = $this->runWithFirmContext(
            $firm,
            fn () => AiUsageEvent::withoutGlobalScopes()->find($action->ai_usage_event_id),
        );

        $this->assertNotNull($usageEvent);
        $this->assertSame($firm->id, $usageEvent->firm_id, 'forFirm() must resolve ai_usage_event_id to a usage event belonging to the same firm.');
    }

    /**
     * Same regression proof for the bare definition() default (no
     * forFirm() call at all) — the single authoritative firm generated
     * inside definition() must be the one both firm_id and
     * ai_usage_event_id ultimately resolve to.
     */
    public function test_factory_definition_default_ties_ai_usage_event_to_the_same_firm(): void
    {
        $action = AiToolAction::factory()->create();

        $usageEvent = $this->runWithFirmContext(
            $action->firm_id,
            fn () => AiUsageEvent::withoutGlobalScopes()->find($action->ai_usage_event_id),
        );

        $this->assertNotNull($usageEvent);
        $this->assertSame($action->firm_id, $usageEvent->firm_id, 'The bare factory default must not produce a cross-firm ai_usage_event mismatch.');
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration
     * introduced the policy itself. up() is restored in a finally block
     * so later tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path('database/migrations/2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'ai_tool_actions'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'ai_tool_actions'::regclass and polname = 'ai_tool_actions_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only ai_tool_actions: matters
     * (already FORCE-active) and ai_usage_events (still unprepared) are
     * both bit-for-bit identical before and after a down()+up() round
     * trip, along with a sample of previously-prepared tables.
     */
    public function test_migration_round_trip_affects_only_ai_tool_actions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'matters', // already FORCE-active, must remain untouched
            'ai_usage_events', // still unprepared (MISSING_PREPARED_TABLES), must remain untouched
        ]);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path('database/migrations/2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php');
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the ai_tool_actions migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the ai_tool_actions migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Collision-guard / scope proofs
    // ---------------------------------------------------------------

    /**
     * Explicit collision guard: this checkpoint must NOT touch the
     * shared writer path used by sibling tables being implemented in
     * parallel.
     */
    public function test_ai_tool_action_recorder_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/AiToolActionRecorderService.php');

        $this->assertEmpty($changed, 'AiToolActionRecorderService.php is a SHARED writer for sibling tables being implemented in parallel and must remain untouched by this checkpoint.');
    }

    /**
     * Explicit collision guard: same rationale as above — record()'s
     * existing single outer wrap already covers this table's writes.
     */
    public function test_ai_usage_recorder_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/AiUsageRecorderService.php');

        $this->assertEmpty($changed, 'AiUsageRecorderService.php is a SHARED writer for sibling tables being implemented in parallel and must remain untouched by this checkpoint.');
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_reserved_wave_integration_paths_were_not_modified(): void
    {
        foreach ([
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'docs/governance/rls-gap-registry.md',
        ] as $reservedPath) {
            $changed = $this->changedOrUntrackedPaths($reservedPath);

            $this->assertEmpty($changed, "{$reservedPath} is reserved for a later, separate wave-integration commit and must remain untouched by this checkpoint.");
        }
    }

    public function test_no_rls_force_firewall_test_files_were_modified(): void
    {
        $changed = array_filter(
            $this->changedOrUntrackedPaths('tests/Feature/Security/RlsForceRollout'),
            fn (string $path) => str_contains($path, 'RlsForce') && str_contains($path, 'FirewallTest.php'),
        );

        $this->assertEmpty($changed, 'RlsForce*FirewallTest.php files are reserved for a later, separate wave-integration commit and must remain untouched by this checkpoint: '.implode(', ', $changed));
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
            'database/migrations/2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php',
            'database/factories/AiToolActionFactory.php',
            'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
            // Two assertDatabaseHas() calls there were context-free raw
            // queries against ai_tool_actions — a real, foreseeable
            // regression once FORCE RLS activates, fixed by wrapping
            // them, not by weakening what they assert.
            'tests/Feature/Ai/PromptInjection/PromptInjectionResistanceTest.php',
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
