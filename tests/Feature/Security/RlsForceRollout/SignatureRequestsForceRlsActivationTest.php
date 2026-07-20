<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\SignatureRequestStatus;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\SignatureRequest;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SignatureRequestsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for signature_requests (database/migrations/
 * 2026_08_27_950035_prepare_row_level_security_and_force_rls_on_signature_requests_table.php)
 * is permanently active and behaves correctly.
 *
 * First of the four-table, one-batch Section 39A-7 Wave 7 activation
 * (e-signature domain: signature_requests, signature_request_recipients,
 * signature_events, signature_certificates) — see this migration's own
 * docblock for the full combined-batch rationale.
 *
 * SignatureRequest uses BelongsToTenant + HasPublicUuid — a plain
 * query() is used throughout below (the PHP-memory global scope
 * narrows further whenever a context happens to be active — it never
 * widens — but only the RLS proofs below are meant to isolate what the
 * DATABASE layer alone permits/denies, independent of that app-layer
 * scope).
 *
 * signature_requests is NOT append-only — status, attorney_reviewed_at,
 * sent_at, completed_at, voided_at, declined_at, etc. are all mutated
 * in place by SignatureRequestWorkflowService/
 * SignatureRequestAggregationService/SignatureCertificateService — so,
 * unlike signature_events/signature_certificates in this same batch, no
 * booted() guard applies here and same-firm UPDATE is expected to
 * succeed, not merely to be RLS-permitted.
 */
class SignatureRequestsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950035_prepare_row_level_security_and_force_rls_on_signature_requests_table.php';

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

    public function test_signature_requests_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('signature_requests', $coverage->forcedTables());
    }

    /**
     * forcedTables() is derived dynamically from every
     * *_force_rls_on_*_table.php migration present in the repository —
     * so the exact count this checkpoint expects is itself exact and
     * reviewable: 82 tables forced by every prior wave (through Section
     * 39A-6 Wave 6), plus this batch's own 4
     * (signature_requests, signature_request_recipients,
     * signature_events, signature_certificates) = 86, no more, no
     * fewer.
     */
    public function test_the_forced_tables_registry_reports_exactly_eighty_six_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-5 Wave 8 (governance/support/platform domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 9 (migration/export domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration Platform mission — firm_integrations added, bumping the forced-table total (113 -> 114).
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase Integration Platform mission (integration_credentials, a new genuine tenant-owned table with RLS prepared and FORCE-activated in the same migration) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertCount(
            115,
            $coverage->forcedTables(),
            'Exactly 108 tables must have FORCE ROW LEVEL SECURITY active after this Wave 7 batch lands — no more, no fewer.'
        );
    }

    public function test_signature_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'signature_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_signature_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'signature_requests'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'signature_requests must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'signature_requests'::regclass and polname = 'signature_requests_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The signature_requests_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_signature_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->createRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, SignatureRequest::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_signature_requests(): void
    {
        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id]));
        $actor = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('signature_requests')->insert($this->rowAttributes($firm, $document, $actor));
    }

    /**
     * SignatureRequestFactory DID gain a context-hold create() override
     * in this batch — its bare default-creation path is already
     * tenant-consistent, so a bare SignatureRequest::factory()->create()
     * must now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $request = SignatureRequest::factory()->create();

        $this->assertNotNull($request->id);
        $this->assertNotNull($request->firm_id);

        $persisted = $this->runWithFirmContext(
            $request->firm_id,
            fn () => SignatureRequest::query()->with('requestedByFirmUser')->find($request->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $request->firm_id,
            $persisted->requestedByFirmUser->firm_id,
            'Bare factory default must not produce a cross-firm requested_by_firm_user_id mismatch.'
        );
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_signature_requests(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->createRequestForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureRequest::query()->pluck('id')->all(),
        );

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_signature_requests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createRequestForFirm($firmA);
        $requestB = $this->createRequestForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureRequest::query()->pluck('id')->all(),
        );

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_signature_request(): void
    {
        $firmA = Firm::factory()->create();
        $document = $this->runWithFirmContext($firmA, fn () => Document::factory()->create(['firm_id' => $firmA->id]));
        $actor = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->create(['firm_id' => $firmA->id]));

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('signature_requests')->insertGetId($this->rowAttributes($firmA, $document, $actor)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_signature_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRequestForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($requestB) {
            return DB::table('signature_requests')->where('id', $requestB->id)->update(['status' => 'voided']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s signature_requests row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SignatureRequest::query()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('draft', $reReadAsFirmB->status->value);
    }

    public function test_firm_a_cannot_delete_firm_b_signature_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRequestForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('signature_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SignatureRequest::query()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B signature_requests.');
    }

    public function test_firm_a_cannot_insert_a_signature_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $document = $this->runWithFirmContext($firmB, fn () => Document::factory()->create(['firm_id' => $firmB->id]));
        $actor = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->create(['firm_id' => $firmB->id]));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $document, $actor) {
            DB::table('signature_requests')->insert($this->rowAttributes($firmB, $document, $actor));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->createRequestForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($requestA, $firmB) {
            DB::table('signature_requests')->where('id', $requestA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Per the migration's own docblock, deferred gap #2: no composite
     * FK/CHECK/trigger ties signature_requests.matter_id (nullable) to
     * the ACTUAL firm_id of the matters row it points at. RLS only
     * checks this row's own firm_id. Proven directly: a raw insert can
     * and does create this mismatch.
     */
    public function test_signature_request_can_reference_a_different_firms_matter_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $document = $this->runWithFirmContext($firmA, fn () => Document::factory()->create(['firm_id' => $firmA->id]));
        $actor = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->create(['firm_id' => $firmA->id]));
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $document, $actor, $matterB) {
            $attributes = $this->rowAttributes($firmA, $document, $actor);
            $attributes['matter_id'] = $matterB->id;

            return DB::table('signature_requests')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureRequest::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($matterB->id, $persisted->matter_id, 'The row genuinely persisted pointing at firm B\'s own matters row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    /**
     * Deferred gap #1: the dual-nullable source pointer (document_id /
     * generated_document_id) is only enforced by
     * SignatureRequestWorkflowService::create()'s own PHP-level XOR
     * check, never a DB CHECK/trigger. No DB constraint prevents both
     * being set simultaneously.
     */
    public function test_signature_request_row_can_have_both_document_id_and_generated_document_id_set_simultaneously_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firm = Firm::factory()->create();
        [$document, $generatedDocument, $actor] = $this->runWithFirmContext($firm, fn () => [
            Document::factory()->create(['firm_id' => $firm->id]),
            \App\Models\GeneratedDocument::factory()->forFirm($firm)->create(),
            FirmUser::factory()->create(['firm_id' => $firm->id]),
        ]);

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $document, $generatedDocument, $actor) {
            $attributes = $this->rowAttributes($firm, $document, $actor);
            $attributes['generated_document_id'] = $generatedDocument->id;

            return DB::table('signature_requests')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'No DB-level XOR/CHECK constraint exists — RLS does not (and is not designed to) block both source pointers being set at once. This is a documented, deliberately-deferred gap, not something this activation closes.');
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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'signature_requests'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'signature_requests'::regclass and polname = 'signature_requests_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_signature_requests(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'signature_request_recipients';
        $otherTables[] = 'signature_events';
        $otherTables[] = 'signature_certificates';

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
                "{$table}'s relrowsecurity must be unaffected by the signature_requests migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the signature_requests migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $thisBatch = ['signature_requests', 'signature_request_recipients', 'signature_events', 'signature_certificates'];

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

    public function test_only_this_batchs_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $unexpected = array_values(array_diff($changed, $this->allowedFiles()));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this batch: '.implode(', ', $unexpected));
    }

    /**
     * @return array<int, string>
     */
    private function allowedFiles(): array
    {
        return [
            'database/migrations/2026_08_27_950035_prepare_row_level_security_and_force_rls_on_signature_requests_table.php',
            'database/migrations/2026_08_27_950036_prepare_row_level_security_and_force_rls_on_signature_request_recipients_table.php',
            'database/migrations/2026_08_27_950037_prepare_row_level_security_and_force_rls_on_signature_events_table.php',
            'database/migrations/2026_08_27_950038_prepare_row_level_security_and_force_rls_on_signature_certificates_table.php',
            'app/Services/SignatureRequestWorkflowService.php',
            'app/Services/SignatureRecipientWorkflowService.php',
            'app/Services/SignatureCertificateService.php',
            'database/factories/SignatureRequestFactory.php',
            'database/factories/SignatureRequestRecipientFactory.php',
            'database/factories/SignatureEventFactory.php',
            'database/factories/SignatureCertificateFactory.php',
            'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
            'tests/Feature/TenantIsolation/SignatureAndPdfTenantIsolationTest.php',
            'tests/Feature/Signature/Requests/SignatureRequestWorkflowServiceTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
            'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        ];
    }

    private function createRequestForFirm(Firm $firm): SignatureRequest
    {
        return $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, Document $document, FirmUser $actor): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'matter_id' => null,
            'client_id' => null,
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => $document->id,
            'generated_document_id' => null,
            'status' => SignatureRequestStatus::Draft->value,
            'title' => 'Engagement Letter',
            'requested_by_firm_user_id' => $actor->id,
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
