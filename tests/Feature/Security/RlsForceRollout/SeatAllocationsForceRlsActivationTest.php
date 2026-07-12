<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DowngradeCheckStatus;
use App\Enums\FirmUserRole;
use App\Enums\PlanLimitMetric;
use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SeatAllocation;
use App\Models\SeatPool;
use App\Services\ComplianceGapRegistryService;
use App\Services\DowngradeEvaluationService;
use App\Services\EntitlementService;
use App\Services\PlanLimitService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\SeatAllocationService;
use App\Services\SeatEnforcementService;
use App\Services\SeatPoolService;
use App\Services\TenantContextService;
use App\Services\UsageRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SeatAllocationsForceRlsActivationTest — Section 39A-3L, Checkpoint 9,
 * Table Phase C. Proves the twenty-seventh staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930009_force_rls_on_seat_allocations_table.php)
 * is permanently active for seat_allocations and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table remains forced
 * simultaneously, and that SeatAllocationService's allocateDirect()/
 * allocateFromPool()/revoke() — each now wrapping its ENTIRE body in a
 * single runWithFirmContext() call — function correctly end-to-end
 * under FORCE.
 *
 * seat_pool_id is, unlike installed_template_pack_id (a real firm-scoped
 * foreign key found by TemplateUpgradePreviewsForceRlsActivationTest/
 * TemplateUpgradeLogsForceRlsActivationTest), a foreign key into
 * seat_pools — an organization-owned, NOT firm-owned table, deliberately
 * exempt from Phase 6 RLS. seat_pools itself has no firm_id column at
 * all, only organization_id, so there is no direct "does seat_pool_id
 * belong to the SAME FIRM" mismatch to even express — pooled seats are
 * legitimately shared across every firm under one organization by
 * design. The genuine residual gap here is one level up the chain:
 * RLS validates only this row's own firm_id, never that the firm making
 * the claim (via its own organization_id) actually belongs to the SAME
 * ORGANIZATION as the seat_pool_id it references. See
 * test_firm_can_still_create_a_seat_allocation_against_another_organizations_seat_pool_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 *
 * This batch also closes a real, previously-live silent bug discovered
 * by this checkpoint's audit: DowngradeEvaluationService::evaluate()
 * calls SeatEnforcementService::usageFor(), which reads BOTH
 * seat_allocations and firm_users (firm_users has been FORCE RLS since
 * an earlier checkpoint). Before this batch's fix, that call site had
 * no context wrap of its own, so evaluate() silently undercounted seat
 * usage to zero whenever it was invoked without ambient tenant context
 * already active — never throwing, never warning, just quietly
 * reporting "0 used" and letting an over-limit downgrade sail through
 * as falsely "safe." See
 * test_downgrade_evaluation_correctly_counts_seat_usage_under_force_rls_instead_of_silently_undercounting_to_zero
 * below.
 */
class SeatAllocationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews',
    ];

    public function test_every_previously_forced_table_remains_force_row_level_security_enabled(): void
    {
        foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must remain FORCE RLS enabled after this batch."
            );
        }
    }

    public function test_seat_allocations_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'seat_allocations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_seat_allocations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'seat_allocations'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'seat_allocations must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-seven tables (the twenty-six previously forced plus
     * seat_allocations) must be FORCE-enabled among ALL prepared tables
     * — no more, no less.
     */
    public function test_exactly_twenty_seven_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 11, Table Phase C
        // (communication_consents) — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table
        // Phase C (communication_consent_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses']);

        $actuallyForced = [];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ((bool) $row->relforcerowsecurity) {
                $actuallyForced[] = $table;
            }
        }

        sort($expectedForced);
        sort($actuallyForced);

        // Narrowly updated by Section 39A-3L, Checkpoint 10, Table
        // Phase C (document_requests) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (communication_consents) for the same reason —
        // additive only, no existing assertion removed or weakened.
        $this->assertSame(37, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (document_requests and communication_consents added on top of this batch\'s own seat_allocations, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch —
     * seat_pools in particular (its own conceptual relative) must
     * remain completely exempt: no RLS enabled at all, not merely
     * unforced.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled_and_seat_pools_remains_exempt(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated by Section 39A-3L, Checkpoint 11, Table Phase C
        // (communication_consents) for the same reason as the count test
        // above — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table
        // Phase C (communication_consent_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses']);

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }

        $seatPoolsRow = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'seat_pools'");
        $this->assertNotNull($seatPoolsRow);
        $this->assertFalse((bool) $seatPoolsRow->relrowsecurity, 'seat_pools must remain completely exempt from RLS — not merely unforced.');
        $this->assertFalse((bool) $seatPoolsRow->relforcerowsecurity, 'seat_pools must remain completely exempt from FORCE RLS.');
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'seat_allocations'::regclass"
        );

        $this->assertNotNull($policy, 'The seat_allocations tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the read
     * genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_seat_allocations(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => SeatAllocation::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, SeatAllocation::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_seat_allocations(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('seat_allocations')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'seat_pool_id' => null,
            'seat_class' => SeatClass::Attorney->value,
            'seats_allocated' => 5,
            'status' => SeatAllocationStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_seat_allocation(): void
    {
        $firmA = Firm::factory()->create();
        $allocationA = $this->runWithFirmContext($firmA, fn () => SeatAllocation::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SeatAllocation::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$allocationA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_seat_allocation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => SeatAllocation::factory()->forFirm($firmA)->create());
        $allocationB = $this->runWithFirmContext($firmB, fn () => SeatAllocation::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SeatAllocation::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($allocationB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('seat_allocations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'seat_pool_id' => null,
                'seat_class' => SeatClass::Attorney->value,
                'seats_allocated' => 5,
                'status' => SeatAllocationStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_seat_allocation_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('seat_allocations')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'seat_pool_id' => null,
                'seat_class' => SeatClass::Attorney->value,
                'seats_allocated' => 5,
                'status' => SeatAllocationStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_seat_allocation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $allocationB = $this->runWithFirmContext($firmB, fn () => SeatAllocation::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($allocationB) {
            DB::table('seat_allocations')->where('id', $allocationB->id)->update(['status' => SeatAllocationStatus::Revoked->value]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocationB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            SeatAllocationStatus::Active,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s seat_allocations row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_seat_allocation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $allocationB = $this->runWithFirmContext($firmB, fn () => SeatAllocation::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($allocationB) {
            DB::table('seat_allocations')->where('id', $allocationB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocationB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s seat_allocations row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_seat_allocation_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $allocationB = $this->runWithFirmContext($firmB, fn () => SeatAllocation::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $allocationB) {
            return DB::table('seat_allocations')->where('id', $allocationB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s seat allocation to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocationB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock: seat_pools carries NO firm_id column at
     * all (organization_id only), so RLS on seat_allocations has
     * nothing firm-scoped to even compare seat_pool_id against — a raw
     * insert whose firm_id matches the active context still succeeds
     * even when seat_pool_id points at a pool belonging to a
     * COMPLETELY DIFFERENT organization than the firm's own
     * organization_id. This is a documented residual
     * DATABASE-CONSTRAINT gap (business-logic level, one hop further
     * than the firm-to-firm mismatches found in prior checkpoints), not
     * something RLS itself closes — never to be described as blocked.
     */
    public function test_firm_can_still_create_a_seat_allocation_against_another_organizations_seat_pool_at_the_raw_db_layer(): void
    {
        $ownOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $ownOrganization->id]);
        $foreignPool = SeatPool::factory()->forOrganization($otherOrganization)->create();

        $mismatchedAllocationId = $this->runWithFirmContext($firm, function () use ($firm, $foreignPool) {
            return DB::table('seat_allocations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'seat_pool_id' => $foreignPool->id,
                'seat_class' => $foreignPool->seat_class->value,
                'seats_allocated' => 1,
                'status' => SeatAllocationStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedAllocationId,
            'RLS only checks the row\'s own firm_id — a seat_pool_id belonging to a different organization than the firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: a bare SeatAllocation::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and the row must
     * actually be visible/readable under its own firm's context
     * afterward.
     */
    public function test_seat_allocation_factory_default_creation_is_internally_consistent(): void
    {
        $allocation = SeatAllocation::factory()->create();

        $this->assertNotNull($allocation->id);
        $this->assertNotNull($allocation->firm_id);

        $persisted = $this->runWithFirmContext(
            $allocation->firm,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocation->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($allocation->firm_id, $persisted->firm_id);
    }

    /**
     * Explicit related-model factory state correctness: forFirm() must
     * set firm_id to the EXACT firm given, and the row must be readable
     * only under that firm's context.
     */
    public function test_seat_allocation_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $allocation = $this->runWithFirmContext($firm, fn () => SeatAllocation::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $allocation->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocation->id),
        );

        $this->assertNotNull($persisted);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => SeatAllocation::factory()->forFirm($firm)->create());

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
     * End-to-end proof that SeatAllocationService::allocateDirect()
     * functions correctly under FORCE — wraps its entire body in
     * runWithFirmContext() and clears context in a finally block before
     * returning.
     */
    public function test_the_allocate_direct_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $service = new SeatAllocationService();

        $allocation = $service->allocateDirect($firm, SeatClass::Attorney, 5);
        $this->assertNoDatabaseTenantContext('allocateDirect() must clear its own context wrap in a finally block before returning.');

        $this->assertFalse($allocation->isPooled());
        $this->assertSame(5, $allocation->seats_allocated);
        $this->assertSame(SeatAllocationStatus::Active, $allocation->status);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocation->id),
        );

        $this->assertNotNull($persisted, 'allocateDirect() must actually persist the new seat_allocations row to the database.');
    }

    /**
     * End-to-end proof that allocateFromPool()/revoke() function
     * correctly under FORCE — each wraps its entire
     * transaction/lock/update chain, and each clears context in a
     * finally block before returning.
     */
    public function test_the_allocate_from_pool_and_revoke_flows_function_correctly_under_force_rls(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $poolService = new SeatPoolService();
        $allocationService = new SeatAllocationService();

        $pool = $poolService->createPool($organization, SeatClass::Staff, 10);
        $this->assertNoDatabaseTenantContext();

        $allocation = $allocationService->allocateFromPool($firm, $pool, 4);
        $this->assertNoDatabaseTenantContext('allocateFromPool() must clear its own context wrap in a finally block before returning.');

        $this->assertTrue($allocation->isPooled());
        $this->assertSame(4, $pool->fresh()->allocated_seats);

        $revoked = $allocationService->revoke($allocation);
        $this->assertNoDatabaseTenantContext('revoke() must clear its own context wrap in a finally block before returning.');

        $this->assertSame(SeatAllocationStatus::Revoked, $revoked->status);
        $this->assertSame(0, $pool->fresh()->allocated_seats);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => SeatAllocation::withoutGlobalScopes()->find($allocation->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(SeatAllocationStatus::Revoked, $persisted->status);
    }

    /**
     * Closes the real, previously-live silent bug this checkpoint's
     * audit discovered: DowngradeEvaluationService::evaluate() must
     * correctly count seat usage under FORCE RLS rather than silently
     * reporting zero when called with no ambient tenant context already
     * active. Deliberately does NOT wrap the evaluate() call itself in
     * runWithFirmContext() — the whole point is proving evaluate() is
     * now self-sufficient.
     */
    public function test_downgrade_evaluation_correctly_counts_seat_usage_under_force_rls_instead_of_silently_undercounting_to_zero(): void
    {
        $firm = Firm::factory()->create();
        $allocationService = new SeatAllocationService();
        $allocationService->allocateDirect($firm, SeatClass::Attorney, 10);
        FirmUser::factory()->forFirm($firm)->role(FirmUserRole::Attorney)->count(3)->create();

        $newPlan = Plan::factory()->create();
        $planLimitService = new PlanLimitService();
        $planLimitService->setLimit($newPlan, PlanLimitMetric::SeatsAttorney, 1);

        $service = new DowngradeEvaluationService(
            new SeatEnforcementService(),
            new EntitlementService(),
            $planLimitService,
            new UsageRollupService(),
        );

        // Deliberately no ambient tenant context set here — proving
        // evaluate() establishes its own context internally rather than
        // relying on the caller (or a leftover leaked context) to have
        // already done so.
        $result = $service->evaluate($firm, $newPlan);
        $this->assertNoDatabaseTenantContext('evaluate() must clear its own context wrap(s) before returning.');

        $this->assertFalse($result->safe);
        $this->assertSame(DowngradeCheckStatus::BlockedSeatOveruse, $result->status);
        $this->assertSame(
            3,
            $result->seatFindings[SeatClass::Attorney->value]['used'],
            'Seat usage must be correctly counted as 3 under FORCE RLS — silently reporting 0 here would be exactly the previously-live bug this checkpoint closes.'
        );
    }

    /**
     * Twenty-six previously forced tables plus seat_allocations must be
     * independently force-active and independently isolated at the same
     * time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses firm_users (the table
     * SeatEnforcementService::usageFor() also reads) as the companion
     * table.
     */
    public function test_seat_allocations_is_isolated_independently_and_simultaneously_with_firm_users(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $allocationA = $this->runWithFirmContext($firmA, fn () => SeatAllocation::factory()->forFirm($firmA)->create());
        $allocationB = $this->runWithFirmContext($firmB, fn () => SeatAllocation::factory()->forFirm($firmB)->create());

        $firmUserA = FirmUser::factory()->forFirm($firmA)->role(FirmUserRole::Attorney)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->role(FirmUserRole::Attorney)->create();

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'seat_allocations' => SeatAllocation::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$allocationA->id], $resultA['seat_allocations']);
        $this->assertNotContains($allocationB->id, $resultA['seat_allocations']);
        $this->assertContains($firmUserA->id, $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself. Also proves rollback affects ONLY this one table — every
     * other previously-forced table must be untouched.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930009_force_rls_on_seat_allocations_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'seat_allocations'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while seat_allocations is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'seat_allocations'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'seat_allocations'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
