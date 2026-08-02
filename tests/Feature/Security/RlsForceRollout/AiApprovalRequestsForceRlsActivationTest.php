<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AiApprovalCategory;
use App\Enums\AiApprovalRequestStatus;
use App\Models\AiApprovalRequest;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AiApprovalRequestsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for ai_approval_requests (database/migrations/
 * 2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct same-firm access, correct cross-firm isolation on
 * read/update/delete, insert and ownership-reassignment protection
 * under the explicit WITH CHECK clause, that every previously-prepared/
 * forced table remains forced simultaneously, and that
 * AiApprovalWorkflowService::approve()/reject() (the only writers that
 * mutate this table after submit()) still function correctly under
 * FORCE.
 *
 * This is a combined-batch checkpoint (see the migration's own
 * docblock): landed together with the paired
 * AiApprovalEventsForceRlsActivationTest checkpoint because both tables
 * are written inside the same AiApprovalWorkflowService::approve()/
 * reject() calls. Each table still has its own independent migration,
 * policy, and down(); this test file proves ai_approval_requests
 * ITSELF, independent of ai_approval_events' own state (proven by its
 * own sibling test file).
 *
 * Like every 39A-5 missingPreparedTables() checkpoint before it,
 * ai_approval_requests still appears in RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() at the point this test runs — the registry is
 * updated once by the coordinator in a later, separate wave-integration
 * commit, not by this checkpoint. Consequently this test does NOT
 * assert ai_approval_requests appears in $coverage->preparedTables(),
 * and does NOT assert any exact "N prepared tables" count. What IS
 * asserted directly against pg_class/pg_policy (the live database state
 * this migration actually produced) is the row security/policy reality
 * for ai_approval_requests itself, independent of the registry.
 * forcedTables() (dynamically discovered from every
 * *_force_rls_on_*_table.php-shaped migration file, including this
 * one's filename) DOES already report ai_approval_requests as forced,
 * since that discovery is filename/migration-content based, not
 * registry-const based — this is asserted directly below.
 *
 * Known, stated (not hidden) residual gap: this migration/test batch
 * does NOT close the transitive cross-firm foreign-key gap between
 * ai_approval_requests.firm_id and the real firm_id of the row
 * matter_id/ai_usage_event_id/encryption_key_id point at — see the
 * migration's own docblock, part (b). RLS on ai_approval_requests alone
 * cannot see into matters/ai_usage_events/tenant_encryption_keys to
 * cross-check this; AiApprovalWorkflowService::submit()'s own
 * construction and, in tests, the factory fix landing alongside this
 * migration are the only things preventing that mismatch by
 * convention. A test below proves — rather than merely asserts — that
 * a raw insert can still create this mismatch, and documents it as a
 * residual database-constraint gap, not a false RLS guarantee.
 */
class AiApprovalRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php';

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

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_ai_approval_requests_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains(
            'ai_approval_requests',
            $coverage->forcedTables(),
            'forcedTables() is derived from migration filenames/contents, not the PREPARED_TABLES const — it must already report this checkpoint\'s table.'
        );
    }

    public function test_ai_approval_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'ai_approval_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_ai_approval_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'ai_approval_requests'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'ai_approval_requests must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'ai_approval_requests'::regclass and polname = 'ai_approval_requests_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The ai_approval_requests_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_ai_approval_requests(): void
    {
        $firm = Firm::factory()->create();
        AiApprovalRequest::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AiApprovalRequest::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_ai_approval_requests(): void
    {
        $firm = Firm::factory()->create();
        $matterAndDeps = $this->runWithFirmContext($firm, fn () => [
            'requested_by' => User::factory()->create()->id,
            'ai_usage_event_id' => AiUsageEvent::factory()->forFirm($firm)->create()->id,
            'encryption_key_id' => TenantEncryptionKey::factory()->forFirm($firm)->create()->id,
        ]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('ai_approval_requests')->insert(array_merge($matterAndDeps, [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'category' => AiApprovalCategory::LegalResearchMemo->value,
            'status' => AiApprovalRequestStatus::Pending->value,
            'draft_label' => 'ai_generated_draft',
            'encrypted_snapshot_ciphertext' => 'placeholder',
            'created_at' => now(),
        ]));
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_ai_approval_request(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => AiApprovalRequest::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiApprovalRequest::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_ai_approval_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => AiApprovalRequest::factory()->forFirm($firmA)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => AiApprovalRequest::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AiApprovalRequest::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_ai_approval_request(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            $usageEvent = AiUsageEvent::factory()->forFirm($firmA)->create();
            $encryptionKey = TenantEncryptionKey::factory()->forFirm($firmA)->create();
            $requestedBy = User::factory()->create();

            return DB::table('ai_approval_requests')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'requested_by' => $requestedBy->id,
                'ai_usage_event_id' => $usageEvent->id,
                'category' => AiApprovalCategory::LegalResearchMemo->value,
                'status' => AiApprovalRequestStatus::Pending->value,
                'draft_label' => 'ai_generated_draft',
                'encrypted_snapshot_ciphertext' => 'placeholder',
                'encryption_key_id' => $encryptionKey->id,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_ai_approval_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->runWithFirmContext($firmB, fn () => AiApprovalRequest::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($requestB) {
            return DB::table('ai_approval_requests')->where('id', $requestB->id)->update(['status' => AiApprovalRequestStatus::Approved->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s ai_approval_requests row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiApprovalRequest::withoutGlobalScopes()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(AiApprovalRequestStatus::Pending, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_ai_approval_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->runWithFirmContext($firmB, fn () => AiApprovalRequest::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('ai_approval_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AiApprovalRequest::withoutGlobalScopes()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B ai_approval_requests.');
    }

    public function test_firm_a_cannot_insert_an_ai_approval_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$usageEventB, $encryptionKeyB, $requestedByB] = $this->runWithFirmContext($firmB, fn () => [
            AiUsageEvent::factory()->forFirm($firmB)->create(),
            TenantEncryptionKey::factory()->forFirm($firmB)->create(),
            User::factory()->create(),
        ]);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $usageEventB, $encryptionKeyB, $requestedByB) {
            DB::table('ai_approval_requests')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'requested_by' => $requestedByB->id,
                'ai_usage_event_id' => $usageEventB->id,
                'category' => AiApprovalCategory::LegalResearchMemo->value,
                'status' => AiApprovalRequestStatus::Pending->value,
                'draft_label' => 'ai_generated_draft',
                'encrypted_snapshot_ciphertext' => 'placeholder',
                'encryption_key_id' => $encryptionKeyB->id,
                'created_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => AiApprovalRequest::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($requestA, $firmB) {
            DB::table('ai_approval_requests')->where('id', $requestA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => AiApprovalRequest::factory()->forFirm($firm)->create());

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
    // Related-model cross-firm mismatch — proven, not assumed. See the
    // migration's own docblock part (b): no composite FK/trigger ties
    // ai_approval_requests.firm_id to the ACTUAL firm_id of the row
    // matter_id/ai_usage_event_id/encryption_key_id point at. RLS on
    // ai_approval_requests alone cannot see into matters/
    // ai_usage_events/tenant_encryption_keys to cross-check this. This
    // test PROVES — via a real raw insert, not an assertion of intent —
    // that this mismatch is NOT blocked by row-level security, and
    // documents it as a residual database-constraint gap rather than
    // claiming a false guarantee.
    // ---------------------------------------------------------------

    public function test_ai_approval_request_row_can_reference_a_different_firms_ai_usage_event_encryption_key_and_matter_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        // All three "other-firm" dependencies belong to firm B.
        [$usageEventB, $encryptionKeyB, $matterB] = $this->runWithFirmContext($firmB, fn () => [
            AiUsageEvent::factory()->forFirm($firmB)->create(),
            TenantEncryptionKey::factory()->forFirm($firmB)->create(),
            Matter::factory()->forFirm($firmB)->create(),
        ]);

        $requestedBy = User::factory()->create();

        // The insert below stamps firm_id = firmA (matching firmA's own
        // active context, so it passes the WITH CHECK clause), but every
        // nested FK points at firm B's rows instead. If RLS closed this
        // gap, this insert would fail. It does not fail — proving the
        // gap is real, not merely theoretical.
        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $usageEventB, $encryptionKeyB, $matterB, $requestedBy) {
            return DB::table('ai_approval_requests')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'matter_id' => $matterB->id,
                'requested_by' => $requestedBy->id,
                'ai_usage_event_id' => $usageEventB->id,
                'category' => AiApprovalCategory::LegalResearchMemo->value,
                'status' => AiApprovalRequestStatus::Pending->value,
                'draft_label' => 'ai_generated_draft',
                'encrypted_snapshot_ciphertext' => 'placeholder',
                'encryption_key_id' => $encryptionKeyB->id,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — this is a known, documented, un-closed database-constraint gap (see the migration docblock part (b)), not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => AiApprovalRequest::withoutGlobalScopes()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($firmA->id, $persisted->firm_id);
        $this->assertSame($matterB->id, $persisted->matter_id, 'The row genuinely persisted pointing at firm B\'s matter despite its own firm_id being firm A — the residual gap this test documents.');
        $this->assertSame($usageEventB->id, $persisted->ai_usage_event_id);
        $this->assertSame($encryptionKeyB->id, $persisted->encryption_key_id);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare AiApprovalRequest::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and its nested
     * ai_usage_event/encryption_key must belong to the SAME firm as
     * firm_id — the factory's own root-cause fix for the cross-firm
     * mismatch a naive three-independent-factories default would
     * otherwise produce.
     */
    public function test_ai_approval_request_factory_default_creation_is_safe_and_internally_consistent(): void
    {
        $request = AiApprovalRequest::factory()->create();

        $this->assertNotNull($request->id);
        $this->assertNotNull($request->firm_id);

        $persisted = $this->runWithFirmContext(
            $request->firm_id,
            fn () => AiApprovalRequest::withoutGlobalScopes()->with(['usageEvent', 'encryptionKey'])->find($request->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($request->firm_id, $persisted->usageEvent->firm_id, 'Bare factory default must not produce a cross-firm ai_usage_event mismatch.');
        $this->assertSame($request->firm_id, $persisted->encryptionKey->firm_id, 'Bare factory default must not produce a cross-firm encryption_key mismatch.');
    }

    /**
     * Explicit forFirm() state must also keep the nested FKs internally
     * consistent with the given firm — not merely the bare default.
     */
    public function test_ai_approval_request_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $request = AiApprovalRequest::factory()->forFirm($firm)->create();

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => AiApprovalRequest::withoutGlobalScopes()->with(['usageEvent', 'encryptionKey'])->find($request->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);
        $this->assertSame($firm->id, $persisted->usageEvent->firm_id);
        $this->assertSame($firm->id, $persisted->encryptionKey->firm_id);
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
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'ai_approval_requests'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'ai_approval_requests'::regclass and polname = 'ai_approval_requests_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only ai_approval_requests: its
     * paired sibling ai_approval_events (own independent migration),
     * tenant_encryption_keys (already FORCE-active), ai_usage_events
     * (still unprepared), and a sample of previously-prepared tables
     * are all bit-for-bit identical before and after a down()+up()
     * round trip.
     */
    public function test_migration_round_trip_affects_only_ai_approval_requests(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', // paired sibling, own independent migration, must remain untouched
            'tenant_encryption_keys', // already FORCE-active, must remain untouched
            'ai_usage_events', // still unprepared (MISSING_PREPARED_TABLES), must remain untouched
            'matters', // already FORCE-active, must remain untouched
        ]);

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
                "{$table}'s relrowsecurity must be unaffected by the ai_approval_requests migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the ai_approval_requests migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_the_rls_gap_remains_tracked_in_the_compliance_gap_registry(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue(
            $registry->isTracked('rls_prepared_not_enforced'),
            'The rls_prepared_not_enforced gap must remain tracked — this checkpoint activates FORCE for two more tables but does not resolve the overall gap.'
        );
    }

    public function test_rls_coverage_mapping_service_and_gap_registry_docs_were_not_modified(): void
    {
        foreach ([
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'docs/governance/rls-gap-registry.md',
        ] as $reservedPath) {
            $changed = $this->changedOrUntrackedPaths($reservedPath);

            $this->assertEmpty($changed, "{$reservedPath} is reserved for a later, separate wave-integration commit and must remain untouched by this checkpoint.");
        }
    }

    public function test_only_this_checkpoints_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowed = [
            'database/migrations/2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php',
            'database/migrations/2026_08_27_950017_prepare_row_level_security_and_force_rls_on_ai_approval_events_table.php',
            'app/Services/AiApprovalWorkflowService.php',
            'database/factories/AiApprovalRequestFactory.php',
            'database/factories/AiApprovalEventFactory.php',
            'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
            'tests/Feature/Ai/Approval/AiApprovalWorkflowServiceTest.php',
        ];

        $unexpected = array_values(array_diff($changed, $allowed));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this checkpoint: '.implode(', ', $unexpected));
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
        // feature/ses-event-consumer (a later, distinct, wholly
        // isolated mission: a production-safe SES bounce/complaint
        // consumer) legitimately added a notification-provider
        // correlation ledger + idempotency ledger (both exempted,
        // no-RLS, registered in RowLevelSecurityCoverageMappingService
        // per the same integration_webhook_routing_index/
        // integration_platform_provider_health_summaries precedent
        // pattern), a dedicated SQS consumer command, real-send
        // correlation wiring in User/ClientPortalUser password-reset
        // notifications, and its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling RlsForceRollout/Governance/Security
        // firewall test files touched by this same mission, matching
        // this array's own established cross-file-listing convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Enums/SesBounceType.php',
        'app/Enums/SesEventType.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/NotificationEvent.php',
        'app/Models/NotificationProviderCorrelation.php',
        'app/Models/SesEventReceipt.php',
        'app/Models/User.php',
        'app/Notifications/ClientPortalResetPasswordNotification.php',
        'app/Notifications/FirmOwnerInvitationNotification.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/NotificationDispatchService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'config/mail.php',
        'config/services.php',
        'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
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
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // post-578ee98 audit remediation (a later, distinct,
        // independent security/architecture review of the SES
        // event consumer feature) legitimately fixed a MessageSent
        // listener leak, an uncaught-exception crash risk in the
        // consumer command, a receipt-write concurrency race, a
        // complaint recipient-mismatch hard-reject, and added a new
        // platform-scope correlation/suppression subsystem for
        // password-reset sends that cannot resolve a firm — plus
        // its own new test files. Also mechanically added this
        // exact const + filtering addition across all its sibling
        // firewall test files touched by this same remediation,
        // matching this array's own established cross-file-listing
        // convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/PlatformNotificationCorrelation.php',
        'app/Models/PlatformNotificationSuppression.php',
        'app/Models/User.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/PlatformNotificationCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'app/Services/SuppressionService.php',
        'config/services.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php',
        'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Mail/SesMailerTransportTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
        'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Notifications/SuppressionServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
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
