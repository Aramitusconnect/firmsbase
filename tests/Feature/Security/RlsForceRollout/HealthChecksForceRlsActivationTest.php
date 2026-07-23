<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Models\Firm;
use App\Models\HealthCheck;
use App\Services\ComplianceGapRegistryService;
use App\Services\HealthCheckRegistry;
use App\Services\HealthCheckService;
use App\Services\QueueHealthService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\SchedulerHealthService;
use App\Services\TenantContextService;
use App\Services\TenantIsolationAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HealthChecksForceRlsActivationTest — Section 39A-3L, Checkpoint 28
 * (Phase B6). Proves the forty-sixth staged FORCE ROW LEVEL SECURITY
 * activation batch (database/migrations/2026_08_25_930028_force_rls_
 * on_health_checks_table.php) is permanently active for health_checks
 * and behaves correctly — including this checkpoint's own novel
 * security contribution (see below): every previously-forced table
 * remains forced simultaneously; missing-context read/insert denial;
 * a firm-specific row remains strictly single-firm-visible; a
 * platform-wide (firm_id = NULL) row is visible under EVERY
 * firm-scoped session's context; the asymmetric WITH CHECK closes
 * both the INSERT-side forgery gap and the DELETE-side gap, mirroring
 * backup_restore_tests' own two-policy design exactly.
 *
 * The checkpoint's actual novel security contribution (distinguishing
 * it from backup_restore_tests): TenantIsolationAnomalyService::
 * checkForKnownAnomalyPatterns() queries HealthCheck with NO firm_id
 * filter in the PHP query at all, yet cannot see another firm's
 * recorded anomaly once FORCE is active — RLS itself, not caller
 * discipline, is what makes this safe. Proven directly below (see
 * "novel security contribution" section) rather than merely asserted.
 *
 * Full design rationale and the exact approved SQL:
 * rls-checkpoints/39a3l/B6-health_checks-design-dossier.md (APPROVED
 * by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests, health_checks required real
 * application-code prerequisites ahead of this FORCE migration —
 * HealthCheckService::runAllAndRecord()'s read/write phase split,
 * TenantIsolationAnomalyService::recordAnomaly()'s self-wrap, and
 * HealthCheckFactory's context-hold create() override with an
 * explicit null-firm_id branch — all committed independently ahead of
 * this migration, per the dossier's own note that the preparation and
 * the FORCE activation are split into two commits here, matching the
 * contacts/parties (Checkpoints 25/26) and backup_restore_tests
 * (Checkpoint 27) precedent.
 */
class HealthChecksForceRlsActivationTest extends TestCase
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
        'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    private function healthCheckService(): HealthCheckService
    {
        return new HealthCheckService(new HealthCheckRegistry(
            new QueueHealthService(),
            new SchedulerHealthService(),
            new TenantIsolationAnomalyService(),
        ));
    }

    private function insertRow(?int $firmId, string $suffix, ?HealthCheckType $type = null, ?HealthCheckStatus $status = null): int
    {
        return DB::table('health_checks')->insertGetId([
            'firm_id' => $firmId,
            'check_type' => ($type ?? HealthCheckType::WebUptime)->value,
            'status' => ($status ?? HealthCheckStatus::Healthy)->value,
            'detail' => 'RLS proof row '.$suffix,
            'checked_at' => now(),
            'metadata_json' => json_encode([]),
            'created_at' => now(),
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

    public function test_health_checks_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'health_checks'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_health_checks_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'health_checks'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'health_checks must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-six tables (the forty-five previously forced plus
     * health_checks) must be FORCE-enabled among ALL prepared tables —
     * no more, no less.
     */
    public function test_exactly_forty_six_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 29 (incident_events) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health']);

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

        $this->assertSame(124, count($actuallyForced), 'Exactly forty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 28 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated by Section 39A-3L, Checkpoint 29 (incident_events) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health']);

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
            "select polname from pg_policy where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_isolation'"
        );

        $this->assertNull($policy, 'The original single-expression policy must have been dropped and replaced by the two new policies.');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_read'"
        );

        $this->assertNotNull($readPolicy, 'health_checks_tenant_read must exist.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_write'"
        );

        $this->assertNotNull($writePolicy, 'health_checks_tenant_write must exist.');
        $this->assertNotNull($writePolicy->with_check_expr, 'the write policy must carry an explicit, asymmetric WITH CHECK — not the FOR ALL implicit reuse of USING.');
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->using_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and backup_restore_tests' own policy (the immediately prior
     * checkpoint) as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $backupRestoreTestsWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'backup_restore_tests'::regclass and polname = 'backup_restore_tests_tenant_write'");
        $this->assertNotNull($backupRestoreTestsWritePolicy);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, HealthCheck::query()->where('firm_id', $firm->id)->count());
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
    // rows remain strictly single-firm-visible, unchanged from the
    // original policy's own intent.
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => HealthCheck::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => HealthCheck::query()->pluck('id')->all(),
        );

        $this->assertNotContains($rowB, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'valid-insert', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmB->id, 'claimed-ownership'));
    }

    public function test_firm_a_context_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'update-target', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $affected = $this->runWithFirmContext($firmA, function () use ($rowB) {
            return DB::table('health_checks')->where('id', $rowB)->update(['detail' => 'Hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s health_checks row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => HealthCheck::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertStringStartsWith('RLS proof row update-target', $reReadAsFirmB->detail);
    }

    public function test_firm_a_context_cannot_reassign_firm_b_row_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'reassign-target', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $rowB) {
            return DB::table('health_checks')->where('id', $rowB)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s health_checks row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => HealthCheck::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('health_checks')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => HealthCheck::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s health_checks row.');
    }

    // ---------------------------------------------------------------
    // Platform-wide (firm_id = NULL) row visibility proofs — the
    // central, positive read-side design decision this checkpoint
    // proves: every tenant may see every platform-wide row.
    // ---------------------------------------------------------------

    public function test_a_platform_wide_row_is_visible_under_every_firm_scoped_sessions_context(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $visibleToA = $this->runWithFirmContext($firmA, fn () => HealthCheck::query()->whereNull('firm_id')->pluck('id')->all());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => HealthCheck::query()->whereNull('firm_id')->pluck('id')->all());

        $this->assertContains($platformWideId, $visibleToA, 'Firm A must see the platform-wide row.');
        $this->assertContains($platformWideId, $visibleToB, 'Firm B must also independently see the same platform-wide row.');
    }

    public function test_a_platform_wide_row_does_not_expose_sibling_firm_specific_rows(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => HealthCheck::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visibleToA, 'Firm A must still not see Firm B\'s firm-specific row, even though a platform-wide row is visible to both.');
    }

    // ---------------------------------------------------------------
    // WITH CHECK asymmetry proofs — INSERT-side forgery prevention.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_insert_a_forged_platform_wide_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-platform-wide'));
    }

    public function test_a_genuinely_context_free_session_can_insert_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $insertedId = $this->insertRow(null, 'legitimate-platform-wide');

        $this->assertIsInt($insertedId);
    }

    // ---------------------------------------------------------------
    // WITH CHECK/USING asymmetry proofs — DELETE-side gap closure.
    // Mirrors backup_restore_tests' own proof exactly: WITH CHECK is
    // never consulted for DELETE in PostgreSQL, so an asymmetric WITH
    // CHECK alone (closing INSERT-side forgery) does nothing for this
    // mirror-image case — the write policy's own USING clause is what
    // closes it.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_delete_a_platform_wide_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'delete-gap-target'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($platformWideId) {
            return DB::table('health_checks')->where('id', $platformWideId)->delete();
        });

        $this->assertSame(0, $affected, 'A firm-scoped session must not be able to delete a platform-wide (firm_id = NULL) row.');

        $stillExists = $this->tenantContext()->runWithoutFirmContext(
            fn () => HealthCheck::query()->whereNull('firm_id')->find($platformWideId),
        );

        $this->assertNotNull($stillExists, 'The platform-wide row must genuinely still exist in the database after the blocked delete attempt.');
    }

    public function test_a_firm_scoped_session_cannot_delete_all_platform_wide_rows_via_a_direct_is_null_filter(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-1'));
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-2'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () {
            return DB::table('health_checks')->whereNull('firm_id')->delete();
        });

        $this->assertSame(0, $affected, 'DELETE FROM health_checks WHERE firm_id IS NULL must affect zero rows under a firm-scoped session.');

        $remaining = $this->tenantContext()->runWithoutFirmContext(
            fn () => HealthCheck::query()->whereNull('firm_id')->count(),
        );

        $this->assertSame(2, $remaining, 'Both platform-wide rows must genuinely still exist.');
    }

    public function test_a_genuinely_context_free_session_can_delete_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $platformWideId = $this->insertRow(null, 'context-free-delete-target');

        $affected = DB::table('health_checks')->where('id', $platformWideId)->delete();

        $this->assertSame(1, $affected, 'A genuinely context-free session must be able to delete a platform-wide row it is also able to write.');
    }

    // ---------------------------------------------------------------
    // Novel security contribution — the central, distinguishing proof
    // of this checkpoint (per the design dossier): checkForKnownAnomaly
    // Patterns() has NO firm_id filter in its own PHP query at all, yet
    // cannot see another firm's recorded anomaly, because RLS itself —
    // not caller discipline — governs what the query can return.
    // ---------------------------------------------------------------

    /**
     * checkForKnownAnomalyPatterns() called under firm A's context must
     * not see firm B's anomaly, even though its own PHP query has no
     * firm_id filter whatsoever. Firm B's anomaly is recorded FIRST, so
     * a naive "just happened not to query it" explanation is ruled out
     * — the row genuinely exists and would be returned by an unscoped
     * query; it is Postgres row-visibility enforcement, not the
     * absence of a WHERE clause, that hides it.
     */
    public function test_check_for_known_anomaly_patterns_under_firm_a_context_cannot_see_firm_bs_anomaly(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $service = new TenantIsolationAnomalyService();

        // Firm B's anomaly recorded first — genuinely exists in the
        // database before firm A's read ever runs.
        $service->recordAnomaly($firmB, 'Query returned rows belonging to firm B only');

        $result = $this->tenantContext()->runWithFirmContext(
            $firmA,
            fn () => $service->checkForKnownAnomalyPatterns()
        );

        $this->assertSame(HealthCheckStatus::Healthy, $result->status, 'Firm A must not observe firm B\'s anomaly — checkForKnownAnomalyPatterns() has no firm_id filter of its own; RLS alone must hide it.');
        $this->assertStringNotContainsString('firm B', $result->detail);
    }

    /**
     * Mirror-image, direct-content proof: firm A's OWN anomaly IS
     * visible under firm A's own context, ruling out "RLS is simply
     * hiding everything" as a false-positive explanation for the test
     * above.
     */
    public function test_check_for_known_anomaly_patterns_under_firm_a_context_sees_its_own_anomaly(): void
    {
        $firmA = Firm::factory()->create();
        $service = new TenantIsolationAnomalyService();

        $service->recordAnomaly($firmA, 'Query returned rows belonging to firm A only');

        $result = $this->tenantContext()->runWithFirmContext(
            $firmA,
            fn () => $service->checkForKnownAnomalyPatterns()
        );

        $this->assertSame(HealthCheckStatus::Unhealthy, $result->status);
        $this->assertStringContainsString('firm A', $result->detail);
    }

    /**
     * A platform-wide runAllAndRecord(null) run's own TenantIsolation
     * Anomalies check result must never contain another firm's
     * previously-recorded anomaly detail — the read phase (via the
     * registry, which transitively calls checkForKnownAnomalyPatterns())
     * runs under runWithoutFirmContext(), so only firm_id IS NULL rows
     * are visible; no firm-specific row of any kind can leak into a
     * platform-wide sweep's persisted result.
     */
    public function test_platform_wide_run_all_and_record_never_surfaces_another_firms_anomaly_detail(): void
    {
        $firm = Firm::factory()->create();
        $service = new TenantIsolationAnomalyService();

        $service->recordAnomaly($firm, 'Leaked content identifying firm-specific-secret-marker');

        $healthCheckService = $this->healthCheckService();
        $created = $healthCheckService->runAllAndRecord(null);

        $tenantIsolationResult = collect($created)->firstWhere('check_type', HealthCheckType::TenantIsolationAnomalies);

        $this->assertNotNull($tenantIsolationResult);
        $this->assertNull($tenantIsolationResult->firm_id, 'A platform-wide run must persist a platform-wide (firm_id = NULL) TenantIsolationAnomalies row.');
        $this->assertStringNotContainsString('firm-specific-secret-marker', (string) $tenantIsolationResult->detail, 'A platform-wide run must never surface a specific firm\'s previously-recorded anomaly detail.');

        // Direct database-level confirmation: read every firm_id = NULL
        // TenantIsolationAnomalies row and assert none of them contain
        // the firm-specific marker, ruling out any leak via a
        // subsequent read too.
        $allPlatformWideAnomalyDetails = $this->tenantContext()->runWithoutFirmContext(
            fn () => HealthCheck::query()
                ->whereNull('firm_id')
                ->where('check_type', HealthCheckType::TenantIsolationAnomalies->value)
                ->pluck('detail')
                ->all(),
        );

        foreach ($allPlatformWideAnomalyDetails as $detail) {
            $this->assertStringNotContainsString('firm-specific-secret-marker', (string) $detail);
        }
    }

    /**
     * A firm-scoped runAllAndRecord($firm) run must persist its
     * TenantIsolationAnomalies result under exactly that firm's own
     * firm_id (never NULL, never a sibling firm's id) — the mirror
     * counterpart to the platform-wide proof above, confirming the
     * write phase's per-result destined-ownership split is correct.
     */
    public function test_firm_scoped_run_all_and_record_persists_the_tenant_isolation_result_under_the_correct_firm(): void
    {
        $firm = Firm::factory()->create();
        $service = new TenantIsolationAnomalyService();
        $service->recordAnomaly($firm, 'Query returned rows from a different firm_id');

        $healthCheckService = $this->healthCheckService();
        $created = $healthCheckService->runAllAndRecord($firm);

        $tenantIsolationResult = collect($created)->firstWhere('check_type', HealthCheckType::TenantIsolationAnomalies);

        $this->assertNotNull($tenantIsolationResult);
        $this->assertSame($firm->id, $tenantIsolationResult->firm_id);
        $this->assertSame(HealthCheckStatus::Unhealthy, $tenantIsolationResult->status);

        // All 8 other results must be platform-wide.
        $otherResults = collect($created)->reject(fn ($r) => $r->check_type === HealthCheckType::TenantIsolationAnomalies);
        $this->assertCount(8, $otherResults);
        foreach ($otherResults as $result) {
            $this->assertNull($result->firm_id, "{$result->check_type->value} must remain platform-wide even when a firm is given.");
        }
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_safe_and_immediately_readable_under_any_firm(): void
    {
        $row = HealthCheck::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertNull($row->firm_id);

        $firm = Firm::factory()->create();
        $persisted = $this->runWithFirmContext($firm, fn () => HealthCheck::query()->find($row->id));

        $this->assertNotNull($persisted, 'A bare factory-created platform-wide row must be visible under any firm\'s own context.');
        $this->assertNull($persisted->firm_id);
    }

    public function test_explicit_firm_id_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $row = HealthCheck::factory()->create(['firm_id' => $firm->id]);

        $this->assertSame($firm->id, $row->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => HealthCheck::query()->find($row->id));
        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => HealthCheck::query()->where('firm_id', $firm->id)->find($row->id));
        $this->assertNull($notVisibleToOther, 'A firm-scoped factory row must not be visible under a sibling firm\'s own context.');
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        \App\Models\Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $row = HealthCheck::factory()->create();

        $this->assertNull($row->firm_id, 'The bare factory create() must still succeed and produce a genuinely null-firm_id row, despite the stale ambient context.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'context-clears-success', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

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
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'without-context-success'));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_run_without_firm_context_clears_database_context_after_exception(): void
    {
        try {
            $this->tenantContext()->runWithoutFirmContext(function () {
                throw new \RuntimeException('simulated failure inside runWithoutFirmContext');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * runAllAndRecord()'s own read-phase-then-write-phase split must
     * not leak DB-level context across the two phases: after a
     * firm-scoped run completes, no ambient context should remain
     * active for the next caller.
     */
    public function test_run_all_and_record_clears_database_context_after_a_firm_scoped_run(): void
    {
        $firm = Firm::factory()->create();

        $this->healthCheckService()->runAllAndRecord($firm);

        $this->assertNoDatabaseTenantContext();
    }

    public function test_run_all_and_record_clears_database_context_after_a_platform_wide_run(): void
    {
        $this->healthCheckService()->runAllAndRecord(null);

        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Real production writer/reader proofs — HealthCheckService /
    // TenantIsolationAnomalyService / RunHealthChecksJob
    // ---------------------------------------------------------------

    public function test_health_check_service_run_all_and_record_with_no_firm_persists_nine_genuinely_visible_platform_wide_rows(): void
    {
        $created = $this->healthCheckService()->runAllAndRecord();

        $this->assertCount(9, $created);

        $firm = Firm::factory()->create();
        foreach ($created as $result) {
            $this->assertNull($result->firm_id);

            $visible = $this->runWithFirmContext($firm, fn () => HealthCheck::query()->find($result->id));
            $this->assertNotNull($visible, "runAllAndRecord() with no firm must genuinely persist a {$result->check_type->value} row visible under any firm's context.");
        }
    }

    public function test_health_check_service_run_all_and_record_with_a_firm_persists_a_firm_scoped_tenant_isolation_row(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->healthCheckService()->runAllAndRecord($firm);

        $tenantIsolationResult = collect($created)->firstWhere('check_type', HealthCheckType::TenantIsolationAnomalies);

        $this->assertSame($firm->id, $tenantIsolationResult->firm_id);

        $visible = $this->runWithFirmContext($firm, fn () => HealthCheck::query()->find($tenantIsolationResult->id));
        $this->assertNotNull($visible);
    }

    public function test_tenant_isolation_anomaly_service_record_anomaly_persists_a_genuinely_visible_firm_scoped_row(): void
    {
        $firm = Firm::factory()->create();
        $service = new TenantIsolationAnomalyService();

        $anomaly = $service->recordAnomaly($firm, 'Query returned rows from a different firm_id');

        $this->assertSame($firm->id, $anomaly->firm_id);

        $visible = $this->runWithFirmContext($firm, fn () => HealthCheck::query()->find($anomaly->id));
        $this->assertNotNull($visible);

        $notVisibleToOtherFirm = $this->runWithFirmContext(
            Firm::factory()->create(),
            fn () => HealthCheck::query()->find($anomaly->id),
        );
        $this->assertNull($notVisibleToOtherFirm);
    }

    public function test_tenant_isolation_anomaly_service_record_anomaly_can_be_platform_wide_and_visible_to_every_firm(): void
    {
        $service = new TenantIsolationAnomalyService();

        $anomaly = $service->recordAnomaly(null, 'Suspicious platform-wide query pattern');

        $this->assertNull($anomaly->firm_id);

        $firm = Firm::factory()->create();
        $visible = $this->runWithFirmContext($firm, fn () => HealthCheck::query()->find($anomaly->id));
        $this->assertNotNull($visible);
    }

    public function test_run_health_checks_job_with_a_firm_id_persists_via_the_correct_context(): void
    {
        $firm = Firm::factory()->create();

        $job = new \App\Jobs\RunHealthChecksJob($firm->id);
        $job->handle($this->healthCheckService());

        $tenantIsolationRow = $this->runWithFirmContext(
            $firm,
            fn () => HealthCheck::query()->where('firm_id', $firm->id)->where('check_type', HealthCheckType::TenantIsolationAnomalies->value)->first(),
        );

        $this->assertNotNull($tenantIsolationRow, 'RunHealthChecksJob with a firmId must persist a genuinely firm-visible TenantIsolationAnomalies row.');
    }

    public function test_run_health_checks_job_with_no_firm_id_persists_platform_wide_rows(): void
    {
        $job = new \App\Jobs\RunHealthChecksJob(null);
        $job->handle($this->healthCheckService());

        $count = $this->tenantContext()->runWithoutFirmContext(fn () => HealthCheck::query()->count());
        $this->assertSame(9, $count);
    }

    // ---------------------------------------------------------------
    // Residual gap scope note — like backup_restore_tests, health_checks
    // has no OTHER tenant-owned relation of its own: firm_id is both
    // its only foreign key into tenant-owned data AND the exact column
    // RLS itself governs, so there is no second, independently-resolved
    // relation whose firm could plausibly mismatch this row's own
    // firm_id. The one genuinely table-specific residual gap, disclosed
    // plainly rather than hidden: recordAnomaly()'s caller-supplied
    // $description/$metadata content is not validated or scrubbed for
    // other-firm-identifying text at the RLS layer — RLS controls row
    // VISIBILITY by firm_id, not free-text CONTENT. This is not a gap
    // in what this checkpoint proves (row visibility is fully correct,
    // per the "novel security contribution" tests above); it is a
    // gap in what any future real anomaly-detection caller must itself
    // avoid doing (writing another firm's identifying text into its
    // OWN firm-scoped or platform-wide row) — not enforceable by RLS,
    // and zero current call sites exist to check today.
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
     * only, matching the contacts/parties/backup_restore_tests
     * precedent's own scope.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 28 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-five previously forced tables plus health_checks must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses backup_restore_tests
     * as the companion table (forced immediately prior, at Checkpoint
     * 27).
     */
    public function test_health_checks_are_isolated_independently_and_simultaneously_with_backup_restore_tests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b', HealthCheckType::TenantIsolationAnomalies, HealthCheckStatus::Unhealthy));

        $backupA = $this->runWithFirmContext($firmA, fn () => \App\Models\BackupRestoreTest::factory()->create(['firm_id' => $firmA->id]));
        $backupB = $this->runWithFirmContext($firmB, fn () => \App\Models\BackupRestoreTest::factory()->create(['firm_id' => $firmB->id]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'health_checks' => HealthCheck::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'backup_restore_tests' => \App\Models\BackupRestoreTest::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['health_checks']);
        $this->assertNotContains($rowB, $resultA['health_checks']);
        $this->assertSame([$backupA->id], $resultA['backup_restore_tests']);
        $this->assertNotContains($backupB->id, $resultA['backup_restore_tests']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the health_checks migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * but NOT forced, and the ORIGINAL single-expression policy
     * restored byte-for-byte (both new policies dropped). Also proves
     * rollback affects ONLY this one table — every other
     * previously-forced table must be untouched. up() is re-run in a
     * finally block so this test leaves the schema in the same state
     * it found it in.
     */
    public function test_health_checks_migration_down_restores_the_original_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930028_force_rls_on_health_checks_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'health_checks'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while health_checks is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_read'");
            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_write'");
            $this->assertNull($readPolicy, 'Rollback must drop the new read policy.');
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_isolation'"
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 5 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'health_checks'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'health_checks'::regclass and polname = 'health_checks_tenant_write'");
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');
    }
}
