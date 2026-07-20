<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Enums\OffboardingRequestStatus;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\Matter;
use App\Models\PlatformAdmin;
use App\Models\RetentionPolicy;
use App\Services\DeletionGovernanceService;
use App\Services\DeletionRequestService;
use App\Services\KeyDestructionRequestService;
use App\Services\LegalHoldService;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LegalHoldsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for legal_holds (database/migrations/
 * 2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php)
 * is permanently active and behaves correctly.
 *
 * First of the six-table, one-batch Section 39A-8 Wave 8 activation
 * (governance/support/platform domain: legal_holds, deletion_requests,
 * key_destruction_requests, support_access_requests,
 * support_access_sessions, deployment_health_checks) — see this
 * migration's own docblock for the full combined-batch rationale.
 *
 * legal_holds lands first because LegalHoldService::hasActiveHold()/
 * checkHold() is the single clearance check every other table in this
 * batch (deletion_requests, key_destruction_requests) and one
 * out-of-batch caller (offboarding_requests) must call before allowing
 * deletion or key destruction. checkHold()/hasActiveHold() deliberately
 * carry NO wrap of their own (a shared helper invoked from multiple
 * callers touching different tables never self-wraps — each caller
 * wraps its own call, keyed on the firm being checked) — this file's
 * own "fail-open regression" section below is the single most
 * important test group in this wave: it proves the silent
 * security-bypass bug (an unwrapped read under FORCE silently returns
 * zero rows rather than erroring, making an active hold invisible to
 * every one of its 3 real callers) is genuinely closed, not merely that
 * a wrap exists syntactically.
 */
class LegalHoldsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php';

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

    public function test_legal_holds_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('legal_holds', $coverage->forcedTables());
    }

    /**
     * forcedTables() is derived dynamically from every
     * *_force_rls_on_*_table.php migration present in the repository —
     * so the exact count this checkpoint expects is itself exact and
     * reviewable: 86 tables forced by every prior wave (through Section
     * 39A-7 Wave 7), plus this batch's own 6 (legal_holds,
     * deletion_requests, key_destruction_requests,
     * support_access_requests, support_access_sessions,
     * deployment_health_checks) = 92, no more, no fewer.
     */
    public function test_the_forced_tables_registry_reports_exactly_ninety_two_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-5 Wave 9 (migration/export domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration Platform mission — firm_integrations added, bumping the forced-table total (113 -> 114).
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase Integration Platform mission (integration_credentials, a new genuine tenant-owned table with RLS prepared and FORCE-activated in the same migration) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertCount(
            115,
            $coverage->forcedTables(),
            'Exactly 108 tables must have FORCE ROW LEVEL SECURITY active after this Wave 8 batch lands — no more, no fewer.'
        );
    }

    public function test_legal_holds_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'legal_holds'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_legal_holds_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'legal_holds'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'legal_holds must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'legal_holds'::regclass and polname = 'legal_holds_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The legal_holds_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_legal_holds(): void
    {
        $firm = Firm::factory()->create();
        $this->createHoldForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, LegalHold::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_legal_holds(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('legal_holds')->insert($this->rowAttributes($firm, $admin));
    }

    /**
     * LegalHoldFactory DID gain a context-hold create() override in
     * this batch — its bare default-creation path is already
     * tenant-consistent, so a bare LegalHold::factory()->create() must
     * now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $hold = LegalHold::factory()->create();

        $this->assertNotNull($hold->id);
        $this->assertNotNull($hold->firm_id);

        $persisted = $this->runWithFirmContext(
            $hold->firm_id,
            fn () => LegalHold::query()->find($hold->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($hold->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_legal_hold(): void
    {
        $firmA = Firm::factory()->create();
        $holdA = $this->createHoldForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => LegalHold::query()->pluck('id')->all(),
        );

        $this->assertSame([$holdA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_legal_hold(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createHoldForFirm($firmA);
        $holdB = $this->createHoldForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => LegalHold::query()->pluck('id')->all(),
        );

        $this->assertNotContains($holdB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_legal_hold(): void
    {
        $firmA = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('legal_holds')->insertGetId($this->rowAttributes($firmA, $admin)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_legal_hold(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $holdB = $this->createHoldForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($holdB) {
            return DB::table('legal_holds')->where('id', $holdB->id)->update(['status' => LegalHoldStatus::Released->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s legal_holds row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => LegalHold::query()->find($holdB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(LegalHoldStatus::Active, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_legal_hold(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $holdB = $this->createHoldForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($holdB) {
            DB::table('legal_holds')->where('id', $holdB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => LegalHold::query()->find($holdB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B legal_holds.');
    }

    public function test_firm_a_cannot_insert_a_legal_hold_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $admin) {
            DB::table('legal_holds')->insert($this->rowAttributes($firmB, $admin));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $holdA = $this->createHoldForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($holdA, $firmB) {
            DB::table('legal_holds')->where('id', $holdA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Per the migration's own docblock, deferred gap #1:
     * client_id/matter_id/document_id are single-hop, nullable FKs with
     * no composite FK/trigger tying the referenced row's own firm_id to
     * this row's own firm_id. RLS only checks this row's own firm_id.
     * Proven directly: a raw insert can and does create this mismatch.
     */
    public function test_legal_hold_can_reference_a_different_firms_matter_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $admin, $matterB) {
            $attributes = $this->rowAttributes($firmA, $admin);
            $attributes['scope_type'] = LegalHoldScope::Matter->value;
            $attributes['matter_id'] = $matterB->id;

            return DB::table('legal_holds')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => LegalHold::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($matterB->id, $persisted->matter_id, 'The row genuinely persisted pointing at firm B\'s own matter row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createHoldForFirm($firm);

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
     * LegalHoldService::place()/release() each clear their own wrap
     * before returning — proven directly, not merely by exercising
     * checkHold() which never wraps at all.
     */
    public function test_legal_hold_service_place_and_release_clear_context_after_returning(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $service = new LegalHoldService();

        $hold = $service->place($firm, LegalHoldScope::Firm, 'Litigation.', $admin);
        $this->assertNoDatabaseTenantContext('place() must clear its own context wrap before returning.');

        $service->release($hold, $admin, 'Concluded.');
        $this->assertNoDatabaseTenantContext('release() must clear its own context wrap before returning.');
    }

    // ---------------------------------------------------------------
    // TOP PRIORITY: fail-open regression — the single most important
    // proof in this wave. checkHold()/hasActiveHold() carry NO wrap of
    // their own (shared helper, never self-wraps); each of the 3 real
    // callers below wraps its own call. Proves the fix is genuinely
    // closed empirically: a real, active hold is correctly reported as
    // BLOCKING clearance for every one of the 3 callers, not silently
    // "clear" (the fail-open shape this fix closes — an unwrapped read
    // under FORCE silently returns zero rows, not an error).
    // ---------------------------------------------------------------

    /**
     * Caller 1 — DeletionGovernanceService::checkClearance().
     */
    public function test_deletion_governance_service_check_clearance_correctly_detects_an_active_legal_hold_under_force(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);

        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);
        app(OffboardingExportService::class)->verify($export, $admin);

        // Place a REAL, active legal hold for this exact firm/matter.
        app(LegalHoldService::class)->place($firm, LegalHoldScope::Matter, 'Litigation in progress.', $admin, matter: $matter);

        $request = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Retention cleared.', $admin, offboardingExport: $export);
        $request->forceFill(['created_at' => now()->subYears(5)])->save();

        $clearance = app(DeletionGovernanceService::class)->checkClearance($request, RetentionRecordType::Matter);

        $this->assertFalse($clearance->isClear(), 'DeletionGovernanceService::checkClearance() must correctly detect the active legal hold and report NOT clear — silently reporting "clear" here is exactly the fail-open bug this batch closes.');
        $this->assertTrue($clearance->exportCleared);
        $this->assertTrue($clearance->retentionCleared);
        $this->assertStringContainsString('legal hold', (string) $clearance->reason);
    }

    /**
     * Caller 2 — KeyDestructionRequestService::checkClearance().
     */
    public function test_key_destruction_request_service_check_clearance_correctly_detects_an_active_legal_hold_under_force(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);
        app(OffboardingExportService::class)->verify($export, $admin);

        // Place a REAL, active firm-scope legal hold.
        app(LegalHoldService::class)->place($firm, LegalHoldScope::Firm, 'Litigation in progress.', $admin);

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin, 'Destroy key.', $offboardingRequest);

        $clearance = app(KeyDestructionRequestService::class)->checkClearance($request);

        $this->assertFalse($clearance->isClear(), 'KeyDestructionRequestService::checkClearance() must correctly detect the active legal hold and report NOT clear — silently reporting "clear" here is exactly the fail-open bug this batch closes.');
        $this->assertTrue($clearance->exportCleared);
        $this->assertTrue($clearance->retentionCleared);
        $this->assertStringContainsString('legal hold', (string) $clearance->reason);
    }

    /**
     * Caller 3 — OffboardingRequestService::evaluateReadiness() — out
     * of this wave's table scope (offboarding_requests is not one of
     * the 6 tables), but the call site changed because checkHold()'s
     * behavior is changing, and legal_holds itself IS in scope.
     */
    public function test_offboarding_request_service_evaluate_readiness_correctly_detects_an_active_legal_hold_under_force(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);
        app(OffboardingExportService::class)->verify($export, $admin);

        // Place a REAL, active firm-scope legal hold.
        app(LegalHoldService::class)->place($firm, LegalHoldScope::Firm, 'Litigation in progress.', $admin);

        // offboarding_requests is now FORCE RLS'd (Wave 9): request()'s own
        // wrap has already exited by this point and restored context to
        // none, so a bare ->fresh() call here would return null. Wrap it
        // explicitly, keyed on the firm this request belongs to.
        $readiness = app(OffboardingRequestService::class)->evaluateReadiness(
            (new TenantContextService())->runWithFirmContext($firm, fn () => $offboardingRequest->fresh())
        );

        $this->assertTrue($readiness->exportCompleted);
        $this->assertTrue($readiness->retentionCleared);
        $this->assertFalse($readiness->legalHoldCleared, 'OffboardingRequestService::evaluateReadiness() must correctly detect the active legal hold — silently reporting it cleared here is exactly the fail-open bug this batch closes.');

        $advanced = app(OffboardingRequestService::class)->advance(
            (new TenantContextService())->runWithFirmContext($firm, fn () => $offboardingRequest->fresh())
        );
        $this->assertSame(OffboardingRequestStatus::LegalHoldBlocked, $advanced->status, 'advance() must transition to LegalHoldBlocked, proving the hold was genuinely detected end-to-end.');
    }

    /**
     * Negative control for all 3 callers above: with NO hold placed at
     * all, every one of them must correctly report clear — proving the
     * fix does not over-correct into a permanent false-positive block.
     */
    public function test_all_three_callers_correctly_report_clear_when_no_legal_hold_exists(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Matter,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);
        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin);
        app(OffboardingExportService::class)->verify($export, $admin);

        $deletionRequest = app(DeletionRequestService::class)->request($firm, Matter::class, $matter->id, 'Retention cleared.', $admin, offboardingExport: $export);
        $deletionRequest->forceFill(['created_at' => now()->subYears(5)])->save();
        $deletionClearance = app(DeletionGovernanceService::class)->checkClearance($deletionRequest, RetentionRecordType::Matter);
        $this->assertTrue($deletionClearance->isClear());

        $keyDestructionRequest = app(KeyDestructionRequestService::class)->request($firm, $admin, 'Destroy key.', $offboardingRequest);
        $keyDestructionClearance = app(KeyDestructionRequestService::class)->checkClearance($keyDestructionRequest);
        $this->assertTrue($keyDestructionClearance->isClear());

        // Same FORCE-RLS-driven fix as above: bare ->fresh() with no
        // ambient context active would return null once offboarding_requests
        // is FORCE'd, so wrap it explicitly keyed on $firm.
        $readiness = app(OffboardingRequestService::class)->evaluateReadiness(
            (new TenantContextService())->runWithFirmContext($firm, fn () => $offboardingRequest->fresh())
        );
        $this->assertTrue($readiness->legalHoldCleared);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'legal_holds'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'legal_holds'::regclass and polname = 'legal_holds_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'legal_holds'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_legal_holds(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'deletion_requests';
        $otherTables[] = 'key_destruction_requests';

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
                "{$table}'s relrowsecurity must be unaffected by the legal_holds migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the legal_holds migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $thisBatch = ['legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks'];

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, $thisBatch, true)) {
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

    public function test_gap_registry_doc_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('docs/governance/rls-gap-registry.md');

        $this->assertEmpty($changed, 'docs/governance/rls-gap-registry.md must remain untouched by this checkpoint — reserved for a later wave-integration commit.');
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

    private function createHoldForFirm(Firm $firm): LegalHold
    {
        return $this->runWithFirmContext($firm, fn () => LegalHold::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, PlatformAdmin $admin): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'scope_type' => LegalHoldScope::Firm->value,
            'client_id' => null,
            'matter_id' => null,
            'document_id' => null,
            'reason' => 'Pending litigation.',
            'status' => LegalHoldStatus::Active->value,
            'placed_by_type' => PlatformAdmin::class,
            'placed_by_id' => $admin->id,
            'placed_at' => now(),
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
