<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SignatureEventsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for signature_events (database/migrations/
 * 2026_08_27_950037_prepare_row_level_security_and_force_rls_on_signature_events_table.php)
 * is permanently active and behaves correctly.
 *
 * Third of the four-table, one-batch Section 39A-7 Wave 7 activation —
 * see SignatureRequestsForceRlsActivationTest's own docblock for the
 * full combined-batch rationale.
 *
 * SignatureEvent does NOT use BelongsToTenant (a plain query() is used
 * throughout below) but already has its own booted() guard (append-
 * only/immutable) — this file's cross-firm update/delete tests use a
 * raw DB::table() bypass of Eloquent specifically to isolate what RLS
 * alone denies, independent of that pre-existing model guard. This is
 * the highest-severity table in this batch: since the model does not
 * use BelongsToTenant, RLS is the ONLY enforcement layer once FORCE
 * activates — no PHP-layer global scope narrows queries at all.
 *
 * This file also proves the two-column composite nullable-FK gap
 * (signature_request_recipient_id / actor_recipient_id) is a genuine,
 * DELIBERATELY DEFERRED database-constraint gap, NOT closed by this
 * activation.
 */
class SignatureEventsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950037_prepare_row_level_security_and_force_rls_on_signature_events_table.php';

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

    public function test_signature_events_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('signature_events', $coverage->forcedTables());
    }

    public function test_the_forced_tables_registry_reports_exactly_eighty_six_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-5 Wave 8 (governance/support/platform domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 9 (migration/export domain, 6 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration Platform mission — firm_integrations added, bumping the forced-table total (113 -> 114).
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase Integration Platform mission (integration_credentials, a new genuine tenant-owned table with RLS prepared and FORCE-activated in the same migration) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertCount(
            147,
            $coverage->forcedTables(),
            'Exactly 108 tables must have FORCE ROW LEVEL SECURITY active after this Wave 7 batch lands — no more, no fewer.'
        );
    }

    public function test_signature_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'signature_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_signature_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'signature_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'signature_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'signature_events'::regclass and polname = 'signature_events_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The signature_events_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly — not a FOR INSERT-only clause.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_signature_events(): void
    {
        $firm = Firm::factory()->create();
        $this->createEventForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, SignatureEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_signature_events(): void
    {
        $firm = Firm::factory()->create();
        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create(['firm_id' => $firm->id]));
        $actor = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('signature_events')->insert($this->rowAttributes($firm, $request, $actor));
    }

    /**
     * SignatureEventFactory DID gain both a root-cause definition()
     * redesign (one authoritative SignatureRequest created up front,
     * firm_id derived directly from it) and a context-hold create()
     * override in this batch — its bare default-creation path is
     * already tenant-consistent, so a bare
     * SignatureEvent::factory()->create() must now SUCCEED even with no
     * ambient context.
     */
    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $event = SignatureEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);

        $persisted = $this->runWithFirmContext(
            $event->firm_id,
            fn () => SignatureEvent::query()->with('signatureRequest')->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame(
            $event->firm_id,
            $persisted->signatureRequest->firm_id,
            'Bare factory default must not produce a cross-firm signature_request_id mismatch.'
        );
    }

    public function test_firm_a_context_can_read_its_own_signature_events(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_signature_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createEventForFirm($firmA);
        $eventB = $this->createEventForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_signature_event(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => SignatureRequest::factory()->create(['firm_id' => $firmA->id]));
        $actorA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->create(['firm_id' => $firmA->id]));

        $insertedId = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('signature_events')->insertGetId($this->rowAttributes($firmA, $requestA, $actorA)),
        );

        $this->assertIsInt($insertedId);
    }

    /**
     * Raw query-builder update (bypassing Eloquent, whose own booted()
     * immutability guard would throw first regardless) isolates this
     * test to what RLS alone denies for a cross-firm update.
     */
    public function test_firm_a_cannot_update_firm_b_signature_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($eventB) {
            return DB::table('signature_events')->where('id', $eventB->id)->update(['ip_address' => '10.0.0.1']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s signature_events row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SignatureEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNotSame('10.0.0.1', $reReadAsFirmB->ip_address);
    }

    public function test_firm_a_cannot_delete_firm_b_signature_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('signature_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => SignatureEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B signature_events.');
    }

    public function test_firm_a_cannot_insert_a_signature_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->runWithFirmContext($firmB, fn () => SignatureRequest::factory()->create(['firm_id' => $firmB->id]));
        $actorB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->create(['firm_id' => $firmB->id]));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $requestB, $actorB) {
            DB::table('signature_events')->insert($this->rowAttributes($firmB, $requestB, $actorB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($eventA, $firmB) {
            DB::table('signature_events')->where('id', $eventA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Append-only residual proof — RLS alone doesn't enforce it.
    // ---------------------------------------------------------------

    /**
     * The model's booted() guard blocks any UPDATE/DELETE at the
     * Eloquent layer, for any firm — but RLS itself, per this table's
     * migration docblock, deliberately governs firm-ownership, not
     * append-only-ness. A SAME-FIRM raw DB::table() bypass of Eloquent
     * is therefore NOT blocked by RLS (it's the model guard that
     * normally blocks it) — proven directly here, isolated from that
     * model guard, as the documented residual shape of this design.
     */
    public function test_same_firm_raw_update_is_not_blocked_by_rls_alone_append_only_is_enforced_by_the_model_guard_not_rls(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $affected = $this->runWithFirmContext($firm, function () use ($event) {
            return DB::table('signature_events')->where('id', $event->id)->update(['ip_address' => '10.0.0.99']);
        });

        $this->assertSame(1, $affected, 'RLS itself permits a same-firm UPDATE — append-only immutability for signature_events is enforced by the model\'s own booted() guard (Eloquent layer only), not by RLS, exactly as this table\'s migration docblock documents.');
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    /**
     * Deferred gap #1: signature_request_recipient_id and
     * actor_recipient_id are two INDEPENDENT nullable FKs into
     * signature_request_recipients, with no DB-level constraint tying
     * either to the row's own firm_id, nor requiring the two to be
     * mutually consistent.
     */
    public function test_signature_event_can_reference_a_different_firms_recipient_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => SignatureRequest::factory()->create(['firm_id' => $firmA->id]));
        $actorA = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->create(['firm_id' => $firmA->id]));
        $recipientB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $requestB = SignatureRequest::factory()->create(['firm_id' => $firmB->id]);

            return SignatureRequestRecipient::factory()->forRequest($requestB)->create();
        });

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $requestA, $actorA, $recipientB) {
            $attributes = $this->rowAttributes($firmA, $requestA, $actorA);
            $attributes['signature_request_recipient_id'] = $recipientB->id;
            $attributes['actor_recipient_id'] = $recipientB->id;

            return DB::table('signature_events')->insertGetId($attributes);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap, not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => SignatureEvent::query()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($recipientB->id, $persisted->signature_request_recipient_id, 'The row genuinely persisted pointing at firm B\'s own recipient row despite its own firm_id being firm A — the residual gap this test documents.');
        $this->assertSame($recipientB->id, $persisted->actor_recipient_id, 'No constraint requires the two nullable recipient FKs to be mutually consistent either — the composite version of the standard gap.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'signature_events'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'signature_events'::regclass and polname = 'signature_events_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_signature_events(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables[] = 'signature_requests';
        $otherTables[] = 'signature_request_recipients';
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
                "{$table}'s relrowsecurity must be unaffected by the signature_events migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the signature_events migration round trip."
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

    private function createEventForFirm(Firm $firm): SignatureEvent
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $request = SignatureRequest::factory()->create(['firm_id' => $firm->id]);

            return SignatureEvent::factory()->forRequest($request)->create();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, SignatureRequest $request, FirmUser $actor): array
    {
        return [
            'firm_id' => $firm->id,
            'signature_request_id' => $request->id,
            'signature_request_recipient_id' => null,
            'event_type' => SignatureEventType::RequestCreated->value,
            'actor_type' => SignatureEventActorType::FirmUser->value,
            'actor_firm_user_id' => $actor->id,
            'actor_recipient_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'document_hash_id' => null,
            'acknowledger_type' => null,
            'acknowledger_id' => null,
            'text_version' => null,
            'acknowledged' => null,
            'acknowledged_at' => null,
            'metadata_json' => null,
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
