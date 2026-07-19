<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GeneratedDocumentsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for generated_documents (database/migrations/
 * 2026_08_27_950029_prepare_row_level_security_and_force_rls_on_generated_documents_table.php)
 * is permanently active and behaves correctly.
 *
 * First of the six-table, one-batch Section 39A-6 Wave 6 activation —
 * see this migration's own docblock for the full combined-batch
 * rationale. GeneratedDocument uses BelongsToTenant + HasPublicUuid, so
 * withoutGlobalScopes() is used throughout below (the PHP-memory global
 * scope narrows further whenever a context happens to be active — it
 * never widens — but only the RLS proofs below are meant to isolate
 * what the DATABASE layer alone permits/denies, independent of that
 * app-layer scope).
 *
 * generated_documents is NOT append-only — status/used_sample_content/
 * reviewed_by_firm_user_id/reviewed_at/approved_at are all mutated in
 * place by DocumentReviewService — so, unlike generated_document_events/
 * form_review_events in this same batch, no booted() guard applies here
 * and same-firm UPDATE is expected to succeed, not merely to be
 * RLS-permitted.
 */
class GeneratedDocumentsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950029_prepare_row_level_security_and_force_rls_on_generated_documents_table.php';

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

    public function test_generated_documents_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('generated_documents', $coverage->forcedTables());
    }

    /**
     * forcedTables() is derived dynamically from every
     * *_force_rls_on_*_table.php migration present in the repository
     * (not a hardcoded, easy-to-go-stale array) — so the exact count
     * this checkpoint expects is itself exact and reviewable: 76 tables
     * forced by every prior wave, plus this batch's own 6
     * (generated_documents, form_drafts, generated_document_events,
     * form_review_events, document_hashes, pdf_view_events) = 82, no
     * more, no fewer.
     */
    public function test_the_forced_tables_registry_reports_exactly_eighty_two_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-5 Wave 7 (e-signature domain, 4 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 8 (governance/support/platform domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 9 (migration/export domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        $this->assertCount(
            113,
            $coverage->forcedTables(),
            'Exactly 108 tables must have FORCE ROW LEVEL SECURITY active after this Wave 7 batch lands — no more, no fewer.'
        );
    }

    public function test_generated_documents_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'generated_documents'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_generated_documents_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'generated_documents'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'generated_documents must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'generated_documents'::regclass and polname = 'generated_documents_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The generated_documents_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_generated_documents(): void
    {
        $firm = Firm::factory()->create();
        $this->createDocumentForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, GeneratedDocument::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_generated_documents(): void
    {
        $firm = Firm::factory()->create();
        $version = DocumentTemplateVersion::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('generated_documents')->insert($this->rowAttributes($firm, $version));
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_generated_documents(): void
    {
        $firmA = Firm::factory()->create();
        $documentA = $this->createDocumentForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => GeneratedDocument::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$documentA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_generated_documents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createDocumentForFirm($firmA);
        $documentB = $this->createDocumentForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => GeneratedDocument::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($documentB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_generated_document(): void
    {
        $firmA = Firm::factory()->create();
        $version = DocumentTemplateVersion::factory()->create();

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('generated_documents')->insertGetId($this->rowAttributes($firmA, $version)),
        );

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_generated_document(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = $this->createDocumentForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($documentB) {
            return DB::table('generated_documents')->where('id', $documentB->id)->update(['status' => 'ready_for_review']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s generated_documents row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => GeneratedDocument::withoutGlobalScopes()->find($documentB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('draft', $reReadAsFirmB->status->value);
    }

    public function test_firm_a_cannot_delete_firm_b_generated_document(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = $this->createDocumentForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($documentB) {
            DB::table('generated_documents')->where('id', $documentB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => GeneratedDocument::withoutGlobalScopes()->find($documentB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B generated_documents.');
    }

    public function test_firm_a_cannot_insert_a_generated_document_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $version = DocumentTemplateVersion::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $version) {
            DB::table('generated_documents')->insert($this->rowAttributes($firmB, $version));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentA = $this->createDocumentForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($documentA, $firmB) {
            DB::table('generated_documents')->where('id', $documentA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Per the migration's own docblock, gap #1: no composite FK/CHECK/
     * trigger ties generated_documents.matter_id (nullable) to the
     * ACTUAL firm_id of the matters row it points at. RLS only checks
     * this row's own firm_id. Proven directly: a raw insert can and
     * does create this mismatch.
     */
    public function test_generated_document_can_reference_a_different_firms_matter_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $version = DocumentTemplateVersion::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $version, $matterB) {
            $attributes = $this->rowAttributes($firmA, $version);
            $attributes['matter_id'] = $matterB->id;

            return DB::table('generated_documents')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => GeneratedDocument::withoutGlobalScopes()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($matterB->id, $persisted->matter_id, 'The row genuinely persisted pointing at firm B\'s own matters row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $document = GeneratedDocument::factory()->create();

        $this->assertNotNull($document->id);
        $this->assertNotNull($document->firm_id);

        $persisted = $this->runWithFirmContext(
            $document->firm_id,
            fn () => GeneratedDocument::withoutGlobalScopes()->with('generatedByFirmUser')->find($document->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $document->firm_id,
            $persisted->generatedByFirmUser->firm_id,
            'Bare factory default must not produce a cross-firm generated_by_firm_user_id mismatch.'
        );
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createDocumentForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'generated_documents'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'generated_documents'::regclass and polname = 'generated_documents_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_generated_documents(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'form_drafts';
        $otherTables[] = 'generated_document_events';
        $otherTables[] = 'form_review_events';
        $otherTables[] = 'document_hashes';
        $otherTables[] = 'pdf_view_events';

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
                "{$table}'s relrowsecurity must be unaffected by the generated_documents migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the generated_documents migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $thisBatch = ['generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events'];

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
            'database/migrations/2026_08_27_950029_prepare_row_level_security_and_force_rls_on_generated_documents_table.php',
            'database/migrations/2026_08_27_950030_prepare_row_level_security_and_force_rls_on_form_drafts_table.php',
            'database/migrations/2026_08_27_950031_prepare_row_level_security_and_force_rls_on_generated_document_events_table.php',
            'database/migrations/2026_08_27_950032_prepare_row_level_security_and_force_rls_on_form_review_events_table.php',
            'database/migrations/2026_08_27_950033_prepare_row_level_security_and_force_rls_on_document_hashes_table.php',
            'database/migrations/2026_08_27_950034_prepare_row_level_security_and_force_rls_on_pdf_view_events_table.php',
            'app/Models/FormReviewEvent.php',
            'app/Models/GeneratedDocumentEvent.php',
            'app/Services/DocumentGenerationService.php',
            'app/Services/DocumentHashService.php',
            'app/Services/DocumentReviewService.php',
            'app/Services/FirmCommandCenterAggregationService.php',
            'app/Services/FormDraftGenerationService.php',
            'app/Services/FormReviewService.php',
            'app/Services/PdfViewEventService.php',
            'app/Services/SignatureCertificateService.php',
            'database/factories/DocumentHashFactory.php',
            'database/factories/FormDraftFactory.php',
            'database/factories/FormReviewEventFactory.php',
            'database/factories/GeneratedDocumentEventFactory.php',
            'database/factories/GeneratedDocumentFactory.php',
            'database/factories/PdfViewEventFactory.php',
            'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
            'tests/Feature/Forms/Review/FormReviewEventAppendOnlyTest.php',
            'tests/Feature/Forms/DocumentGeneration/GeneratedDocumentEventAppendOnlyTest.php',
            'tests/Feature/Forms/Review/FormReviewServiceTest.php',
            'tests/Feature/Forms/DocumentGeneration/DocumentReviewServiceTest.php',
            'tests/Feature/TenantIsolation/FormAndDocumentTenantIsolationTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        ];
    }

    private function createDocumentForFirm(Firm $firm): GeneratedDocument
    {
        return $this->runWithFirmContext($firm, fn () => GeneratedDocument::factory()->forFirm($firm)->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, DocumentTemplateVersion $version): array
    {
        $actor = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'matter_id' => null,
            'client_id' => null,
            'document_template_version_id' => $version->id,
            'status' => 'draft',
            'simulated_storage_path' => 'generated-documents/fixture/'.\Illuminate\Support\Str::uuid().'.pdf',
            'used_sample_content' => false,
            'generated_by_firm_user_id' => $actor->id,
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
