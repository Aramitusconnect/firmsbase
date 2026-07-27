<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MaintenanceWindowStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\MaintenanceWindow;
use App\Services\ComplianceGapRegistryService;
use App\Services\MaintenanceWindowService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MaintenanceWindowsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 30 (Phase B6). Proves the forty-eighth staged FORCE ROW LEVEL
 * SECURITY activation batch (database/migrations/2026_08_25_930030_
 * force_rls_on_maintenance_windows_table.php) is permanently active
 * for maintenance_windows and behaves correctly — including this
 * checkpoint's own novel contribution (see below): every previously-
 * forced table remains forced simultaneously; missing-context read/
 * insert denial; a firm-specific row remains strictly single-firm-
 * visible; a platform-wide (firm_id = NULL) row is visible under EVERY
 * firm-scoped session's context; the asymmetric WITH CHECK closes both
 * the INSERT-side forgery gap and the DELETE-side gap, mirroring
 * backup_restore_tests'/health_checks'/incident_events' own two-policy
 * design exactly.
 *
 * The checkpoint's actual novel contribution (distinguishing it from
 * all three prior tables, and genuinely NEW in this arc — neither
 * health_checks' mixed-ownership-batch problem nor incident_events'
 * chicken-and-egg ownership-discovery problem): this is the FIRST time
 * ANY test exercises a non-null-firm_id maintenance window at all —
 * every pre-existing MaintenanceWindowServiceTest case used firm_id =
 * null exclusively. start(), complete(), cancel(), and
 * markCustomerNotificationSent() all follow the shape
 * `$window->update([...]); return $window->fresh();` — Model::fresh()
 * issues a NEW SELECT by primary key, which is only visible under FORCE
 * RLS if the correct context is STILL active at that point. This test
 * file directly proves the application-code prerequisite's wrap-must-
 * extend-through-fresh() fix actually works (not just that it was
 * written) via a full schedule -> start -> complete lifecycle exercised
 * against a firm-scoped window, plus cancel() and
 * markCustomerNotificationSent() exercised the same way, plus
 * reschedule() proven to carry the original window's exact firm_id
 * forward onto the new row it creates.
 *
 * Full design rationale and the exact approved SQL:
 * rls-checkpoints/39a3l/B6-maintenance_windows-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests/health_checks/incident_events,
 * maintenance_windows required real application-code prerequisites
 * ahead of this FORCE migration — MaintenanceWindowService's four
 * affected methods gaining a context wrap that extends through their
 * trailing ->fresh() re-read, and MaintenanceWindowFactory's
 * context-hold create() override with an explicit null-firm_id branch
 * — all committed independently ahead of this migration, per the
 * dossier's own note that the preparation and the FORCE activation are
 * split into two commits here, matching the contacts/parties
 * (Checkpoints 25/26) and backup_restore_tests/health_checks/
 * incident_events (Checkpoints 27/28/29) precedent.
 */
class MaintenanceWindowsForceRlsActivationTest extends TestCase
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
        'health_checks', 'incident_events',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService;
    }

    private function maintenanceWindowService(): MaintenanceWindowService
    {
        return new MaintenanceWindowService;
    }

    private function insertRow(?int $firmId, string $suffix, ?MaintenanceWindowStatus $status = null): int
    {
        return DB::table('maintenance_windows')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firmId,
            'title' => 'RLS proof row '.$suffix,
            'status' => ($status ?? MaintenanceWindowStatus::Scheduled)->value,
            'scheduled_starts_at' => now()->addDay(),
            'scheduled_ends_at' => now()->addDay()->addHours(2),
            'affected_components' => json_encode(['database']),
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

    public function test_maintenance_windows_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'maintenance_windows'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_maintenance_windows_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'maintenance_windows'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'maintenance_windows must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-eight tables (the forty-seven previously forced
     * plus maintenance_windows) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     */
    public function test_exactly_forty_eight_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions']);

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

        $this->assertSame(126, count($actuallyForced), 'Exactly forty-eight prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 30 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions']);

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
            "select polname from pg_policy where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_isolation'"
        );

        $this->assertNull($policy, 'The original single-expression policy must have been dropped and replaced by the two new policies.');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_read'"
        );

        $this->assertNotNull($readPolicy, 'maintenance_windows_tenant_read must exist.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_write'"
        );

        $this->assertNotNull($writePolicy, 'maintenance_windows_tenant_write must exist.');
        $this->assertNotNull($writePolicy->with_check_expr, 'the write policy must carry an explicit, asymmetric WITH CHECK — not the FOR ALL implicit reuse of USING.');
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->using_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and incident_events' own policy (the immediately prior
     * checkpoint) as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $incidentEventsWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'incident_events'::regclass and polname = 'incident_events_tenant_write'");
        $this->assertNotNull($incidentEventsWritePolicy);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, MaintenanceWindow::query()->where('firm_id', $firm->id)->count());
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
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MaintenanceWindow::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MaintenanceWindow::query()->pluck('id')->all(),
        );

        $this->assertNotContains($rowB, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'valid-insert'));

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
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'update-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($rowB) {
            return DB::table('maintenance_windows')->where('id', $rowB)->update(['title' => 'Hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s maintenance_windows row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MaintenanceWindow::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertStringStartsWith('RLS proof row update-target', $reReadAsFirmB->title);
    }

    public function test_firm_a_context_cannot_reassign_firm_b_row_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'reassign-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $rowB) {
            return DB::table('maintenance_windows')->where('id', $rowB)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s maintenance_windows row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MaintenanceWindow::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target'));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('maintenance_windows')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MaintenanceWindow::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s maintenance_windows row.');
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

        $visibleToA = $this->runWithFirmContext($firmA, fn () => MaintenanceWindow::query()->whereNull('firm_id')->pluck('id')->all());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => MaintenanceWindow::query()->whereNull('firm_id')->pluck('id')->all());

        $this->assertContains($platformWideId, $visibleToA, 'Firm A must see the platform-wide row.');
        $this->assertContains($platformWideId, $visibleToB, 'Firm B must also independently see the same platform-wide row.');
    }

    public function test_a_platform_wide_row_does_not_expose_sibling_firm_specific_rows(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked'));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => MaintenanceWindow::query()->pluck('id')->all());

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
    // WITH CHECK is never consulted for DELETE in PostgreSQL, so an
    // asymmetric WITH CHECK alone (closing INSERT-side forgery) does
    // nothing for this mirror-image case — the write policy's own
    // USING clause is what closes it.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_delete_a_platform_wide_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'delete-gap-target'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($platformWideId) {
            return DB::table('maintenance_windows')->where('id', $platformWideId)->delete();
        });

        $this->assertSame(0, $affected, 'A firm-scoped session must not be able to delete a platform-wide (firm_id = NULL) row.');

        $stillExists = $this->tenantContext()->runWithoutFirmContext(
            fn () => MaintenanceWindow::query()->whereNull('firm_id')->find($platformWideId),
        );

        $this->assertNotNull($stillExists, 'The platform-wide row must genuinely still exist in the database after the blocked delete attempt.');
    }

    public function test_a_firm_scoped_session_cannot_delete_all_platform_wide_rows_via_a_direct_is_null_filter(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-1'));
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-2'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () {
            return DB::table('maintenance_windows')->whereNull('firm_id')->delete();
        });

        $this->assertSame(0, $affected, 'DELETE FROM maintenance_windows WHERE firm_id IS NULL must affect zero rows under a firm-scoped session.');

        $remaining = $this->tenantContext()->runWithoutFirmContext(
            fn () => MaintenanceWindow::query()->whereNull('firm_id')->count(),
        );

        $this->assertSame(2, $remaining, 'Both platform-wide rows must genuinely still exist.');
    }

    public function test_a_genuinely_context_free_session_can_delete_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $platformWideId = $this->insertRow(null, 'context-free-delete-target');

        $affected = DB::table('maintenance_windows')->where('id', $platformWideId)->delete();

        $this->assertSame(1, $affected, 'A genuinely context-free session must be able to delete a platform-wide row it is also able to write.');
    }

    /**
     * Direct SQL-level proof a firm-scoped session cannot write into a
     * sibling firm's firm_id via UPDATE — mirror of the INSERT-side
     * forgery proof above, exercised through UPDATE ... SET firm_id.
     * Unlike the cross-firm UPDATE proof above (where the target row
     * is invisible under USING and the UPDATE silently affects zero
     * rows), this row IS visible under USING (firm A owns it) — the
     * failure instead comes from WITH CHECK rejecting the resulting
     * new row (firm_id = firm B) outright, raising a hard
     * row-level-security QueryException rather than returning 0.
     */
    public function test_a_firm_scoped_session_cannot_update_its_own_row_to_claim_sibling_firm_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'reassign-to-sibling'));

        try {
            $this->runWithFirmContext($firmA, function () use ($firmB, $rowA) {
                return DB::table('maintenance_windows')->where('id', $rowA)->update(['firm_id' => $firmB->id]);
            });
            $this->fail('Expected a row-level security policy violation when Firm A tries to reassign its own row to Firm B.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('row-level security policy', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext();

        $stillFirmAs = $this->runWithFirmContext($firmA, fn () => MaintenanceWindow::query()->find($rowA));
        $this->assertNotNull($stillFirmAs);
        $this->assertSame($firmA->id, $stillFirmAs->firm_id);
    }

    // ---------------------------------------------------------------
    // Novel security contribution — the wrap-must-extend-through-
    // fresh() fix, proven directly against a firm-scoped window. This
    // is the FIRST time any test in this repo exercises a non-null-
    // firm_id maintenance window's full lifecycle.
    // ---------------------------------------------------------------

    public function test_full_lifecycle_schedule_start_complete_against_a_firm_scoped_window_returns_populated_models_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->maintenanceWindowService();

        $window = $service->schedule($firm, 'Firm-scoped API upgrade', now()->addHour(), now()->addHours(2));

        $this->assertSame($firm->id, $window->firm_id);
        $this->assertSame(MaintenanceWindowStatus::Scheduled, $window->status);

        $started = $service->start($window);

        $this->assertNotNull($started, 'start()\'s trailing fresh() must return a populated model, not null, for a firm-scoped window under FORCE.');
        $this->assertSame(MaintenanceWindowStatus::InProgress, $started->status);
        $this->assertNotNull($started->actual_starts_at);
        $this->assertSame($firm->id, $started->firm_id);

        $completed = $service->complete($started);

        $this->assertNotNull($completed, 'complete()\'s trailing fresh() must return a populated model, not null, for a firm-scoped window under FORCE.');
        $this->assertSame(MaintenanceWindowStatus::Completed, $completed->status);
        $this->assertNotNull($completed->actual_ends_at);
        $this->assertSame($firm->id, $completed->firm_id);
    }

    public function test_cancel_against_a_firm_scoped_window_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->maintenanceWindowService();

        $window = $service->schedule($firm, 'Firm-scoped migration', now()->addDay(), now()->addDay()->addHour());

        $cancelled = $service->cancel($window, 'No longer needed');

        $this->assertNotNull($cancelled, 'cancel()\'s trailing fresh() must return a populated model, not null, for a firm-scoped window under FORCE.');
        $this->assertSame(MaintenanceWindowStatus::Cancelled, $cancelled->status);
        $this->assertSame('No longer needed', $cancelled->cancellation_reason);
        $this->assertSame($firm->id, $cancelled->firm_id);
    }

    public function test_mark_customer_notification_sent_against_a_firm_scoped_window_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->maintenanceWindowService();

        $window = $service->schedule($firm, 'Firm-scoped upgrade', now()->addDay(), now()->addDay()->addHour());

        $this->assertFalse($window->customerNotificationSent());

        $notified = $service->markCustomerNotificationSent($window);

        $this->assertNotNull($notified, 'markCustomerNotificationSent()\'s trailing fresh() must return a populated model, not null, for a firm-scoped window under FORCE.');
        $this->assertTrue($notified->customerNotificationSent());
        $this->assertSame($firm->id, $notified->firm_id);
    }

    /**
     * reschedule() specifically: the new row must inherit the EXACT
     * same firm_id as the original — proven under a firm-scoped
     * context, where a mismatch would either be invisible (wrong
     * context) or rejected outright by WITH CHECK (forged ownership).
     */
    public function test_reschedule_against_a_firm_scoped_window_creates_a_new_row_with_the_same_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->maintenanceWindowService();

        $original = $service->schedule($firm, 'Firm-scoped storage migration', now()->addDay(), now()->addDay()->addHours(3));
        $originalScheduledStart = $original->scheduled_starts_at;

        $newStart = now()->addWeek();
        $newEnd = now()->addWeek()->addHours(3);

        $rescheduled = $service->reschedule($original, $newStart, $newEnd);

        $this->assertNotNull($rescheduled);
        $this->assertSame($firm->id, $rescheduled->firm_id, 'The new row must inherit the exact same firm_id as the original.');
        $this->assertSame(MaintenanceWindowStatus::Scheduled, $rescheduled->status);
        $this->assertSame($original->id, $rescheduled->rescheduled_from_id);

        $refreshedOriginal = $this->runWithFirmContext($firm, fn () => MaintenanceWindow::query()->find($original->id));
        $this->assertNotNull($refreshedOriginal, 'The original row itself must remain readable under the firm\'s own context.');
        $this->assertSame(MaintenanceWindowStatus::Rescheduled, $refreshedOriginal->status);
        $this->assertTrue($refreshedOriginal->scheduled_starts_at->equalTo($originalScheduledStart));
        $this->assertSame($firm->id, $refreshedOriginal->firm_id);

        // Direct database-level confirmation both rows genuinely share
        // one firm_id — not merely equal in-memory attribute values
        // that could mask a stale/uncommitted read.
        $distinctFirmIds = $this->runWithFirmContext($firm, fn () => DB::table('maintenance_windows')
            ->whereIn('id', [$original->id, $rescheduled->id])
            ->distinct()
            ->pluck('firm_id'));

        $this->assertCount(1, $distinctFirmIds);
        $this->assertSame($firm->id, $distinctFirmIds->first());
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_safe_and_immediately_readable_under_any_firm(): void
    {
        $row = MaintenanceWindow::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertNull($row->firm_id);

        $firm = Firm::factory()->create();
        $persisted = $this->runWithFirmContext($firm, fn () => MaintenanceWindow::query()->find($row->id));

        $this->assertNotNull($persisted, 'A bare factory-created platform-wide row must be visible under any firm\'s own context.');
        $this->assertNull($persisted->firm_id);
    }

    public function test_explicit_firm_id_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $row = MaintenanceWindow::factory()->create(['firm_id' => $firm->id]);

        $this->assertSame($firm->id, $row->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => MaintenanceWindow::query()->find($row->id));
        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => MaintenanceWindow::query()->where('firm_id', $firm->id)->find($row->id));
        $this->assertNull($notVisibleToOther, 'A firm-scoped factory row must not be visible under a sibling firm\'s own context.');
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $row = MaintenanceWindow::factory()->create();

        $this->assertNull($row->firm_id, 'The bare factory create() must still succeed and produce a genuinely null-firm_id row, despite the stale ambient context.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'context-clears-success'));

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

    public function test_maintenance_window_service_methods_clear_database_context_after_a_firm_scoped_operation(): void
    {
        $firm = Firm::factory()->create();

        $this->maintenanceWindowService()->schedule($firm, 'Context lifecycle proof', now()->addDay(), now()->addDay()->addHour());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_maintenance_window_service_methods_clear_database_context_after_a_platform_wide_operation(): void
    {
        $this->maintenanceWindowService()->schedule(null, 'Context lifecycle proof, platform-wide', now()->addDay(), now()->addDay()->addHour());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_full_lifecycle_against_a_firm_scoped_window_clears_context_after_every_step(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->maintenanceWindowService();

        $window = $service->schedule($firm, 'Context lifecycle, full trip', now()->addHour(), now()->addHours(2));
        $this->assertNoDatabaseTenantContext();

        $started = $service->start($window);
        $this->assertNoDatabaseTenantContext();

        $service->complete($started);
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Real production writer/reader proofs — MaintenanceWindowService
    // ---------------------------------------------------------------

    public function test_schedule_with_no_firm_persists_a_genuinely_visible_platform_wide_row(): void
    {
        $window = $this->maintenanceWindowService()->schedule(null, 'Shared infrastructure upgrade', now()->addDay(), now()->addDay()->addHours(2));

        $this->assertNull($window->firm_id);

        $firm = Firm::factory()->create();
        $visible = $this->runWithFirmContext($firm, fn () => MaintenanceWindow::query()->find($window->id));
        $this->assertNotNull($visible, 'schedule() with no firm must genuinely persist a row visible under any firm\'s context.');
    }

    public function test_schedule_with_a_firm_persists_a_firm_scoped_row_invisible_to_a_sibling(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $window = $this->maintenanceWindowService()->schedule($firm, 'Dedicated deployment upgrade', now()->addDay(), now()->addDay()->addHours(2));

        $this->assertSame($firm->id, $window->firm_id);

        $visible = $this->runWithFirmContext($firm, fn () => MaintenanceWindow::query()->find($window->id));
        $this->assertNotNull($visible);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => MaintenanceWindow::query()->find($window->id));
        $this->assertNull($notVisibleToOther);
    }

    // ---------------------------------------------------------------
    // Residual gap scope note — maintenance_windows has no OTHER
    // tenant-owned relation of its own that RLS does not already
    // govern: firm_id is both its only foreign key into tenant-owned
    // data AND the exact column RLS itself governs (rescheduled_from_id
    // is a self-FK, always sharing the same firm_id by construction —
    // never independently derived). Zero existing pre-prerequisite test
    // coverage of a non-null-firm_id window's full lifecycle existed
    // before this checkpoint's own new activation test above — the
    // first place this path is exercised at all.
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService;

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
     * health_checks/incident_events precedent's own scope.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 30 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-seven previously forced tables plus maintenance_windows
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere
     * with any prior section's own enforcement. Uses incident_events as
     * the companion table (forced immediately prior, at Checkpoint 29).
     */
    public function test_maintenance_windows_are_isolated_independently_and_simultaneously_with_incident_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b'));

        $incidentA = $this->runWithFirmContext($firmA, fn () => DB::table('incident_events')->insertGetId([
            'firm_id' => $firmA->id,
            'correlation_id' => (string) Str::uuid(),
            'event_type' => 'opened',
            'severity' => IncidentSeverity::Medium->value,
            'status' => IncidentStatus::Investigating->value,
            'customer_impact' => false,
            'notification_needed' => false,
            'message' => 'Simultaneous isolation proof A',
            'created_at' => now(),
        ]));
        $incidentB = $this->runWithFirmContext($firmB, fn () => DB::table('incident_events')->insertGetId([
            'firm_id' => $firmB->id,
            'correlation_id' => (string) Str::uuid(),
            'event_type' => 'opened',
            'severity' => IncidentSeverity::Medium->value,
            'status' => IncidentStatus::Investigating->value,
            'customer_impact' => false,
            'notification_needed' => false,
            'message' => 'Simultaneous isolation proof B',
            'created_at' => now(),
        ]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'maintenance_windows' => MaintenanceWindow::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'incident_events' => DB::table('incident_events')->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['maintenance_windows']);
        $this->assertNotContains($rowB, $resultA['maintenance_windows']);
        $this->assertContains($incidentA, $resultA['incident_events']);
        $this->assertNotContains($incidentB, $resultA['incident_events']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the maintenance_windows migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * but NOT forced, and the ORIGINAL single-expression policy
     * restored byte-for-byte (both new policies dropped). Also proves
     * rollback affects ONLY this one table — every other
     * previously-forced table must be untouched. up() is re-run in a
     * finally block so this test leaves the schema in the same state
     * it found it in.
     */
    public function test_maintenance_windows_migration_down_restores_the_original_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930030_force_rls_on_maintenance_windows_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'maintenance_windows'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while maintenance_windows is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_read'");
            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_write'");
            $this->assertNull($readPolicy, 'Rollback must drop the new read policy.');
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_isolation'"
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 5 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'maintenance_windows'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'maintenance_windows'::regclass and polname = 'maintenance_windows_tenant_write'");
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');
    }
}
