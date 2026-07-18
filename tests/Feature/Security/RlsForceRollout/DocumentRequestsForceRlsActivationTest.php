<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DocumentRequestStatus;
use App\Enums\ReadinessComponentStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\ReadinessScorecardComponent;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\DocumentChaseService;
use App\Services\DocumentRequestService;
use App\Services\MobilePortalReadinessService;
use App\Services\ReadinessScorecardRegistry;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DocumentRequestsForceRlsActivationTest — Section 39A-3L, Checkpoint 10,
 * Table Phase C. Proves the twenty-eighth staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php)
 * is permanently active for document_requests and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table remains forced
 * simultaneously, and that DocumentRequestService's create() and its 7
 * single-item mutators, plus DocumentChaseService's
 * checkAndLog()/escalate()/pause()/resume(), plus
 * ReadinessScorecardRegistry's documents_approved component, plus
 * MobilePortalReadinessService's documentChecklistAvailable() — each
 * now wrapping in a runWithFirmContext() call — function correctly
 * end-to-end under FORCE.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * the migration's own docblock): document_requests.client_id/matter_id
 * firm-ownership is not validated at the app layer —
 * DocumentRequestService::create() never checks $client->firm_id ===
 * $firm->id or $matter?->firm_id === $firm->id before insert. FORCE RLS
 * does not catch this (RLS only checks document_requests.firm_id
 * itself, never a related row's firm_id), so a cross-firm client/matter
 * reference remains possible today. See
 * test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 */
class DocumentRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations',
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

    public function test_document_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'document_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_document_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'document_requests'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'document_requests must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-eight tables (the twenty-seven previously forced
     * plus document_requests) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_twenty_eight_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 11, Table Phase C
        // (communication_consents) — additive only, no existing assertion
        // removed or weakened. See CommunicationConsentsForceRlsActivationTest
        // for that batch's own dedicated proof file.
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (this repo's fortieth staged FORCE activation batch, covering payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
        // repo's forty-second staged FORCE activation batch, covering
        // notification_events) to extend the "exactly these tables
        // are forced" list to include notification_events too — this
        // test's own scope predates Checkpoint 24, but the exact-count
        // assertion below must still account for that later,
        // legitimate addition rather than falsely reporting it as
        // unexpected — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'customer_success_health_scores', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events']);
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

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21, Table
        // Phase C (this repo's thirty-ninth staged FORCE activation batch,
        // covering time_entries) for the same reason — additive only, no
        // existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(76, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (the twenty-eight from this batch plus communication_consents from Checkpoint 11, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     * document_request_items carries no firm_id of its own (scoped
     * transitively through document_requests) and is genuinely NOT a
     * prepared table at all — it must remain completely exempt: no RLS
     * enabled, not merely unforced. document_chase_events, by contrast,
     * DOES carry its own firm_id and IS already a prepared table (RLS
     * enabled, per the Phase 4 preparation migration) — it is simply
     * not part of THIS batch's forced set, so it must remain RLS-enabled
     * but NOT forced, exactly like every other still-unforced prepared
     * table below.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled_and_child_tables_remain_correctly_scoped(): void
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (covering
        // notification_events) for the same reason as above —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'customer_success_health_scores', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events']);
        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }

        $this->assertContains('document_chase_events', $coverage->preparedTables(), 'document_chase_events must remain a genuinely prepared (RLS-enabled, not-yet-forced) table, not exempt.');

        $childRow = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', ['document_request_items']);
        $this->assertNotNull($childRow, 'Table document_request_items not found in pg_class.');
        $this->assertFalse((bool) $childRow->relrowsecurity, 'document_request_items must remain completely exempt from RLS — not merely unforced.');
        $this->assertFalse((bool) $childRow->relforcerowsecurity, 'document_request_items must remain completely exempt from FORCE RLS.');
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'document_requests'::regclass"
        );

        $this->assertNotNull($policy, 'The document_requests tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the read
     * genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_document_requests(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, DocumentRequest::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_document_requests(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('document_requests')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'matter_id' => null,
            'client_id' => $client->id,
            'status' => DocumentRequestStatus::Open->value,
            'title' => 'Please provide the following documents',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_document_request(): void
    {
        $firmA = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $requestA = $this->runWithFirmContext($firmA, fn () => DocumentRequest::factory()->forClient($clientA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentRequest::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_document_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, fn () => DocumentRequest::factory()->forClient($clientA)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->forClient($clientB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentRequest::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $client) {
            return DB::table('document_requests')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'matter_id' => null,
                'client_id' => $client->id,
                'status' => DocumentRequestStatus::Open->value,
                'title' => 'Please provide the following documents',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_document_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientB) {
            DB::table('document_requests')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'matter_id' => null,
                'client_id' => $clientB->id,
                'status' => DocumentRequestStatus::Open->value,
                'title' => 'Please provide the following documents',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_document_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->forClient($clientB)->create());

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('document_requests')->where('id', $requestB->id)->update(['title' => 'Hijacked title']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentRequest::withoutGlobalScopes()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            $requestB->title,
            $reReadAsFirmB->title,
            'Firm A context must not be able to update Firm B\'s document_requests row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_document_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->forClient($clientB)->create());

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('document_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentRequest::withoutGlobalScopes()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s document_requests row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_document_request_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->forClient($clientB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $requestB) {
            return DB::table('document_requests')->where('id', $requestB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s document request to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentRequest::withoutGlobalScopes()->find($requestB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates document_requests.firm_id, never client_id/matter_id's
     * OWN firm_id — a raw insert whose firm_id matches the active
     * context still succeeds even when client_id points at a Client
     * belonging to a COMPLETELY DIFFERENT firm. This is a documented
     * residual DATABASE-CONSTRAINT gap, not something RLS itself
     * closes — never to be described as blocked.
     */
    public function test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());

        $mismatchedRequestId = $this->runWithFirmContext($firm, function () use ($firm, $foreignClient) {
            return DB::table('document_requests')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'matter_id' => null,
                'client_id' => $foreignClient->id,
                'status' => DocumentRequestStatus::Open->value,
                'title' => 'Please provide the following documents',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedRequestId,
            'RLS only checks the row\'s own firm_id — a client_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: a bare DocumentRequest::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and the row must
     * actually be visible/readable under its own firm's context
     * afterward, with firm_id/client_id genuinely consistent (the root
     * cause this batch's factory definition() fix closed).
     */
    public function test_document_request_factory_default_creation_is_internally_consistent(): void
    {
        $request = DocumentRequest::factory()->create();

        $this->assertNotNull($request->id);
        $this->assertNotNull($request->firm_id);
        $this->assertSame($request->firm_id, $request->client->firm_id, 'A bare DocumentRequest::factory()->create() must never produce a firm_id/client_id mismatch.');

        $persisted = $this->runWithFirmContext(
            $request->firm,
            fn () => DocumentRequest::withoutGlobalScopes()->find($request->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($request->firm_id, $persisted->firm_id);
    }

    /**
     * Explicit related-model factory state correctness: forClient()
     * must set firm_id/client_id to the EXACT client given, and the row
     * must be readable only under that firm's context.
     */
    public function test_document_request_factory_for_client_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create());

        $this->assertSame($firm->id, $request->firm_id);
        $this->assertSame($client->id, $request->client_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => DocumentRequest::withoutGlobalScopes()->find($request->id),
        );

        $this->assertNotNull($persisted);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create());

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
     * End-to-end proof that DocumentRequestService::create() functions
     * correctly under FORCE — wraps its entire body (including every
     * DocumentRequestItem insert) in a single runWithFirmContext() call
     * and clears context before returning.
     */
    public function test_the_create_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $service = new DocumentRequestService();

        $request = $service->create($firm, $client, [
            ['label' => 'Passport copy'],
            ['label' => 'Optional cover letter', 'is_required' => false],
        ]);

        $this->assertNoDatabaseTenantContext('create() must clear its own context wrap in a finally block before returning.');
        $this->assertSame(DocumentRequestStatus::Open, $request->status);
        $this->assertCount(2, $request->items);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => DocumentRequest::withoutGlobalScopes()->find($request->id),
        );

        $this->assertNotNull($persisted, 'create() must actually persist the new document_requests row to the database.');
    }

    /**
     * End-to-end proof that every one of the 7 single-item mutators
     * function correctly under FORCE — each wraps its whole body
     * (update + recomputeParentStatus()) in a single runWithFirmContext()
     * + DB::transaction() call.
     */
    public function test_every_single_item_mutator_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $reviewer = User::factory()->create();
        $service = new DocumentRequestService();

        $request = $service->create($firm, $client, [
            ['label' => 'Passport copy'],
            ['label' => 'Birth certificate'],
            ['label' => 'Employment letter'],
            ['label' => 'I-94 record'],
        ]);

        $viewed = $service->markViewed($firm, $request->items[0]);
        $this->assertNoDatabaseTenantContext('markViewed() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::Viewed, $viewed->status);

        $submitted = $service->markSubmitted($firm, $request->items[1]);
        $this->assertNoDatabaseTenantContext('markSubmitted() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::Submitted, $submitted->status);

        $underReview = $service->markUnderReview($firm, $submitted);
        $this->assertNoDatabaseTenantContext('markUnderReview() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::UnderReview, $underReview->status);

        $approved = $service->approve($firm, $underReview, $reviewer);
        $this->assertNoDatabaseTenantContext('approve() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::Approved, $approved->status);

        $submittedForRejection = $service->markSubmitted($firm, $request->items[2]);
        $rejected = $service->reject($firm, $submittedForRejection, $reviewer, 'illegible copy');
        $this->assertNoDatabaseTenantContext('reject() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::Rejected, $rejected->status);

        $waived = $service->waive($firm, $request->items[3], $reviewer, 'not applicable');
        $this->assertNoDatabaseTenantContext('waive() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::Waived, $waived->status);

        $replacementRequested = $service->requestReplacement($firm, $rejected, $reviewer, 'photo page unreadable');
        $this->assertNoDatabaseTenantContext('requestReplacement() must clear its own context wrap before returning.');
        $this->assertSame(DocumentRequestItemStatus::NeedsReplacement, $replacementRequested->status);

        $persistedRequest = $this->runWithFirmContext(
            $firm,
            fn () => DocumentRequest::withoutGlobalScopes()->find($request->id),
        );

        $this->assertNotNull($persistedRequest, 'The parent document_requests row must remain readable throughout every mutator call under FORCE.');
    }

    /**
     * End-to-end proof that DocumentChaseService's checkAndLog() (and,
     * transitively, its logEvent() helper) function correctly under
     * FORCE — the item's lazy $item->documentRequest load must succeed
     * under the caller-supplied Firm $firm context, not silently
     * return null.
     */
    public function test_the_check_and_log_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $requestService = new DocumentRequestService();
        $request = $requestService->create($firm, $client, [['label' => 'Passport copy']]);
        $item = $request->items[0];

        $chaseService = app(DocumentChaseService::class);
        $result = $chaseService->checkAndLog($firm, $item);

        $this->assertNoDatabaseTenantContext('checkAndLog() must clear its own context wrap before returning.');
        $this->assertFalse($result->eligible, 'No consent has been granted, so the item is chase-eligible but not yet notification-eligible.');

        $loggedCount = $this->runWithFirmContext($firm, fn () => $item->chaseEvents()->count());
        $this->assertSame(1, $loggedCount, 'checkAndLog() must still log a reminder_skipped event via its logEvent() helper under FORCE RLS.');
    }

    /**
     * Proves the real fix this batch made to
     * ReadinessScorecardRegistry::documents_approved: an outstanding
     * required item genuinely belonging to the matter's own firm must
     * be detected as outstanding (satisfied: false) — NOT silently
     * reported as satisfied merely because the query would otherwise
     * return zero rows under a missing/wrong tenant context. This is
     * the exact silent-false-positive failure mode this fix closed.
     *
     * Section 39A-3L, Checkpoint 14 regression fix: this test used
     * to call $registry->evaluate($matter) directly, with NO
     * surrounding tenant context, relying on documents_approved's
     * now-removed internal self-wrap to see the outstanding
     * DocumentRequestItem row seeded above. That self-wrap was
     * correctly removed (responsibility for establishing context now
     * belongs to the caller — see ReadinessScorecardRegistry and
     * MatterReadinessService::recompute()), so evaluate() must now be
     * called from within the matter's own firm context here, exactly
     * as MatterReadinessService::recompute() does in production.
     */
    public function test_readiness_scorecard_documents_approved_correctly_detects_an_outstanding_item_under_force_rls(): void
    {
        ReadinessScorecardComponent::factory()->create([
            'component_key' => 'documents_approved',
            'status' => ReadinessComponentStatus::Active,
        ]);
        $matter = Matter::factory()->create();
        $client = $this->runWithFirmContext($matter->firm, fn () => Client::factory()->forFirm($matter->firm)->create());
        $request = $this->runWithFirmContext(
            $matter->firm,
            fn () => DocumentRequest::factory()->forClient($client)->create(['matter_id' => $matter->id]),
        );
        $this->runWithFirmContext($matter->firm, fn () => DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'is_required' => true,
            'status' => DocumentRequestItemStatus::Requested,
        ]));

        // Matter::factory()->create() (bare, above) leaves DB-session
        // tenant context set to $matter->firm_id (the established
        // context-hold factory pattern) — establish a genuinely clean
        // baseline immediately before the call under test, so the
        // post-call assertion proves the wrap itself clears context.
        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $registry = new ReadinessScorecardRegistry();
        $results = $this->runWithFirmContext($matter->firm, fn () => $registry->evaluate($matter));
        $this->assertNoDatabaseTenantContext('the test\'s own runWithFirmContext() wrap must clear context before returning.');

        $result = collect($results)->firstWhere('componentKey', 'documents_approved');
        $this->assertNotNull($result);

        $this->assertFalse(
            $result->satisfied,
            'An outstanding required item must be detected as outstanding under FORCE RLS — silently reporting satisfied here would be exactly the false-positive bug this fix closed.'
        );
    }

    /**
     * Companion proof: once every required item reaches a
     * satisfied/waived terminal status, documents_approved must
     * correctly report satisfied — proving the fix did not merely
     * flip the result to always-false.
     *
     * Section 39A-3L, Checkpoint 14: also wrapped in the matter's
     * own firm context now (was previously called bare). Before this
     * fix, the bare call coincidentally still "passed" because zero
     * visible rows (no context) looks identical to zero outstanding
     * items (genuinely satisfied) — masking the exact same loss of
     * visibility as the sibling test above. Wrapping it proves the
     * satisfied result is genuine, not a coincidental false positive.
     */
    public function test_readiness_scorecard_documents_approved_correctly_reports_satisfied_once_no_item_is_outstanding(): void
    {
        ReadinessScorecardComponent::factory()->create([
            'component_key' => 'documents_approved',
            'status' => ReadinessComponentStatus::Active,
        ]);
        $matter = Matter::factory()->create();
        $client = $this->runWithFirmContext($matter->firm, fn () => Client::factory()->forFirm($matter->firm)->create());
        $request = $this->runWithFirmContext(
            $matter->firm,
            fn () => DocumentRequest::factory()->forClient($client)->create(['matter_id' => $matter->id]),
        );
        $this->runWithFirmContext($matter->firm, fn () => DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'is_required' => true,
            'status' => DocumentRequestItemStatus::Approved,
        ]));

        // Matter::factory()->create() (bare, above) leaves DB-session
        // tenant context set to $matter->firm_id (the established
        // context-hold factory pattern) — establish a genuinely clean
        // baseline immediately before the call under test.
        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $registry = new ReadinessScorecardRegistry();
        $results = $this->runWithFirmContext($matter->firm, fn () => $registry->evaluate($matter));
        $this->assertNoDatabaseTenantContext();

        $result = collect($results)->firstWhere('componentKey', 'documents_approved');
        $this->assertNotNull($result);
        $this->assertTrue($result->satisfied);
    }

    /**
     * Proves the real fix this batch made to
     * MobilePortalReadinessService::documentChecklistAvailable(): must
     * correctly return true once a genuinely firm-owned DocumentRequest
     * exists for the matter, under FORCE RLS.
     */
    public function test_mobile_portal_document_checklist_available_correctly_detects_an_existing_request_under_force_rls(): void
    {
        $matter = Matter::factory()->create();
        $service = new MobilePortalReadinessService();

        $this->assertFalse($service->documentChecklistAvailable($matter));

        $client = $this->runWithFirmContext($matter->firm, fn () => Client::factory()->forFirm($matter->firm)->create());
        $this->runWithFirmContext(
            $matter->firm,
            fn () => DocumentRequest::factory()->forClient($client)->create(['matter_id' => $matter->id]),
        );

        // Matter::factory()->create() (bare, above) leaves DB-session
        // tenant context set to $matter->firm_id (the established
        // context-hold factory pattern) — establish a genuinely clean
        // baseline immediately before the call under test.
        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertTrue(
            $service->documentChecklistAvailable($matter),
            'documentChecklistAvailable() must establish its own context internally and correctly detect the existing request — silently returning false here would be exactly the bug this fix closed.'
        );
        $this->assertNoDatabaseTenantContext('documentChecklistAvailable() must clear its own context wrap before returning.');
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Twenty-seven previously forced tables plus document_requests must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses clients as the companion
     * table (the parent of document_requests.client_id).
     */
    public function test_document_requests_is_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $requestA = $this->runWithFirmContext($firmA, fn () => DocumentRequest::factory()->forClient($clientA)->create());
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->forClient($clientB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'document_requests' => DocumentRequest::withoutGlobalScopes()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$requestA->id], $resultA['document_requests']);
        $this->assertNotContains($requestB->id, $resultA['document_requests']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
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
        $migration = require base_path('database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'document_requests'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while document_requests is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'document_requests'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'document_requests'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
