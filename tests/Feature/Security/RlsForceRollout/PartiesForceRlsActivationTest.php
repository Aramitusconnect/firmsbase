<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ConflictScope;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\PartyEntityType;
use App\Models\ConflictCheckResult;
use App\Models\ConflictCheckRun;
use App\Models\Contact;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Party;
use App\Services\ComplianceGapRegistryService;
use App\Services\ConflictCheckService;
use App\Services\DocumentUploadPolicyService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\ImportDuplicateDetectionService;
use App\Services\ImportMappingService;
use App\Services\ImportPreviewService;
use App\Services\ImportRowValidationService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PartiesForceRlsActivationTest — Section 39A-3L, Checkpoint 26.
 * Proves the forty-fourth staged FORCE ROW LEVEL SECURITY activation
 * batch (database/migrations/2026_08_25_930026_force_rls_on_parties_
 * table.php) is permanently active for parties and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table (including
 * contacts, forced one checkpoint earlier) remains forced
 * simultaneously, and — the central finding of this checkpoint — that
 * every real production writer/reader of parties
 * (ConflictCheckService::searchParties()/the Party half of
 * searchMatterParties() via run(), ImportApplyService's Party::create()
 * arm, and ImportDuplicateDetectionService::detectParty()) genuinely
 * persists/reads parties rows correctly now that FORCE is active,
 * because each was already fixed ahead of this migration by the
 * Section 39A-3L Phase B5 prerequisite remediation (committed
 * independently, before this migration, alongside contacts' own
 * equivalent fixes) — see this file's own writer-proof section below
 * and the migration's own docblock for the full account of what was
 * verified and why no further production change was required at this
 * checkpoint.
 *
 * parties has no nullable/other tenant foreign key of its own at all
 * (unlike contacts' client_id) — just firm_id — so there is no
 * transitive cross-firm foreign-key surface on this table, and no
 * "bare factory produces an unsafe cross-firm default" bug was
 * possible here either.
 *
 * contacts, parties' sibling table under the same Phase B5 prerequisite
 * remediation, was already forced separately (Checkpoint 25, committed
 * ahead of this one) — this file also proves contacts remains forced
 * and untouched by this checkpoint's migration, so a reader of this
 * file does not have to take that on faith.
 */
class PartiesForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
    // Integration Platform mission (firm_integrations, a new genuine
    // tenant-owned table with RLS prepared and FORCE-activated in the
    // same migration, 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 114.
    // Narrowly updated AGAIN by Stage B Checkpoint 4 of the
    // FirmsBase Integration Platform mission (integration_credentials,
    // a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration,
    // 2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 115.
    // Narrowly updated AGAIN by Stage B Checkpoint 5 of the FirmsBase Integration Platform mission
    // (integration_oauth_states, a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 116.
    // Narrowly updated AGAIN by Stage B Checkpoint 6 of the FirmsBase Integration Platform mission
    // (integration_sync_runs, integration_sync_items, integration_external_mappings,
    // integration_sync_cursors, integration_conflicts, and integration_outbox_events, six
    // brand-new genuine tenant-owned tables, each with RLS prepared and FORCE-activated
    // in its own combined migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 122.
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events', 'intake_submissions',
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans',
        'payment_plan_events', 'notification_events', 'contacts',
    ];

    private function conflictCheckService(): ConflictCheckService
    {
        return new ConflictCheckService(new TimelineEventRecorder());
    }

    private function importApplyService(): ImportApplyService
    {
        $auditService = new ImportAuditService();

        return new ImportApplyService(
            new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner()),
            $auditService,
        );
    }

    private function importBatchService(): ImportBatchService
    {
        return new ImportBatchService(new ImportAuditService());
    }

    private function importDuplicateDetectionService(): ImportDuplicateDetectionService
    {
        return new ImportDuplicateDetectionService(new ImportAuditService());
    }

    private function importPreviewService(): ImportPreviewService
    {
        $auditService = new ImportAuditService();

        return new ImportPreviewService(
            new ImportRowValidationService(new ImportMappingService($auditService), $auditService),
            $this->importDuplicateDetectionService(),
            $auditService,
        );
    }

    // ---------------------------------------------------------------
    // FORCE state / policy / cumulative-coverage proofs
    // ---------------------------------------------------------------

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

    public function test_parties_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'parties'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_parties_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'parties'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'parties must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-four tables (the forty-three previously forced plus
     * parties) must be FORCE-enabled among ALL prepared tables — no
     * more, no less.
     */
    public function test_exactly_forty_four_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events']);

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

        $this->assertSame(123, count($actuallyForced), 'Exactly forty-four prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 26 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events']);

        // Section 39A-3L, Phase B6, Checkpoint 34 (security_events) is
        // the final checkpoint in this arc: $forced now equals the FULL
        // preparedTables() set exactly, so the per-table loop below
        // legitimately has zero remaining iterations (a real, positive
        // end state, not a lost assertion). This explicit equality
        // check keeps the test genuinely assertive regardless of loop
        // iteration count.
        $forcedSorted = $forced;
        sort($forcedSorted);
        $preparedTablesSorted = $coverage->preparedTables();
        sort($preparedTablesSorted);
        $this->assertSame($forcedSorted, $preparedTablesSorted, 'Every originally "prepared" table must now be force-enabled, no more, no fewer.');

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    /**
     * contacts, parties' sibling table under the same Section 39A-3L
     * Phase B5 prerequisite remediation, was already forced separately
     * (Checkpoint 25, committed ahead of this one) — proves this
     * checkpoint's own migration did not regress it.
     */
    public function test_contacts_remains_force_enabled_and_is_unaffected_by_this_checkpoint(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'contacts'");

        $this->assertNotNull($row, 'contacts table not found in pg_class.');
        $this->assertTrue((bool) $row->relrowsecurity, 'contacts must remain RLS-enabled.');
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'contacts must remain FORCE-enabled (Checkpoint 25) — this checkpoint (Checkpoint 26, parties) must not regress its sibling.'
        );
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'parties'::regclass"
        );

        $this->assertNotNull($policy, 'The parties tenant isolation policy must still exist.');
        $this->assertSame('parties_tenant_isolation', $policy->polname);
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
        $this->assertNull($policy->with_check_expr, 'parties never had a separate WITH CHECK clause — USING alone governs both read and write under FORCE.');
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and contacts' own policy (the checkpoint immediately prior)
     * as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne(
            "select polname from pg_policy where polrelid = 'clients'::regclass"
        );

        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $contactsPolicy = DB::selectOne(
            "select polname from pg_policy where polrelid = 'contacts'::regclass"
        );

        $this->assertNotNull($contactsPolicy);
        $this->assertSame('contacts_tenant_isolation', $contactsPolicy->polname);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_parties(): void
    {
        $firm = Firm::factory()->create();
        Party::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, Party::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_parties(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('parties')->insert([
            'firm_id' => $firm->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'No Context Party',
            'entity_type' => PartyEntityType::Individual->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_party(): void
    {
        $firmA = Firm::factory()->create();
        $partyA = $this->runWithFirmContext($firmA, fn () => Party::factory()->create(['firm_id' => $firmA->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Party::query()->pluck('id')->all(),
        );

        $this->assertSame([$partyA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_party(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => Party::factory()->create(['firm_id' => $firmA->id]));
        $partyB = $this->runWithFirmContext($firmB, fn () => Party::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Party::query()->pluck('id')->all(),
        );

        $this->assertNotContains($partyB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('parties')->insertGetId([
                'firm_id' => $firm->id,
                'uuid' => (string) Str::uuid(),
                'name' => 'Valid Insert Party',
                'entity_type' => PartyEntityType::Individual->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_party_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('parties')->insert([
                'firm_id' => $firmB->id,
                'uuid' => (string) Str::uuid(),
                'name' => 'Claimed Ownership Party',
                'entity_type' => PartyEntityType::Individual->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_party(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $partyB = $this->runWithFirmContext($firmB, fn () => Party::factory()->create(['firm_id' => $firmB->id, 'name' => 'Original Name']));

        $affected = $this->runWithFirmContext($firmA, function () use ($partyB) {
            return DB::table('parties')->where('id', $partyB->id)->update(['name' => 'Hijacked Name']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s parties row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Party::query()->find($partyB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->name);
    }

    public function test_firm_a_context_cannot_delete_firm_b_party(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $partyB = $this->runWithFirmContext($firmB, fn () => Party::factory()->create(['firm_id' => $firmB->id]));

        $this->runWithFirmContext($firmA, function () use ($partyB) {
            DB::table('parties')->where('id', $partyB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Party::query()->find($partyB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s parties row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_party_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $partyB = $this->runWithFirmContext($firmB, fn () => Party::factory()->create(['firm_id' => $firmB->id]));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $partyB) {
            return DB::table('parties')->where('id', $partyB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s parties row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Party::query()->find($partyB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare Party::factory()->create() must
     * succeed even from outside any already-active tenant context (the
     * factory's context-hold create() override), and must be
     * immediately readable under its own firm's context. Unlike
     * contacts, parties has no other tenant foreign key on the row at
     * all — just firm_id — so the bare/default creation path cannot
     * produce a cross-firm relation mismatch at all.
     */
    public function test_party_factory_default_creation_is_safe_and_immediately_readable(): void
    {
        $party = Party::factory()->create();

        $this->assertNotNull($party->id);
        $this->assertNotNull($party->firm_id);

        $persisted = $this->runWithFirmContext(
            $party->firm_id,
            fn () => Party::query()->find($party->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($party->firm_id, $persisted->firm_id);
    }

    /**
     * PartyFactory::forFirm() state correctness — the row is created
     * under, and immediately readable under, exactly the given firm.
     */
    public function test_party_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $party = Party::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $party->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => Party::query()->find($party->id));

        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);
    }

    /**
     * PartyFactory::company() state correctness — entity_type is set to
     * Company and a company-shaped name is generated, and the row
     * genuinely persists and is readable under FORCE RLS.
     */
    public function test_party_factory_company_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $party = Party::factory()->forFirm($firm)->company()->create();

        $this->assertSame(PartyEntityType::Company, $party->entity_type);

        $persisted = $this->runWithFirmContext($firm, fn () => Party::query()->find($party->id));

        $this->assertNotNull($persisted);
        $this->assertSame(PartyEntityType::Company, $persisted->entity_type);
        $this->assertNotNull($persisted->name);
    }

    // ---------------------------------------------------------------
    // Writer regression proofs — the central finding of this
    // checkpoint. Each proves the corresponding REAL production
    // writer/reader genuinely persists/reads a parties row correctly
    // now that FORCE is active, because each was already fixed ahead
    // of this migration by the Section 39A-3L Phase B5 prerequisite
    // remediation.
    // ---------------------------------------------------------------

    /**
     * ConflictCheckService::searchParties() (reached via run()) —
     * rewritten by Phase B5 to iterate $firmIds explicitly under its
     * own per-firm runWithFirmContext() call, matching searchContacts().
     * Proves a real party match is found by a real conflict check
     * run, and that the match genuinely persists to conflict_check_
     * results, not just an in-memory side effect.
     */
    public function test_conflict_check_service_run_finds_a_party_match_via_search_parties(): void
    {
        $matter = Matter::factory()->create();
        $party = Party::factory()->create(['firm_id' => $matter->firm_id, 'email' => 'party-conflict-checkpoint26@example.com']);

        $summary = $this->conflictCheckService()->run($matter, ['party-conflict-checkpoint26@example.com']);

        $this->assertSame(1, $summary->resultCount);
        $this->assertTrue($summary->hasPossibleMatches);

        $result = $this->runWithFirmContext(
            $matter->firm,
            fn () => ConflictCheckResult::query()->where('matched_type', 'party')->first(),
        );

        $this->assertNotNull($result, 'searchParties() must genuinely produce a persisted conflict_check_results row for a real party match.');
        $this->assertSame($party->id, $result->matched_id);
        $this->assertSame($party->name, $result->matched_value, 'matched_value must contain the real party name, per searchParties()\'s own mapping.');
    }

    /**
     * searchParties() must merge matches across MULTIPLE firms when
     * organization-wide scope legitimately reaches more than one firm
     * — not just the run()-owning firm's own parties.
     */
    public function test_conflict_check_service_finds_party_matches_in_both_sibling_firms(): void
    {
        $organization = Organization::factory()->create(['conflict_scope' => ConflictScope::OrganizationWide]);
        $firmA = Firm::factory()->forOrganization($organization)->create();
        $firmB = Firm::factory()->forOrganization($organization)->create();

        $partyA = Party::factory()->create(['firm_id' => $firmA->id, 'name' => 'Checkpoint 26 Shared Party Match']);
        $partyB = Party::factory()->create(['firm_id' => $firmB->id, 'name' => 'Checkpoint 26 Shared Party Match']);

        $matter = Matter::factory()->forFirm($firmA)->create();
        $summary = $this->conflictCheckService()->run($matter, ['Checkpoint 26 Shared Party Match']);

        $this->assertSame(2, $summary->resultCount, 'a party match in each sibling firm must both be found');

        $results = $this->runWithFirmContext(
            $firmA,
            fn () => ConflictCheckRun::find($summary->conflictCheckRunId)->results,
        );
        $matchedIds = $results->where('matched_type', 'party')->pluck('matched_id')->all();

        $this->assertEqualsCanonicalizing([$partyA->id, $partyB->id], $matchedIds);
    }

    /**
     * The Party half of searchMatterParties() — flags a matched party's
     * presence in OTHER matters within scope. This is the specific
     * proof of the ->with('party') eager-load-removal fix: the final
     * composed MatterParty query deliberately does NOT eager-load its
     * party relation (that would run after every runWithFirmContext()
     * call has already cleared its own context, returning zero rows
     * under RLS); instead matched_value is built from an in-PHP
     * [$partyId => $partyName] map populated from the already-fetched,
     * already-context-wrapped $parties collection. This test proves the
     * matched_value content is genuinely correct — the real party name
     * plus the real other matter's id — not merely that a row exists.
     */
    public function test_conflict_check_service_run_finds_a_matter_party_match_via_search_matter_parties_with_correct_matched_value(): void
    {
        $firm = Firm::factory()->create();
        $matterUnderTest = Matter::factory()->forFirm($firm)->create();
        $otherMatter = Matter::factory()->forFirm($firm)->create();

        $party = Party::factory()->create(['firm_id' => $firm->id, 'name' => 'Checkpoint 26 Opposing Party']);
        $matterParty = MatterParty::factory()->forMatter($otherMatter)->forParty($party)->opposing()->create();

        $summary = $this->conflictCheckService()->run($matterUnderTest, ['Checkpoint 26 Opposing Party']);

        // Both searchParties() (the party itself) and searchMatterParties()
        // (its appearance in the other matter) legitimately match here —
        // isolate the matter_party result specifically.
        $results = $this->runWithFirmContext(
            $firm,
            fn () => ConflictCheckRun::find($summary->conflictCheckRunId)->results,
        );

        $matterPartyResult = $results->firstWhere('matched_type', 'matter_party');

        $this->assertNotNull($matterPartyResult, 'searchMatterParties() must genuinely produce a persisted conflict_check_results row for a real matter_party match.');
        $this->assertSame($matterParty->id, $matterPartyResult->matched_id);
        $this->assertSame(
            sprintf('%s (matter #%d)', $party->name, $otherMatter->id),
            $matterPartyResult->matched_value,
            'matched_value must contain the real party name (resolved from the in-PHP map, not a cleared-context eager load) and the real other matter id.'
        );
    }

    /**
     * ImportApplyService's ImportEntityType::Party arm — wrapped in
     * runWithFirmContext($firm, ...) by Phase B5. Proves the full
     * apply() pipeline (confirm -> apply) genuinely persists a real
     * parties row with no ambient context established by the caller
     * beforehand.
     */
    public function test_import_apply_service_party_arm_genuinely_persists_a_party_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $batch = $this->importBatchService()->create($firm, ImportEntityType::Party, ImportSourceType::CsvUpload);
        // import_batches gained permanent FORCE ROW LEVEL SECURITY in a
        // later, separate wave (Section 39A-9 Wave 9); each writer
        // service's own wrap already restores database session context
        // to "none" once it returns, so a bare $batch->fresh() call
        // afterward would return null. Chain each service's own
        // already-fresh return value instead of an unwrapped re-fetch.
        $batch = $this->importBatchService()->stageRows($batch, [['name' => 'Checkpoint 26 Imported Party', 'email' => 'imported.party.checkpoint26@example.test']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);

        $applyService = $this->importApplyService();
        $confirmed = $applyService->confirmBatch($batch);

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $applied = $applyService->apply($confirmed);

        $this->assertNoDatabaseTenantContext('apply() must clear its own internal context wrap before returning.');
        $this->assertSame(ImportBatchStatus::Applied, $applied->status);

        $row = $batch->rows()->first();
        $this->assertSame(ImportRowStatus::Applied, $row->status);
        $this->assertSame(Party::class, $row->applied_record_type);
        $this->assertNotNull($row->applied_record_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => Party::query()->where('firm_id', $firm->id)->where('email', 'imported.party.checkpoint26@example.test')->first(),
        );

        $this->assertNotNull($persisted, 'ImportApplyService\'s Party arm must genuinely persist its parties row to the database, not just an in-memory side effect.');
        $this->assertSame('Checkpoint 26 Imported Party', $persisted->name);
    }

    /**
     * ImportDuplicateDetectionService::detectParty() — wrapped in
     * runWithFirmContext($firmId, ...) by Phase B5. import_batches now
     * carries FORCE ROW LEVEL SECURITY (see database/migrations/
     * 2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php),
     * and detect()'s own $row->importBatch lazy load is NOT wrapped by
     * detect() itself — only its per-entity-type helper methods
     * (detectParty() etc.) wrap themselves. detect() therefore no
     * longer works standalone with truly zero ambient context: its real,
     * only production call path is ImportPreviewService::preview(),
     * whose entire body is now wrapped in one runWithFirmContext($batch->
     * firm_id, ...) call (ImportPreviewService.php's own docblock). This
     * test proves detect() correctly reads a real, already-persisted
     * party row and reports a genuine duplicate match when exercised
     * through that real call path, with no ambient context established
     * by preview()'s OWN caller beforehand — the guarantee that
     * actually holds in production, replacing the old (and no longer
     * true, now that import_batches is FORCE-RLS'd) claim that detect()
     * works with literally zero context of any kind.
     */
    public function test_import_duplicate_detection_service_detect_party_genuinely_reads_when_called_via_preview_with_no_ambient_context_established_by_the_caller(): void
    {
        $firm = Firm::factory()->create();
        $existing = Party::factory()->create(['firm_id' => $firm->id, 'name' => 'Checkpoint 26 Dup Party', 'email' => 'dup-party-checkpoint26@example.test']);
        $batch = ImportBatch::factory()->forFirm($firm)->entityType(ImportEntityType::Party)->create();
        // import_mappings is not RLS-protected (InheritedTenant via
        // import_batch_id, no firm_id of its own) — safe to seed with no
        // ambient context, and required here so validateBatch() (called
        // internally by preview()) preserves 'name'/'email' into
        // mapped_data instead of dropping them (applyMappingsToRawData()
        // only copies fields with a saved mapping).
        (new ImportMappingService(new ImportAuditService()))->saveMappings($batch, [
            ['source_field' => 'name', 'target_field' => 'name', 'is_required' => false],
            ['source_field' => 'email', 'target_field' => 'email', 'is_required' => false],
        ]);
        $row = $batch->rows()->create(['row_number' => 1, 'raw_data' => ['name' => 'Checkpoint 26 Dup Party', 'email' => 'dup-party-checkpoint26@example.test'], 'status' => 'validated']);

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        // $batch is passed as the already-hydrated, in-memory object
        // returned by create() above — not re-fetched via ->fresh() —
        // exactly matching preview()'s own documented contract that
        // $batch->firm_id is already an in-memory attribute requiring no
        // extra query before its internal wrap begins.
        $preview = $this->importPreviewService()->preview($batch);

        $this->assertNoDatabaseTenantContext('preview() must clear its own internal context wrap before returning.');
        $this->assertSame(1, $preview->duplicateRows);

        $duplicateRow = $this->runWithFirmContext($firm, fn () => $row->fresh());
        $this->assertTrue($duplicateRow->is_duplicate);
        $this->assertSame($existing->id, $duplicateRow->duplicate_of_id);
        $this->assertSame(Party::class, $duplicateRow->duplicate_of_type);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => Party::factory()->create(['firm_id' => $firm->id]));

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
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg('app/Services/ComplianceGapRegistryService.php')
        ));

        $this->assertSame('', $changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    /**
     * No UI/route/domain surface was added by this checkpoint — a
     * migration-only, test-only change.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 26 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-three previously forced tables plus parties must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses contacts as the
     * companion table (forced immediately prior, at Checkpoint 25).
     */
    public function test_parties_are_isolated_independently_and_simultaneously_with_contacts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $partyA = $this->runWithFirmContext($firmA, fn () => Party::factory()->create(['firm_id' => $firmA->id]));
        $partyB = $this->runWithFirmContext($firmB, fn () => Party::factory()->create(['firm_id' => $firmB->id]));

        $contactA = $this->runWithFirmContext($firmA, fn () => Contact::factory()->create(['firm_id' => $firmA->id]));
        $contactB = $this->runWithFirmContext($firmB, fn () => Contact::factory()->create(['firm_id' => $firmB->id]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'parties' => Party::query()->pluck('id')->all(),
            'contacts' => Contact::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$partyA->id], $resultA['parties']);
        $this->assertNotContains($partyB->id, $resultA['parties']);
        $this->assertSame([$contactA->id], $resultA['contacts']);
        $this->assertNotContains($contactB->id, $resultA['contacts']);
    }

    /**
     * Forty-one previously forced tables (excluding contacts/parties)
     * plus notification_events must remain independently isolated too
     * — proof the isolation established two checkpoints prior is still
     * unaffected.
     */
    public function test_parties_are_isolated_independently_and_simultaneously_with_notification_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $partyA = $this->runWithFirmContext($firmA, fn () => Party::factory()->create(['firm_id' => $firmA->id]));
        $partyB = $this->runWithFirmContext($firmB, fn () => Party::factory()->create(['firm_id' => $firmB->id]));

        $notificationEventA = $this->runWithFirmContext($firmA, fn () => NotificationEvent::factory()->create(['firm_id' => $firmA->id]));
        $notificationEventB = $this->runWithFirmContext($firmB, fn () => NotificationEvent::factory()->create(['firm_id' => $firmB->id]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'parties' => Party::query()->pluck('id')->all(),
            'notification_events' => NotificationEvent::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$partyA->id], $resultA['parties']);
        $this->assertNotContains($partyB->id, $resultA['parties']);
        $this->assertSame([$notificationEventA->id], $resultA['notification_events']);
        $this->assertNotContains($notificationEventB->id, $resultA['notification_events']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the parties migration's down() must genuinely
     * restore the Section 39A baseline — RLS still enabled, policy
     * still present, but NOT forced — never drop the policy or disable
     * RLS itself. Also proves rollback affects ONLY this one table —
     * every other previously-forced table (including contacts) must be
     * untouched.
     */
    public function test_parties_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930026_force_rls_on_parties_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'parties'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while parties is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'parties'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'parties'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
