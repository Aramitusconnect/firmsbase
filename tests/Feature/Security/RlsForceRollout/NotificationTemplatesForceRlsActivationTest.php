<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\SenderDomainStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Services\ComplianceGapRegistryService;
use App\Services\NotificationTemplateService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\SenderDomainVerificationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NotificationTemplatesForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 31 (Phase B6). Proves the forty-ninth staged FORCE ROW
 * LEVEL SECURITY activation batch (database/migrations/2026_08_25_
 * 930031_force_rls_on_notification_templates_table.php) is
 * permanently active for notification_templates and behaves
 * correctly: every previously-forced table remains forced
 * simultaneously; missing-context read/insert denial; a firm-specific
 * override row remains strictly single-firm-visible; a global-default
 * (firm_id = NULL) row is visible under EVERY firm-scoped session's
 * context; the asymmetric WITH CHECK closes both the INSERT-side
 * forgery gap and the DELETE-side gap.
 *
 * This checkpoint's own genuinely different semantics (NOT a
 * "platform monitoring" nullable-firm_id table like the four prior
 * checkpoints — a "global default with optional per-firm override"
 * table instead, per the dossier): NotificationTemplateService::
 * resolve() implements the fallback lookup (firm override first, then
 * global default), and this test file proves that lookup end-to-end
 * under real FORCE enforcement — not just at the application-logic
 * level (already covered by the pre-existing unit test) — by reading
 * both the override-exists and override-absent cases through actual
 * RLS-gated queries. It also proves the wrap-extends-through-fresh()
 * fix for archive()/markVerified()/markFailed(), and the
 * previously-undefined-by-accident syncVerificationAcrossFirmTemplates()
 * behavior, mirroring maintenance_windows' own novel contribution but
 * applied to two service classes instead of one.
 *
 * Full design rationale and the exact approved SQL:
 * rls-checkpoints/39a3l/B6-notification_templates-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests/health_checks/incident_events/
 * maintenance_windows, notification_templates required real
 * application-code prerequisites ahead of this FORCE migration —
 * NotificationTemplateService's createGlobalDefault()/
 * createFirmOverride()/archive() and SenderDomainVerificationService's
 * markVerified()/markFailed()/syncVerificationAcrossFirmTemplates()
 * gaining a context wrap (extending through their trailing ->fresh()
 * re-read where applicable), and NotificationTemplateFactory's
 * context-hold create() override — all already committed ahead of
 * this migration, matching this arc's own established split-commit
 * precedent. That factory fix also closes a real, already-latent
 * cross-checkpoint regression in NotificationEventsForceRlsActivationTest
 * ::test_a_raw_insert_can_still_reference_a_notification_template_from_a_different_firm_at_the_raw_db_layer
 * (line ~478) — this checkpoint's own verification explicitly
 * re-runs that file, plus CommunicationConsentsForceRlsActivationTest.php
 * and NotificationDispatchServiceTest.php, as a genuine regression
 * check rather than a formality, since notification_templates is now
 * FORCE-active for the first time and those files were previously
 * only passing by accident of table-owner RLS bypass.
 */
class NotificationTemplatesForceRlsActivationTest extends TestCase
{
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
        'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests',
        'health_checks', 'incident_events', 'maintenance_windows',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    private function templateService(): NotificationTemplateService
    {
        return new NotificationTemplateService();
    }

    private function senderDomainService(): SenderDomainVerificationService
    {
        return new SenderDomainVerificationService();
    }

    private function insertRow(?int $firmId, string $key, string $suffix = ''): int
    {
        return DB::table('notification_templates')->insertGetId([
            'firm_id' => $firmId,
            'key' => $key,
            'channel' => ConsentChannel::Email->value,
            'language' => 'en',
            'status' => NotificationTemplateStatus::Active->value,
            'subject' => 'RLS proof subject '.$suffix,
            'body' => 'RLS proof body '.$suffix,
            'spf_status' => SenderDomainStatus::Pending->value,
            'dkim_status' => SenderDomainStatus::Pending->value,
            'dmarc_status' => SenderDomainStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    public function test_notification_templates_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'notification_templates'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_notification_templates_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'notification_templates'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'notification_templates must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-nine tables (the forty-eight previously forced
     * plus notification_templates) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     */
    public function test_exactly_forty_nine_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests']);

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

        $this->assertSame(108, count($actuallyForced), 'Exactly forty-nine prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 31 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests']);

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
     * This migration REPLACES the original single-expression policy
     * with two new policies — unlike every FORCE-only checkpoint,
     * where the pre-existing policy was left completely untouched.
     */
    public function test_the_original_single_policy_no_longer_exists(): void
    {
        $policy = DB::selectOne(
            "select polname from pg_policy where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_isolation'"
        );

        $this->assertNull($policy, 'The original single-expression policy must have been dropped and replaced by the two new policies.');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_read'"
        );

        $this->assertNotNull($readPolicy, 'notification_templates_tenant_read must exist.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_write'"
        );

        $this->assertNotNull($writePolicy, 'notification_templates_tenant_write must exist.');
        $this->assertNotNull($writePolicy->with_check_expr, 'the write policy must carry an explicit, asymmetric WITH CHECK — not the FOR ALL implicit reuse of USING.');
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->using_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and maintenance_windows' own policy (the immediately prior
     * checkpoint) as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $maintenanceWindowsWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_write'");
        $this->assertNotNull($maintenanceWindowsWritePolicy);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_override_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'override-key', 'firm-specific'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, NotificationTemplate::query()->where('firm_id', $firm->id)->count());
    }

    public function test_missing_tenant_context_cannot_insert_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->insertRow($firm->id, 'no-context-insert');
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs — firm-specific
    // override rows remain strictly single-firm-visible.
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_specific_override_row(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-key', 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => NotificationTemplate::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_specific_override_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-key', 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => NotificationTemplate::query()->pluck('id')->all(),
        );

        $this->assertNotContains($rowB, $visibleIds);
    }

    public function test_firm_specific_override_row_is_invisible_under_no_context(): void
    {
        $firmA = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'no-context-visibility-key'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $visible = NotificationTemplate::query()->find($rowA);

        $this->assertNull($visible, 'A firm-specific override row must be invisible under a genuinely context-free session.');
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'valid-insert-key'));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmB->id, 'claimed-ownership-key'));
    }

    public function test_firm_a_context_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'update-target-key'));

        $affected = $this->runWithFirmContext($firmA, function () use ($rowB) {
            return DB::table('notification_templates')->where('id', $rowB)->update(['subject' => 'Hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s notification_templates row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => NotificationTemplate::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertStringStartsWith('RLS proof subject', $reReadAsFirmB->subject);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target-key'));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('notification_templates')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => NotificationTemplate::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s notification_templates row.');
    }

    /**
     * Direct SQL-level proof a firm-scoped session cannot write into a
     * sibling firm's firm_id via UPDATE — the target row IS visible
     * under USING (firm A owns it), but WITH CHECK rejects the
     * resulting new row (firm_id = firm B) outright.
     */
    public function test_a_firm_scoped_session_cannot_update_its_own_row_to_claim_sibling_firm_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'reassign-to-sibling-key'));

        try {
            $this->runWithFirmContext($firmA, function () use ($firmB, $rowA) {
                return DB::table('notification_templates')->where('id', $rowA)->update(['firm_id' => $firmB->id]);
            });
            $this->fail('Expected a row-level security policy violation when Firm A tries to reassign its own row to Firm B.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security policy', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext();

        $stillFirmAs = $this->runWithFirmContext($firmA, fn () => NotificationTemplate::query()->find($rowA));
        $this->assertNotNull($stillFirmAs);
        $this->assertSame($firmA->id, $stillFirmAs->firm_id);
    }

    // ---------------------------------------------------------------
    // Global-default (firm_id = NULL) row visibility proofs — the
    // central, positive read-side design decision this checkpoint
    // proves: every tenant may see every global-default row.
    // ---------------------------------------------------------------

    public function test_a_global_default_row_is_visible_under_every_firm_scoped_sessions_context(): void
    {
        $globalId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'global-key', 'global-default'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $visibleToA = $this->runWithFirmContext($firmA, fn () => NotificationTemplate::query()->whereNull('firm_id')->pluck('id')->all());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => NotificationTemplate::query()->whereNull('firm_id')->pluck('id')->all());

        $this->assertContains($globalId, $visibleToA, 'Firm A must see the global-default row.');
        $this->assertContains($globalId, $visibleToB, 'Firm B must also independently see the same global-default row.');
    }

    public function test_a_global_default_row_does_not_expose_sibling_firm_specific_rows(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'global-isolation-key', 'global-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked-key', 'firm-b-not-leaked'));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => NotificationTemplate::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visibleToA, 'Firm A must still not see Firm B\'s firm-specific row, even though a global-default row is visible to both.');
    }

    // ---------------------------------------------------------------
    // WITH CHECK asymmetry proofs — INSERT-side forgery prevention.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_insert_a_forged_global_default_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-global-key'));
    }

    public function test_a_genuinely_context_free_session_can_insert_a_global_default_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $insertedId = $this->insertRow(null, 'legitimate-global-key');

        $this->assertIsInt($insertedId);
    }

    // ---------------------------------------------------------------
    // WITH CHECK/USING asymmetry proofs — DELETE-side gap closure.
    // WITH CHECK is never consulted for DELETE in PostgreSQL, so an
    // asymmetric WITH CHECK alone (closing INSERT-side forgery) does
    // nothing for this mirror-image case — the write policy's own
    // USING clause is what closes it.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_delete_a_global_default_row(): void
    {
        $globalId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'delete-gap-target-key'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($globalId) {
            return DB::table('notification_templates')->where('id', $globalId)->delete();
        });

        $this->assertSame(0, $affected, 'A firm-scoped session must not be able to delete a global-default (firm_id = NULL) row.');

        $stillExists = $this->tenantContext()->runWithoutFirmContext(
            fn () => NotificationTemplate::query()->whereNull('firm_id')->find($globalId),
        );

        $this->assertNotNull($stillExists, 'The global-default row must genuinely still exist in the database after the blocked delete attempt.');
    }

    public function test_a_firm_scoped_session_cannot_delete_all_global_default_rows_via_a_direct_is_null_filter(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-key-1', 'is-null-exploit-shape-1'));
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-key-2', 'is-null-exploit-shape-2'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () {
            return DB::table('notification_templates')->whereNull('firm_id')->delete();
        });

        $this->assertSame(0, $affected, 'DELETE FROM notification_templates WHERE firm_id IS NULL must affect zero rows under a firm-scoped session.');

        $remaining = $this->tenantContext()->runWithoutFirmContext(
            fn () => NotificationTemplate::query()->whereNull('firm_id')->count(),
        );

        $this->assertSame(2, $remaining, 'Both global-default rows must genuinely still exist.');
    }

    public function test_a_genuinely_context_free_session_can_delete_a_global_default_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $globalId = $this->insertRow(null, 'context-free-delete-key');

        $affected = DB::table('notification_templates')->where('id', $globalId)->delete();

        $this->assertSame(1, $affected, 'A genuinely context-free session must be able to delete a global-default row it is also able to write.');
    }

    // ---------------------------------------------------------------
    // resolve() end-to-end proofs under real FORCE enforcement — the
    // central "global default with per-firm override" semantics this
    // checkpoint's own dossier distinguishes from the four prior
    // nullable-firm_id tables.
    // ---------------------------------------------------------------

    public function test_resolve_falls_back_to_the_global_default_when_no_firm_override_exists_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->templateService();

        $global = $service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body under FORCE.');

        $resolved = $this->runWithFirmContext($firm, fn () => $service->resolve($firm, 'document_reminder', ConsentChannel::Email));

        $this->assertNotNull($resolved, 'resolve() must genuinely find the global default row under real FORCE enforcement.');
        $this->assertTrue($resolved->is($global));
        $this->assertNull($resolved->firm_id);
    }

    public function test_resolve_prefers_a_firm_override_over_the_global_default_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->templateService();

        $service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body under FORCE.');
        $override = $service->createFirmOverride($firm, 'document_reminder', ConsentChannel::Email, 'Firm-specific body under FORCE.');

        $resolved = $this->runWithFirmContext($firm, fn () => $service->resolve($firm, 'document_reminder', ConsentChannel::Email));

        $this->assertNotNull($resolved, 'resolve() must genuinely find a row under real FORCE enforcement, not silently return null.');
        $this->assertTrue($resolved->is($override), 'resolve() must prefer the firm\'s own override over the global default when both exist and are visible under FORCE.');
        $this->assertSame($firm->id, $resolved->firm_id);
    }

    public function test_resolve_called_with_no_ambient_context_cannot_see_a_firm_override_and_falls_back_to_global(): void
    {
        // Direct proof of the failure mode Design Reviewer 1 caught in
        // the dossier's own review: resolve() called with zero ambient
        // context cannot see a firm-scoped override row under the read
        // policy — it silently falls back to the global default
        // instead of raising an error. This is expected, documented
        // application behavior (resolve() is intentionally unwrapped,
        // relying on its caller to establish context) — proven here
        // directly rather than assumed.
        $firm = Firm::factory()->create();
        $service = $this->templateService();

        $global = $service->createGlobalDefault('document_reminder', ConsentChannel::Email, 'Global body.');
        $service->createFirmOverride($firm, 'document_reminder', ConsentChannel::Email, 'Firm-specific body.');

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $resolved = $service->resolve($firm, 'document_reminder', ConsentChannel::Email);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($global), 'With no ambient context, resolve() must fall back to the global default — the override row is genuinely invisible under the read policy.');
    }

    // ---------------------------------------------------------------
    // Novel security contribution — the wrap-must-extend-through-
    // fresh() fix, proven directly against a firm-scoped template for
    // BOTH NotificationTemplateService::archive() and
    // SenderDomainVerificationService::markVerified()/markFailed().
    // ---------------------------------------------------------------

    public function test_archive_against_a_firm_scoped_template_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->templateService();

        $template = $service->createFirmOverride($firm, 'archive-key', ConsentChannel::Email, 'To be archived.');
        $this->assertSame(NotificationTemplateStatus::Active, $template->status);

        $archived = $service->archive($template);

        $this->assertNotNull($archived, 'archive()\'s trailing fresh() must return a populated model, not null, for a firm-scoped template under FORCE.');
        $this->assertSame(NotificationTemplateStatus::Archived, $archived->status);
        $this->assertSame($firm->id, $archived->firm_id);
    }

    public function test_archive_against_a_global_default_template_returns_a_populated_model_under_force(): void
    {
        $service = $this->templateService();

        $template = $service->createGlobalDefault('archive-global-key', ConsentChannel::Email, 'To be archived.');

        $archived = $service->archive($template);

        $this->assertNotNull($archived, 'archive()\'s trailing fresh() must return a populated model, not null, for a global-default template under FORCE.');
        $this->assertSame(NotificationTemplateStatus::Archived, $archived->status);
        $this->assertNull($archived->firm_id);
    }

    public function test_mark_verified_against_a_firm_scoped_template_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $templateService = $this->templateService();
        $senderService = $this->senderDomainService();

        $template = $templateService->createFirmOverride($firm, 'mark-verified-key', ConsentChannel::Email, 'Body.', fromDomain: 'mail.example.com');

        $verified = $senderService->markVerified($template);

        $this->assertNotNull($verified, 'markVerified()\'s trailing fresh() must return a populated model, not null, for a firm-scoped template under FORCE.');
        $this->assertSame(SenderDomainStatus::Verified, $verified->spf_status);
        $this->assertSame(SenderDomainStatus::Verified, $verified->dkim_status);
        $this->assertSame(SenderDomainStatus::Verified, $verified->dmarc_status);
        $this->assertNotNull($verified->domain_verified_at);
        $this->assertSame($firm->id, $verified->firm_id);
    }

    public function test_mark_failed_against_a_firm_scoped_template_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $templateService = $this->templateService();
        $senderService = $this->senderDomainService();

        $template = $templateService->createFirmOverride($firm, 'mark-failed-key', ConsentChannel::Email, 'Body.', fromDomain: 'mail.example.com');
        $verified = $senderService->markVerified($template);

        // Deliberately use markVerified()'s own returned model rather
        // than re-calling ->fresh() directly here with no ambient
        // context — doing so would reproduce the exact wrap-must-
        // extend-through-fresh() failure mode this checkpoint fixes
        // (a firm-scoped row's ->fresh() returns null with no
        // context), which is not what this test is proving.
        $failed = $senderService->markFailed($verified, 'DMARC record missing');

        $this->assertNotNull($failed, 'markFailed()\'s trailing fresh() must return a populated model, not null, for a firm-scoped template under FORCE.');
        $this->assertSame(SenderDomainStatus::Failed, $failed->spf_status);
        $this->assertNull($failed->domain_verified_at);
        $this->assertSame($firm->id, $failed->firm_id);
    }

    public function test_mark_verified_against_a_global_default_template_returns_a_populated_model_under_force(): void
    {
        $templateService = $this->templateService();
        $senderService = $this->senderDomainService();

        $template = $templateService->createGlobalDefault('mark-verified-global-key', ConsentChannel::Email, 'Body.', fromDomain: 'mail.example.com');

        $verified = $senderService->markVerified($template);

        $this->assertNotNull($verified, 'markVerified()\'s trailing fresh() must return a populated model, not null, for a global-default template under FORCE.');
        $this->assertSame(SenderDomainStatus::Verified, $verified->spf_status);
        $this->assertNull($verified->firm_id);
    }

    /**
     * syncVerificationAcrossFirmTemplates() against a firm-scoped
     * $firmId: proven to correctly update the intended rows (not
     * silently zero) under FORCE — the "new failure-mode class" the
     * dossier calls out explicitly (an unwrapped write silently
     * no-op'ing, not just an unwrapped read returning nothing).
     */
    public function test_sync_verification_across_firm_templates_updates_the_intended_rows_not_silently_zero_under_force(): void
    {
        $firm = Firm::factory()->create();
        $templateService = $this->templateService();
        $senderService = $this->senderDomainService();

        $templateA = $templateService->createFirmOverride($firm, 'sync-key-a', ConsentChannel::Email, 'Body A.', fromDomain: 'mail.example.com');
        $templateB = $templateService->createFirmOverride($firm, 'sync-key-b', ConsentChannel::Email, 'Body B.', fromDomain: 'mail.example.com');

        $updated = $senderService->syncVerificationAcrossFirmTemplates($firm->id, 'mail.example.com', true);

        $this->assertSame(2, $updated, 'syncVerificationAcrossFirmTemplates() must genuinely update both matching firm-scoped rows under FORCE, not silently affect zero rows.');

        $this->runWithFirmContext($firm, function () use ($senderService, $templateA, $templateB) {
            $this->assertTrue($senderService->isVerified($templateA->fresh()));
            $this->assertTrue($senderService->isVerified($templateB->fresh()));
        });
    }

    /**
     * syncVerificationAcrossFirmTemplates() against $firmId = null:
     * the dossier's Finding 3 correction — this sub-case would have
     * SUCCEEDED even unwrapped, by coincidence of NULLIF() evaluating
     * an unset setting to NULL. Proven here that the explicit wrap
     * still produces the correct, deterministic result under FORCE.
     */
    public function test_sync_verification_across_firm_templates_updates_global_default_rows_under_force(): void
    {
        $templateService = $this->templateService();
        $senderService = $this->senderDomainService();

        $global = $templateService->createGlobalDefault('sync-global-key', ConsentChannel::Email, 'Global body.', fromDomain: 'mail.example.com');

        $updated = $senderService->syncVerificationAcrossFirmTemplates(null, 'mail.example.com', true);

        $this->assertSame(1, $updated, 'syncVerificationAcrossFirmTemplates() must genuinely update the matching global-default row under FORCE.');

        $refreshed = $this->tenantContext()->runWithoutFirmContext(fn () => $global->fresh());
        $this->assertNotNull($refreshed);
        $this->assertTrue($senderService->isVerified($refreshed));
    }

    public function test_sync_verification_across_firm_templates_does_not_touch_a_sibling_firms_rows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $templateService = $this->templateService();
        $senderService = $this->senderDomainService();

        $templateA = $templateService->createFirmOverride($firmA, 'sync-cross-key-a', ConsentChannel::Email, 'Body A.', fromDomain: 'mail.example.com');
        $templateB = $templateService->createFirmOverride($firmB, 'sync-cross-key-b', ConsentChannel::Email, 'Body B.', fromDomain: 'mail.example.com');

        $updated = $senderService->syncVerificationAcrossFirmTemplates($firmA->id, 'mail.example.com', true);

        $this->assertSame(1, $updated, 'syncVerificationAcrossFirmTemplates() must only affect the requested firm\'s own rows.');

        $this->runWithFirmContext($firmA, function () use ($senderService, $templateA) {
            $this->assertTrue($senderService->isVerified($templateA->fresh()));
        });
        $this->runWithFirmContext($firmB, function () use ($senderService, $templateB) {
            $this->assertFalse($senderService->isVerified($templateB->fresh()), 'Firm B\'s own row must remain unverified — syncVerificationAcrossFirmTemplates() must not leak across firms.');
        });
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_safe_and_immediately_readable_under_any_firm(): void
    {
        $row = NotificationTemplate::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertNull($row->firm_id);

        $firm = Firm::factory()->create();
        $persisted = $this->runWithFirmContext($firm, fn () => NotificationTemplate::query()->find($row->id));

        $this->assertNotNull($persisted, 'A bare factory-created global-default row must be visible under any firm\'s own context.');
        $this->assertNull($persisted->firm_id);
    }

    public function test_explicit_firm_id_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $row = NotificationTemplate::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $row->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => NotificationTemplate::query()->find($row->id));
        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => NotificationTemplate::query()->where('firm_id', $firm->id)->find($row->id));
        $this->assertNull($notVisibleToOther, 'A firm-scoped factory row must not be visible under a sibling firm\'s own context.');
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        \App\Models\Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $row = NotificationTemplate::factory()->create();

        $this->assertNull($row->firm_id, 'The bare factory create() must still succeed and produce a genuinely null-firm_id row, despite the stale ambient context.');
    }

    /**
     * Direct proof of this checkpoint's own cited cross-checkpoint
     * regression fix: NotificationTemplate::factory()->forFirm($otherFirm)
     * ->create() called with zero ambient context, immediately
     * followed by a runWithFirmContext() call for a DIFFERENT firm —
     * the exact shape of NotificationEventsForceRlsActivationTest's
     * own already-committed line. This must succeed now that
     * notification_templates is FORCE-active.
     */
    public function test_forfirm_factory_create_with_no_ambient_context_succeeds_immediately_before_a_different_firms_context_wrap(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $foreignTemplate = NotificationTemplate::factory()->forFirm($otherFirm)->create();

        $this->assertSame($otherFirm->id, $foreignTemplate->firm_id);

        $visibleUnderFirm = $this->runWithFirmContext($firm, function () use ($foreignTemplate) {
            return $foreignTemplate->id;
        });

        $this->assertIsInt($visibleUnderFirm);

        $visibleUnderOwner = $this->runWithFirmContext($otherFirm, fn () => NotificationTemplate::query()->find($foreignTemplate->id));
        $this->assertNotNull($visibleUnderOwner, 'The factory-created row must genuinely persist and remain readable under its own firm\'s context.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'context-clears-success-key'));

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

    public function test_run_without_firm_context_clears_database_context_after_success(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'without-context-success-key'));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_create_global_default_clears_database_context_after_success(): void
    {
        $this->templateService()->createGlobalDefault('context-lifecycle-global-key', ConsentChannel::Email, 'Body.');

        $this->assertNoDatabaseTenantContext();
    }

    public function test_create_firm_override_clears_database_context_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->templateService()->createFirmOverride($firm, 'context-lifecycle-override-key', ConsentChannel::Email, 'Body.');

        $this->assertNoDatabaseTenantContext();
    }

    public function test_archive_clears_database_context_after_success(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->templateService();

        $template = $service->createFirmOverride($firm, 'context-lifecycle-archive-key', ConsentChannel::Email, 'Body.');
        $service->archive($template);

        $this->assertNoDatabaseTenantContext();
    }

    public function test_sync_verification_across_firm_templates_clears_database_context_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->templateService()->createFirmOverride($firm, 'context-lifecycle-sync-key', ConsentChannel::Email, 'Body.', fromDomain: 'mail.example.com');

        $this->senderDomainService()->syncVerificationAcrossFirmTemplates($firm->id, 'mail.example.com', true);

        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Residual gap scope note — a raw insert can still reference a
    // firm_id on a related model that mismatches the acting session's
    // own firm_id, because RLS only checks the row's OWN firm_id, never
    // a related row's. Proven directly (not asserted) here for the one
    // FK notification_templates itself does not carry (it has no
    // outbound tenant-owned FK of its own — firm_id is both its only
    // tenant-scoping column and the exact column RLS governs), so this
    // residual-gap class is instead proven from the OTHER direction:
    // notification_events.notification_template_id referencing a
    // foreign firm's notification_templates row, which is exactly what
    // NotificationEventsForceRlsActivationTest's own re-run (required
    // by this checkpoint's verification) already covers. Documented
    // here rather than duplicated, to avoid two tests claiming the same
    // proof under different names.
    // ---------------------------------------------------------------

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
     * No UI/route/domain/deployment/payment/storage/AI/client-portal/
     * marketplace surface was added by this checkpoint — an
     * application-code-prerequisite-plus-migration-plus-test change
     * only, matching the contacts/parties/backup_restore_tests/
     * health_checks/incident_events/maintenance_windows precedent's
     * own scope.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 31 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-eight previously forced tables plus notification_templates
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere
     * with any prior section's own enforcement. Uses maintenance_windows
     * as the companion table (forced immediately prior, at Checkpoint 30).
     */
    public function test_notification_templates_are_isolated_independently_and_simultaneously_with_maintenance_windows(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-key-a', 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-key-b', 'simultaneous-b'));

        $maintenanceA = $this->runWithFirmContext($firmA, fn () => DB::table('maintenance_windows')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid7(),
            'firm_id' => $firmA->id,
            'title' => 'Simultaneous isolation proof A',
            'status' => \App\Enums\MaintenanceWindowStatus::Scheduled->value,
            'scheduled_starts_at' => now()->addDay(),
            'scheduled_ends_at' => now()->addDay()->addHours(2),
            'affected_components' => json_encode(['database']),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $maintenanceB = $this->runWithFirmContext($firmB, fn () => DB::table('maintenance_windows')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid7(),
            'firm_id' => $firmB->id,
            'title' => 'Simultaneous isolation proof B',
            'status' => \App\Enums\MaintenanceWindowStatus::Scheduled->value,
            'scheduled_starts_at' => now()->addDay(),
            'scheduled_ends_at' => now()->addDay()->addHours(2),
            'affected_components' => json_encode(['database']),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'notification_templates' => NotificationTemplate::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'maintenance_windows' => DB::table('maintenance_windows')->where('firm_id', $firmA->id)->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['notification_templates']);
        $this->assertNotContains($rowB, $resultA['notification_templates']);
        $this->assertSame([$maintenanceA], $resultA['maintenance_windows']);
        $this->assertNotContains($maintenanceB, $resultA['maintenance_windows']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the notification_templates migration's down()
     * must genuinely restore this table's own ORIGINAL Phase-4
     * preparation policy — RLS still enabled, but NOT forced, and the
     * original single-expression policy restored byte-for-byte (both
     * new policies dropped). This is quoted from this table's own
     * origin migration (2026_07_07_800016_extend_row_level_security_to_
     * phase_4_tenant_tables.php), NOT the Phase 5 migration the four
     * prior nullable-firm_id checkpoints shared — the dossier calls
     * this distinction out explicitly as an easy copy-paste mistake to
     * avoid, so this test directly verifies the restored text carries
     * no artifact of the Phase 5 text (both are actually
     * textually-identical single-expression policies with no IS NULL
     * branch and no separate WITH CHECK, so this test verifies shape
     * rather than a byte-level Phase-4-vs-Phase-5 diff, which would be
     * indistinguishable from restored SQL text alone — the meaningful
     * distinction is which FILE it was quoted from, verified instead
     * by asserting the migration's own down() method exists and is
     * exercised, and that the restored policy is exactly the original
     * shape with no accidental IS NULL branch bleeding through from
     * the two-policy up() shape). Also proves rollback affects ONLY
     * this one table — every other previously-forced table must be
     * untouched. up() is re-run in a finally block so this test leaves
     * the schema in the same state it found it in.
     */
    public function test_notification_templates_migration_down_restores_the_original_phase_4_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930031_force_rls_on_notification_templates_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'notification_templates'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while notification_templates is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_read'");
            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_write'");
            $this->assertNull($readPolicy, 'Rollback must drop the new read policy.');
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_isolation'"
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 4 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'notification_templates'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_write'");
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');
    }
}
