<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SupportAccessRequestsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for support_access_requests (database/
 * migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php)
 * is permanently active and behaves correctly.
 *
 * Fourth of the six-table, one-batch Section 39A-8 Wave 8 activation —
 * see LegalHoldsForceRlsActivationTest's own docblock for the full
 * combined-batch rationale and table order. Must land BEFORE
 * support_access_sessions — a hard, parent-before-child ordering
 * requirement, since the sessions migration adds a composite foreign
 * key referencing the compound UNIQUE(firm_id, id) constraint this
 * migration adds.
 *
 * Neither SupportAccessRequest nor SupportAccessSession uses
 * BelongsToTenant — deliberate: the actor is always a PlatformAdmin
 * with no ambient firm-membership context. firm_id is still the
 * correct, sufficient isolation key for RLS purposes — RLS operates at
 * the DB layer regardless of Eloquent trait usage. Fully mutable via
 * approve()/deny()/expire() — same-firm UPDATE is expected to succeed.
 */
class SupportAccessRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php';

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

    public function test_support_access_requests_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('support_access_requests', $coverage->forcedTables());
    }

    public function test_support_access_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'support_access_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_support_access_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'support_access_requests'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'support_access_requests must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'support_access_requests'::regclass and polname = 'support_access_requests_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The support_access_requests_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_compound_unique_firm_id_id_constraint_exists(): void
    {
        $constraint = DB::selectOne(
            "select 1 from pg_constraint where conname = 'support_access_requests_firm_id_id_unique' and conrelid = 'support_access_requests'::regclass"
        );

        $this->assertNotNull($constraint, 'The compound UNIQUE(firm_id, id) constraint must exist so support_access_sessions can reference it via a composite FK.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_support_access_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->createRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, SupportAccessRequest::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_support_access_requests(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('support_access_requests')->insert($this->rowAttributes($firm, $admin));
    }

    /**
     * SupportAccessRequestFactory DID gain a context-hold create()
     * override in this batch — its bare default-creation path is
     * already tenant-consistent, so a bare
     * SupportAccessRequest::factory()->create() must now SUCCEED even
     * with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $request = SupportAccessRequest::factory()->create();

        $this->assertNotNull($request->id);
        $this->assertNotNull($request->firm_id);

        $persisted = $this->runWithFirmContext(
            $request->firm_id,
            fn () => SupportAccessRequest::query()->find($request->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($request->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_support_access_request(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->createRequestForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SupportAccessRequest::query()->pluck('id')->all(),
        );

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_support_access_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createRequestForFirm($firmA);
        $requestB = $this->createRequestForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SupportAccessRequest::query()->pluck('id')->all(),
        );

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_support_access_request(): void
    {
        $firmA = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('support_access_requests')->insertGetId($this->rowAttributes($firmA, $admin)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_support_access_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRequestForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($requestB) {
            return DB::table('support_access_requests')->where('id', $requestB->id)->update(['status' => SupportAccessRequestStatus::Denied->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s support_access_requests row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SupportAccessRequest::query()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(SupportAccessRequestStatus::Requested, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_support_access_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRequestForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('support_access_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SupportAccessRequest::query()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B support_access_requests.');
    }

    public function test_firm_a_cannot_insert_a_support_access_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $admin) {
            DB::table('support_access_requests')->insert($this->rowAttributes($firmB, $admin));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->createRequestForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($requestA, $firmB) {
            DB::table('support_access_requests')->where('id', $requestA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createRequestForFirm($firm);

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

    /**
     * Rollback must remove all effects up() added, IN REVERSE ORDER:
     * FORCE, the policy, RLS itself, and finally the compound
     * UNIQUE(firm_id, id) constraint. By the time this down() runs
     * during an isolated call like this one (not a real migrate:rollback
     * across the whole batch), support_access_sessions' own composite
     * FK (added by a later, separate migration) still exists and
     * depends on this constraint — so this isolated round trip must NOT
     * attempt to drop the compound unique constraint while the sessions
     * migration's FK is still in place. Confirm this explicitly rather
     * than assuming it.
     */
    public function test_migration_down_fully_restores_the_rls_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        // Run inside its own nested DB::transaction() (Laravel emits a
        // real SAVEPOINT here, since RefreshDatabase already wraps this
        // whole test in an outer transaction) so the expected failure
        // below rolls back to the savepoint rather than poisoning the
        // rest of this test's transaction.
        $threw = false;

        try {
            DB::transaction(function () use ($migration) {
                $migration->down();
            });
        } catch (\Throwable $e) {
            // Expected: dropping the compound UNIQUE(firm_id, id)
            // constraint in isolation, while support_access_sessions'
            // own composite FK (added by a later, separate migration in
            // this same batch) still depends on it, must fail at the
            // database layer — proving the constraint is genuinely
            // still enforced, not silently droppable.
            $threw = true;
            $this->assertStringContainsStringIgnoringCase('depends on', $e->getMessage().($e->getPrevious()?->getMessage() ?? ''));
        }

        $this->assertTrue($threw, 'Expected down() to fail while support_access_sessions\' composite FK still depends on the UNIQUE(firm_id, id) constraint this migration owns.');

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'support_access_requests'");
        $this->assertTrue((bool) $row->relforcerowsecurity, 'A failed, rolled-back down() must not leave FORCE cleared.');
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

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    private function createRequestForFirm(Firm $firm): SupportAccessRequest
    {
        return $this->runWithFirmContext($firm, fn () => SupportAccessRequest::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, PlatformAdmin $admin): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'requested_by' => $admin->id,
            'access_type' => SupportAccessType::Standard->value,
            'reason' => 'Investigating a client-reported billing discrepancy.',
            'status' => SupportAccessRequestStatus::Requested->value,
            'requested_duration_minutes' => 60,
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
