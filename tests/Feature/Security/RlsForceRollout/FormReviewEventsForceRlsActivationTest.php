<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\FormDraft;
use App\Models\FormReviewEvent;
use App\Models\Matter;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FormReviewEventsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for form_review_events (database/migrations/
 * 2026_08_27_950032_prepare_row_level_security_and_force_rls_on_form_review_events_table.php)
 * is permanently active and behaves correctly.
 *
 * Fourth of the six-table, one-batch Section 39A-6 Wave 6 activation —
 * see GeneratedDocumentsForceRlsActivationTest's own docblock for the
 * full combined-batch rationale.
 *
 * Structurally identical to GeneratedDocumentEventsForceRlsActivationTest:
 * FormReviewEvent deliberately does NOT use BelongsToTenant, so a plain
 * query() (no withoutGlobalScopes()) is used throughout below.
 *
 * Append-only enforcement is proven separately in this file's own
 * companion test, tests/Feature/Forms/Review/FormReviewEventAppendOnlyTest.php.
 */
class FormReviewEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950032_prepare_row_level_security_and_force_rls_on_form_review_events_table.php';

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

    public function test_form_review_events_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('form_review_events', $coverage->forcedTables());
    }

    public function test_form_review_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'form_review_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_form_review_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'form_review_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'form_review_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'form_review_events'::regclass and polname = 'form_review_events_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The form_review_events_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_form_review_events(): void
    {
        $firm = Firm::factory()->create();
        $this->createEventForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, FormReviewEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_write_form_review_events(): void
    {
        $firm = Firm::factory()->create();
        $draft = $this->createDraftForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('form_review_events')->insert($this->rowAttributes($firm, $draft));
    }

    /**
     * FormReviewEventFactory DID gain a context-hold create() override
     * in this batch — its bare default-creation path is already
     * tenant-consistent (firm_id is derived directly from the created
     * FormDraft's own firm_id), so a bare
     * FormReviewEvent::factory()->create() must now SUCCEED even with
     * no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $event = FormReviewEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);

        $persisted = $this->runWithFirmContext(
            $event->firm_id,
            fn () => FormReviewEvent::query()->with('formDraft')->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $event->firm_id,
            $persisted->formDraft->firm_id,
            'Bare factory default must not produce a cross-firm form_draft_id mismatch.'
        );
    }

    public function test_firm_a_context_can_read_its_own_form_review_events(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FormReviewEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_form_review_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createEventForFirm($firmA);
        $eventB = $this->createEventForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FormReviewEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_form_review_event_row(): void
    {
        $firmA = Firm::factory()->create();
        $draftA = $this->createDraftForFirm($firmA);

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('form_review_events')->insertGetId($this->rowAttributes($firmA, $draftA)),
        );

        $this->assertIsInt($insertedId);
    }

    /**
     * Raw query-builder update (bypassing Eloquent, whose own booted()
     * guard would throw first regardless — see the companion
     * FormReviewEventAppendOnlyTest for that proof) isolates this test
     * to what RLS alone denies for a cross-firm update.
     */
    public function test_firm_a_cannot_update_firm_b_form_review_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($eventB) {
            return DB::table('form_review_events')->where('id', $eventB->id)->update(['notes' => 'attempted cross-firm edit']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s form_review_events row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FormReviewEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNull($reReadAsFirmB->notes);
    }

    public function test_firm_a_cannot_delete_firm_b_form_review_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('form_review_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FormReviewEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B form_review_events.');
    }

    public function test_firm_a_cannot_insert_a_form_review_event_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $draftB = $this->createDraftForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $draftB) {
            DB::table('form_review_events')->insert($this->rowAttributes($firmB, $draftB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($eventA, $firmB) {
            DB::table('form_review_events')->where('id', $eventA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    public function test_form_review_event_row_can_reference_a_different_firms_form_draft_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $draftB = $this->createDraftForFirm($firmB);

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $draftB) {
            return DB::table('form_review_events')->insertGetId($this->rowAttributes($firmA, $draftB));
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => FormReviewEvent::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($draftB->id, $persisted->form_draft_id, 'The row genuinely persisted pointing at firm B\'s own form_drafts row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createEventForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'form_review_events'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'form_review_events'::regclass and polname = 'form_review_events_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_form_review_events(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'generated_documents';
        $otherTables[] = 'form_drafts';
        $otherTables[] = 'generated_document_events';
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
                "{$table}'s relrowsecurity must be unaffected by the form_review_events migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the form_review_events migration round trip."
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

    private function createDraftForFirm(Firm $firm): FormDraft
    {
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext($firm, fn () => FormDraft::factory()->forFirmAndMatter($firm, $matter)->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEventForFirm(Firm $firm, array $overrides = []): FormReviewEvent
    {
        $draft = $this->createDraftForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        return $this->runWithFirmContext($firm, fn () => FormReviewEvent::factory()->create(array_merge([
            'firm_id' => $firm->id,
            'form_draft_id' => $draft->id,
        ], $overrides)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, FormDraft $draft): array
    {
        $actor = $this->runWithFirmContext($firm, fn () => \App\Models\FirmUser::factory()->create(['firm_id' => $firm->id]));

        return [
            'firm_id' => $firm->id,
            'form_draft_id' => $draft->id,
            'event_type' => 'marked_ready_for_review',
            'actor_firm_user_id' => $actor->id,
            'notes' => null,
            'created_at' => now(),
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
