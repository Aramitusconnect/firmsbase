<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
use App\Models\GeneratedDocument;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DocumentHashesForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for document_hashes (database/migrations/
 * 2026_08_27_950033_prepare_row_level_security_and_force_rls_on_document_hashes_table.php)
 * is permanently active and behaves correctly.
 *
 * Fifth of the six-table, one-batch Section 39A-6 Wave 6 activation —
 * see GeneratedDocumentsForceRlsActivationTest's own docblock for the
 * full combined-batch rationale.
 *
 * DocumentHash does NOT use BelongsToTenant (a plain query() is used
 * throughout below, no withoutGlobalScopes() needed) but ALREADY has
 * its own booted() guard (immutability, proven separately by
 * DocumentHashIsImmutableTest) — this file's cross-firm update/delete
 * tests below therefore use a raw DB::table() bypass of Eloquent
 * specifically to isolate what RLS alone denies, independent of that
 * pre-existing model guard.
 *
 * This file also proves the dual-nullable-parent-pointer XOR gap
 * (document_id/generated_document_id) is a genuine, DELIBERATELY
 * DEFERRED database-constraint gap, NOT closed by this activation —
 * per the migration's own docblock, no CHECK/trigger enforces exactly
 * one of the two ever being set.
 */
class DocumentHashesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950033_prepare_row_level_security_and_force_rls_on_document_hashes_table.php';

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

    public function test_document_hashes_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('document_hashes', $coverage->forcedTables());
    }

    public function test_document_hashes_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'document_hashes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_document_hashes_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'document_hashes'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'document_hashes must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'document_hashes'::regclass and polname = 'document_hashes_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The document_hashes_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_document_hashes(): void
    {
        $firm = Firm::factory()->create();
        $this->createHashForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DocumentHash::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_document_hashes(): void
    {
        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('document_hashes')->insert($this->documentRowAttributes($firm, $document));
    }

    /**
     * DocumentHashFactory DID gain a context-hold create() override in
     * this batch — its bare default-creation path is already
     * tenant-consistent, so a bare DocumentHash::factory()->create()
     * must now SUCCEED even with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $hash = DocumentHash::factory()->create();

        $this->assertNotNull($hash->id);
        $this->assertNotNull($hash->firm_id);

        $persisted = $this->runWithFirmContext(
            $hash->firm_id,
            fn () => DocumentHash::query()->with('document')->find($hash->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($hash->firm_id, $persisted->document->firm_id, 'Bare factory default must not produce a cross-firm document_id mismatch.');
    }

    public function test_firm_a_context_can_read_its_own_document_hashes(): void
    {
        $firmA = Firm::factory()->create();
        $hashA = $this->createHashForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentHash::query()->pluck('id')->all(),
        );

        $this->assertSame([$hashA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_document_hashes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createHashForFirm($firmA);
        $hashB = $this->createHashForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentHash::query()->pluck('id')->all(),
        );

        $this->assertNotContains($hashB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_document_hash(): void
    {
        $firmA = Firm::factory()->create();
        $documentA = $this->runWithFirmContext($firmA, fn () => Document::factory()->create(['firm_id' => $firmA->id]));

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('document_hashes')->insertGetId($this->documentRowAttributes($firmA, $documentA)),
        );

        $this->assertIsInt($insertedId);
    }

    /**
     * Raw query-builder update (bypassing Eloquent, whose own booted()
     * immutability guard would throw first regardless — see
     * DocumentHashIsImmutableTest) isolates this test to what RLS alone
     * denies for a cross-firm update.
     */
    public function test_firm_a_cannot_update_firm_b_document_hash(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $hashB = $this->createHashForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($hashB) {
            return DB::table('document_hashes')->where('id', $hashB->id)->update(['hash_value' => 'attempted-cross-firm-overwrite']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s document_hashes row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentHash::query()->find($hashB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNotSame('attempted-cross-firm-overwrite', $reReadAsFirmB->hash_value);
    }

    public function test_firm_a_cannot_delete_firm_b_document_hash(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $hashB = $this->createHashForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($hashB) {
            DB::table('document_hashes')->where('id', $hashB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentHash::query()->find($hashB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B document_hashes.');
    }

    public function test_firm_a_cannot_insert_a_document_hash_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = $this->runWithFirmContext($firmB, fn () => Document::factory()->create(['firm_id' => $firmB->id]));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $documentB) {
            DB::table('document_hashes')->insert($this->documentRowAttributes($firmB, $documentB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $hashA = $this->createHashForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($hashA, $firmB) {
            DB::table('document_hashes')->where('id', $hashA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    public function test_document_hash_can_reference_a_different_firms_document_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $documentB = $this->runWithFirmContext($firmB, fn () => Document::factory()->create(['firm_id' => $firmB->id]));

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $documentB) {
            return DB::table('document_hashes')->insertGetId($this->documentRowAttributes($firmA, $documentB));
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentHash::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($documentB->id, $persisted->document_id, 'The row genuinely persisted pointing at firm B\'s own documents row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    /**
     * The dual-nullable-parent-pointer XOR gap: no DB-level CHECK/
     * trigger prevents BOTH document_id and generated_document_id from
     * being set simultaneously. Only DocumentHashService's own two
     * distinct methods (recordForDocument/recordForGeneratedDocument)
     * enforce the XOR in practice — proven directly here, not assumed,
     * and explicitly NOT closed by this migration (see its own
     * docblock's deferred-gaps list).
     */
    public function test_document_hash_row_can_have_both_document_id_and_generated_document_id_set_simultaneously_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firm = Firm::factory()->create();
        [$document, $generatedDocument] = $this->runWithFirmContext($firm, fn () => [
            Document::factory()->create(['firm_id' => $firm->id]),
            GeneratedDocument::factory()->forFirm($firm)->create(),
        ]);

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $document, $generatedDocument) {
            $attributes = $this->documentRowAttributes($firm, $document);
            $attributes['generated_document_id'] = $generatedDocument->id;

            return DB::table('document_hashes')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'No DB-level XOR/CHECK constraint exists — RLS does not (and is not designed to) block both parent pointers being set at once. This is a documented, deliberately-deferred gap, not something this activation closes.');

        $persisted = $this->runWithFirmContext($firm, fn () => DocumentHash::query()->find($insertedId));

        $this->assertNotNull($persisted->document_id);
        $this->assertNotNull($persisted->generated_document_id);
    }

    /**
     * The mirror case: neither pointer set at all. Also not blocked by
     * any DB-level constraint — again, a documented, deliberately
     * deferred gap.
     */
    public function test_document_hash_row_can_have_neither_document_id_nor_generated_document_id_set_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('document_hashes')->insertGetId([
                'firm_id' => $firm->id,
                'source_document_type' => SignatureSourceDocumentType::Document->value,
                'document_id' => null,
                'generated_document_id' => null,
                'algorithm' => HashAlgorithm::Sha256->value,
                'hash_value' => hash('sha256', 'orphaned-hash-row'),
                'recorded_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId, 'No DB-level XOR/CHECK constraint exists — a row with neither parent pointer set is not blocked. This is a documented, deliberately-deferred gap, not something this activation closes.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createHashForFirm($firm);

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

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'document_hashes'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'document_hashes'::regclass and polname = 'document_hashes_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_document_hashes(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'generated_documents';
        $otherTables[] = 'form_drafts';
        $otherTables[] = 'generated_document_events';
        $otherTables[] = 'form_review_events';
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
                "{$table}'s relrowsecurity must be unaffected by the document_hashes migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the document_hashes migration round trip."
            );
        }
    }

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

        $this->assertEmpty($changed, 'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint.');
    }

    public function test_gap_registry_doc_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('docs/governance/rls-gap-registry.md');

        $this->assertEmpty($changed, 'docs/governance/rls-gap-registry.md must remain untouched by this checkpoint.');
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
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

    private function createHashForFirm(Firm $firm): DocumentHash
    {
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id]));

        return $this->runWithFirmContext($firm, fn () => DocumentHash::factory()->forDocument($document)->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRowAttributes(Firm $firm, Document $document): array
    {
        return [
            'firm_id' => $firm->id,
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => $document->id,
            'generated_document_id' => null,
            'algorithm' => HashAlgorithm::Sha256->value,
            'hash_value' => hash('sha256', (string) \Illuminate\Support\Str::uuid()),
            'recorded_at' => now(),
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
