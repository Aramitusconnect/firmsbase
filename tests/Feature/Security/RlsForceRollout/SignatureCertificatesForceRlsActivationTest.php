<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\SignatureCertificateStatus;
use App\Models\DocumentHash;
use App\Models\Firm;
use App\Models\SignatureCertificate;
use App\Models\SignatureRequest;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SignatureCertificatesForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for signature_certificates (database/
 * migrations/2026_08_27_950038_prepare_row_level_security_and_force_rls_on_signature_certificates_table.php)
 * is permanently active and behaves correctly.
 *
 * Fourth and last of the four-table, one-batch Section 39A-7 Wave 7
 * activation — see SignatureRequestsForceRlsActivationTest's own
 * docblock for the full combined-batch rationale.
 *
 * SignatureCertificate uses BelongsToTenant + HasPublicUuid — a plain
 * query() is used throughout below. It also already has its own
 * booted() guard (immutable after generation) — this file's cross-firm
 * update/delete tests use a raw DB::table() bypass of Eloquent
 * specifically to isolate what RLS alone denies, independent of that
 * pre-existing model guard.
 *
 * signature_request_id is UNIQUE + restrictOnDelete() — a genuine,
 * DB-enforced one-certificate-per-request guarantee (a CLOSED gap, not
 * deferred). This table has NO *_by_firm_user_id/actor column at all
 * (certificates are system-generated only) — it does NOT have the
 * actor-attribution gap class the other three tables in this batch
 * have.
 */
class SignatureCertificatesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950038_prepare_row_level_security_and_force_rls_on_signature_certificates_table.php';

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

    public function test_signature_certificates_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('signature_certificates', $coverage->forcedTables());
    }

    /**
     * This is the fourth and last table of the batch — the exact total
     * count assertion belongs here as the final proof that all 4 landed
     * and no more, no fewer.
     */
    public function test_the_forced_tables_registry_reports_exactly_eighty_six_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-5 Wave 8 (governance/support/platform domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 9 (migration/export domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        $this->assertCount(
            108,
            $coverage->forcedTables(),
            'Exactly 108 tables must have FORCE ROW LEVEL SECURITY active once this final checkpoint of the Wave 7 batch lands — no more, no fewer.'
        );
    }

    public function test_signature_certificates_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'signature_certificates'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_signature_certificates_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'signature_certificates'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'signature_certificates must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'signature_certificates'::regclass and polname = 'signature_certificates_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The signature_certificates_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_signature_certificates(): void
    {
        $firm = Firm::factory()->create();
        $this->createCertificateForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, SignatureCertificate::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_signature_certificates(): void
    {
        $firm = Firm::factory()->create();
        [$request, $hash] = $this->runWithFirmContext($firm, function () use ($firm) {
            $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);
            $hash = DocumentHash::factory()->create(['firm_id' => $firm->id]);

            return [$request, $hash];
        });

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('signature_certificates')->insert($this->rowAttributes($firm, $request, $hash));
    }

    /**
     * SignatureCertificateFactory DID gain both a root-cause
     * definition() redesign (one authoritative SignatureRequest created
     * up front, firm_id derived directly from it) and a context-hold
     * create() override in this batch — its bare default-creation path
     * is already tenant-consistent, so a bare
     * SignatureCertificate::factory()->create() must now SUCCEED even
     * with no ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $certificate = SignatureCertificate::factory()->create();

        $this->assertNotNull($certificate->id);
        $this->assertNotNull($certificate->firm_id);

        $persisted = $this->runWithFirmContext(
            $certificate->firm_id,
            fn () => SignatureCertificate::query()->with('signatureRequest')->find($certificate->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $certificate->firm_id,
            $persisted->signatureRequest->firm_id,
            'Bare factory default must not produce a cross-firm signature_request_id mismatch.'
        );
    }

    public function test_firm_a_context_can_read_its_own_signature_certificates(): void
    {
        $firmA = Firm::factory()->create();
        $certificateA = $this->createCertificateForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureCertificate::query()->pluck('id')->all(),
        );

        $this->assertSame([$certificateA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_signature_certificates(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createCertificateForFirm($firmA);
        $certificateB = $this->createCertificateForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureCertificate::query()->pluck('id')->all(),
        );

        $this->assertNotContains($certificateB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_signature_certificate(): void
    {
        $firmA = Firm::factory()->create();
        [$requestA, $hashA] = $this->runWithFirmContext($firmA, function () use ($firmA) {
            $request = SignatureRequest::factory()->create(['firm_id' => $firmA->id]);
            $hash = DocumentHash::factory()->create(['firm_id' => $firmA->id]);

            return [$request, $hash];
        });

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('signature_certificates')->insertGetId($this->rowAttributes($firmA, $requestA, $hashA)),
        );

        $this->assertIsInt($insertedId);
    }

    /**
     * Raw query-builder update (bypassing Eloquent, whose own booted()
     * immutability guard would throw first regardless) isolates this
     * test to what RLS alone denies for a cross-firm update.
     */
    public function test_firm_a_cannot_update_firm_b_signature_certificate(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $certificateB = $this->createCertificateForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($certificateB) {
            return DB::table('signature_certificates')->where('id', $certificateB->id)->update(['status' => 'generated']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s signature_certificates row.');
    }

    public function test_firm_a_cannot_delete_firm_b_signature_certificate(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $certificateB = $this->createCertificateForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($certificateB) {
            DB::table('signature_certificates')->where('id', $certificateB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SignatureCertificate::query()->find($certificateB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B signature_certificates.');
    }

    public function test_firm_a_cannot_insert_a_signature_certificate_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$requestB, $hashB] = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $request = SignatureRequest::factory()->create(['firm_id' => $firmB->id]);
            $hash = DocumentHash::factory()->create(['firm_id' => $firmB->id]);

            return [$request, $hash];
        });

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $requestB, $hashB) {
            DB::table('signature_certificates')->insert($this->rowAttributes($firmB, $requestB, $hashB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $certificateA = $this->createCertificateForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($certificateA, $firmB) {
            DB::table('signature_certificates')->where('id', $certificateA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Append-only residual proof — RLS alone doesn't enforce it.
    // ---------------------------------------------------------------

    public function test_same_firm_raw_update_is_not_blocked_by_rls_alone_immutability_is_enforced_by_the_model_guard_not_rls(): void
    {
        $firm = Firm::factory()->create();
        $certificate = $this->createCertificateForFirm($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($certificate) {
            return DB::table('signature_certificates')->where('id', $certificate->id)->update(['status' => 'generated']);
        });

        $this->assertSame(1, $affected, 'RLS itself permits a same-firm UPDATE — immutability for signature_certificates is enforced by the model\'s own booted() guard (Eloquent layer only), not by RLS, exactly as this table\'s migration docblock documents.');
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Deferred gap #1: document_hash_id (NOT NULL) -> document_hashes —
     * no composite FK/CHECK/trigger ties document_hashes.firm_id to this
     * row's own firm_id; only SignatureCertificateService::generate()'s
     * explicit 'firm_id' => $request->firm_id assignment enforces
     * agreement today.
     */
    public function test_signature_certificate_can_reference_a_different_firms_document_hash_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => SignatureRequest::factory()->create(['firm_id' => $firmA->id]));
        $hashB = $this->runWithFirmContext($firmB, fn () => DocumentHash::factory()->create(['firm_id' => $firmB->id]));

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $requestA, $hashB) {
            return DB::table('signature_certificates')->insertGetId($this->rowAttributes($firmA, $requestA, $hashB));
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureCertificate::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($hashB->id, $persisted->document_hash_id, 'The row genuinely persisted pointing at firm B\'s own document_hashes row despite its own firm_id being firm A — the residual gap this test documents.');
    }

    /**
     * The CLOSED gap, proven for contrast: signature_request_id is
     * UNIQUE at the DB level — a second certificate for the same
     * request is structurally impossible, independent of RLS or any
     * service-level pre-check.
     */
    public function test_signature_request_id_unique_constraint_blocks_a_second_certificate_for_the_same_request(): void
    {
        $firm = Firm::factory()->create();
        [$request, $hash] = $this->runWithFirmContext($firm, function () use ($firm) {
            $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);
            $hash = DocumentHash::factory()->create(['firm_id' => $firm->id]);

            return [$request, $hash];
        });

        $this->runWithFirmContext($firm, fn () => DB::table('signature_certificates')->insert($this->rowAttributes($firm, $request, $hash)));

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->runWithFirmContext($firm, fn () => DB::table('signature_certificates')->insert($this->rowAttributes($firm, $request, $hash)));
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->createCertificateForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'signature_certificates'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'signature_certificates'::regclass and polname = 'signature_certificates_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_signature_certificates(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'signature_requests';
        $otherTables[] = 'signature_request_recipients';
        $otherTables[] = 'signature_events';

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
                "{$table}'s relrowsecurity must be unaffected by the signature_certificates migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the signature_certificates migration round trip."
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

    private function createCertificateForFirm(Firm $firm): SignatureCertificate
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);
            $hash = DocumentHash::factory()->create(['firm_id' => $firm->id]);

            return SignatureCertificate::factory()->forRequest($request, $hash)->create();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, SignatureRequest $request, DocumentHash $hash): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'signature_request_id' => $request->id,
            'status' => SignatureCertificateStatus::Generated->value,
            'certificate_data_json' => json_encode(['fixture' => true]),
            'document_hash_id' => $hash->id,
            'generated_at' => now(),
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
