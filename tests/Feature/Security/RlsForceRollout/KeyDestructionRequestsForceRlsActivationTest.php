<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\KeyDestructionRequestStatus;
use App\Models\Firm;
use App\Models\KeyDestructionRequest;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KeyDestructionRequestsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for key_destruction_requests (database/
 * migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php)
 * is permanently active and behaves correctly.
 *
 * Third of the six-table, one-batch Section 39A-8 Wave 8 activation —
 * see LegalHoldsForceRlsActivationTest's own docblock for the full
 * combined-batch rationale and table order. Lands after legal_holds
 * because KeyDestructionRequestService::checkClearance() calls
 * LegalHoldService::hasActiveHold() as part of its own clearance gate.
 * Mirrors deletion_requests structurally.
 *
 * KeyDestructionRequest's booted() blocks physical deletion of this row
 * (permanent governance evidence) but the row is otherwise fully
 * mutable via update() — same-firm UPDATE is expected to succeed.
 */
class KeyDestructionRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php';

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

    public function test_key_destruction_requests_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('key_destruction_requests', $coverage->forcedTables());
    }

    public function test_key_destruction_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'key_destruction_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_key_destruction_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'key_destruction_requests'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'key_destruction_requests must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'key_destruction_requests'::regclass and polname = 'key_destruction_requests_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The key_destruction_requests_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_key_destruction_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->createRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, KeyDestructionRequest::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_key_destruction_requests(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('key_destruction_requests')->insert($this->rowAttributes($firm, $admin));
    }

    /**
     * KeyDestructionRequestFactory DID gain a context-hold create()
     * override in this batch — its bare default-creation path is
     * already tenant-consistent, so a bare
     * KeyDestructionRequest::factory()->create() must now SUCCEED even
     * with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $request = KeyDestructionRequest::factory()->create();

        $this->assertNotNull($request->id);
        $this->assertNotNull($request->firm_id);

        $persisted = $this->runWithFirmContext(
            $request->firm_id,
            fn () => KeyDestructionRequest::query()->find($request->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($request->firm_id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_key_destruction_request(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->createRequestForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => KeyDestructionRequest::query()->pluck('id')->all(),
        );

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_key_destruction_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createRequestForFirm($firmA);
        $requestB = $this->createRequestForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => KeyDestructionRequest::query()->pluck('id')->all(),
        );

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_key_destruction_request(): void
    {
        $firmA = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('key_destruction_requests')->insertGetId($this->rowAttributes($firmA, $admin)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_key_destruction_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRequestForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($requestB) {
            return DB::table('key_destruction_requests')->where('id', $requestB->id)->update(['status' => KeyDestructionRequestStatus::Cancelled->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s key_destruction_requests row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => KeyDestructionRequest::query()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(KeyDestructionRequestStatus::Requested, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_key_destruction_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRequestForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('key_destruction_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => KeyDestructionRequest::query()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B key_destruction_requests.');
    }

    public function test_firm_a_cannot_insert_a_key_destruction_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $admin) {
            DB::table('key_destruction_requests')->insert($this->rowAttributes($firmB, $admin));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->createRequestForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($requestA, $firmB) {
            DB::table('key_destruction_requests')->where('id', $requestA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Per the migration's own docblock, deferred gap #1:
     * offboarding_request_id (nullable) is a single-hop FK with no
     * composite FK/trigger tying offboarding_requests.firm_id to this
     * row's own firm_id. RLS only checks this row's own firm_id.
     * Proven directly: a raw insert can and does create this mismatch.
     */
    public function test_key_destruction_request_can_reference_a_different_firms_offboarding_request_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $offboardingRequestB = OffboardingRequest::factory()->create(['firm_id' => $firmB->id]);

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $admin, $offboardingRequestB) {
            $attributes = $this->rowAttributes($firmA, $admin);
            $attributes['offboarding_request_id'] = $offboardingRequestB->id;

            return DB::table('key_destruction_requests')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => KeyDestructionRequest::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($offboardingRequestB->id, $persisted->offboarding_request_id, 'The row genuinely persisted pointing at firm B\'s own offboarding_requests row despite its own firm_id being firm A — the residual gap this test documents.');
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

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'key_destruction_requests'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'key_destruction_requests'::regclass and polname = 'key_destruction_requests_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'key_destruction_requests'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    public function test_migration_round_trip_affects_only_key_destruction_requests(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'legal_holds';
        $otherTables[] = 'deletion_requests';

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
                "{$table}'s relrowsecurity must be unaffected by the key_destruction_requests migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the key_destruction_requests migration round trip."
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

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    private function createRequestForFirm(Firm $firm): KeyDestructionRequest
    {
        return $this->runWithFirmContext($firm, fn () => KeyDestructionRequest::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, PlatformAdmin $admin): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'offboarding_request_id' => null,
            'tenant_encryption_key_id' => null,
            'status' => KeyDestructionRequestStatus::Requested->value,
            'reason' => 'Firm offboarding complete; destroying envelope encryption key.',
            'requested_by_platform_admin_id' => $admin->id,
            'requested_at' => now(),
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
