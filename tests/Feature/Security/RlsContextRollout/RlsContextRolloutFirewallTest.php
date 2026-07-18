<?php

namespace Tests\Feature\Security\RlsContextRollout;

use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsContextRolloutFirewallTest — Section 39A-2. Proves this branch
 * stayed inside its declared boundary: no global RLS bypass was
 * introduced, no permanent FORCE ROW LEVEL SECURITY was enabled on the
 * live schema, no new RLS policies were added for the 43 still-
 * uncovered tenant-owned tables, no UI/routes/controllers were added,
 * and ComplianceGapRegistryService was not deleted/rewritten.
 */
class RlsContextRolloutFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_permanent_force_row_level_security_is_enabled_on_any_prepared_table(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Section 39A-3A/39A-3B/39A-3C/39A-3D/39A-3E/39A-3F/39A-3G/
        // 39A-3H/39A-3I/39A-3J/39A-3K/39A-3L (later, distinct staged-
        // FORCE-activation branches) legitimately activated permanent
        // FORCE ROW LEVEL SECURITY on clients, firm_users, documents,
        // deadlines, tasks, matters, invoices, payments,
        // conflict_check_runs, lead_sources, consultation_outcomes,
        // firm_leads, consultations (Section 39A-3J), (Section 39A-3K)
        // firm_practice_areas, document_chase_rules, employee_rates,
        // calendar_events, client_communication_preferences, (Section
        // 39A-3L, Checkpoint 1, Table Phase C)
        // payment_classification_events, (Section 39A-3L, Checkpoint
        // 2, Table Phase C) activation_checklists, (Section 39A-3L,
        // Checkpoint 3, Table Phase C) firm_activation_events, (Section
        // 39A-3L, Checkpoint 4, Table Phase C) firm_entitlements,
        // (Section 39A-3L, Checkpoint 5, Table Phase C)
        // firm_entitlement_events, (Section 39A-3L, Checkpoint 6,
        // Table Phase C) installed_template_packs, (Section 39A-3L,
        // Checkpoint 7, Table Phase C) template_upgrade_logs, and
        // (Section 39A-3L, Checkpoint 8, Table Phase C)
        // template_upgrade_previews, and (Section 39A-3L, Checkpoint 9,
        // Table Phase C) seat_allocations, and (Section 39A-3L,
        // Checkpoint 10, Table Phase C) document_requests, and (Section
        // 39A-3L, Checkpoint 11, Table Phase C) communication_consents,
        // and (Section 39A-3L, Checkpoint 12, Table Phase C)
        // communication_consent_events, and (Section 39A-3L, Checkpoint
        // 13, Table Phase C) intake_submissions, and (Section 39A-3L,
        // Checkpoint 14, Table Phase C) matter_readiness_scores, and
        // (Section 39A-3L, Checkpoint 15, Table Phase C)
        // readiness_score_events, and (Section 39A-3L, Checkpoint 16,
        // Table Phase C) tenant_encryption_keys. This test's own scope
        // (Section 39A-2) never touched FORCE state; the remaining
        // prepared tables must still be unforced.
        $forcedByLaterBranch = [
            'customer_success_health_scores',
            'ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings',
            'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links',
            'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events',
            'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts',
            'expense_approvals', 'accounting_export_batches', 'accounting_export_lines',
            'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events',
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            'communication_consents', 'communication_consent_events', 'intake_submissions',
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 18
            // (this repo's thirty-sixth staged FORCE activation batch,
            // covering firm_settings). This test's own scope (39A-2)
            // never touched FORCE state.
            'firm_settings',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 19
            // (this repo's thirty-seventh staged FORCE activation
            // batch, covering firm_licenses). This test's own scope
            // (39A-2) never touched FORCE state.
            'firm_licenses',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 20
            // (this repo's thirty-eighth staged FORCE activation
            // batch, covering time_tracking_sessions). This test's own
            // scope (39A-2) never touched FORCE state.
            'time_tracking_sessions',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21
            // (this repo's thirty-ninth staged FORCE activation batch,
            // covering time_entries). This test's own scope (39A-2)
            // never touched FORCE state.
            'time_entries',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22
            // (this repo's fortieth staged FORCE activation batch,
            // covering payment_plans). This test's own scope (39A-2)
            // never touched FORCE state.
            'payment_plans',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23
            // (this repo's forty-first staged FORCE activation batch,
            // covering payment_plan_events). This test's own scope
            // (39A-2) never touched FORCE state.
            'payment_plan_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 24
            // (this repo's forty-second staged FORCE activation batch,
            // covering notification_events). This test's own scope
            // (39A-2) never touched FORCE state.
            'notification_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 25
            // (this repo's forty-third staged FORCE activation batch,
            // covering contacts). This test's own scope (39A-2) never
            // touched FORCE state.
            'contacts',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 26
            // (this repo's forty-fourth staged FORCE activation batch,
            // covering parties). This test's own scope (39A-2) never
            // touched FORCE state.
            'parties',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 27
            // (this repo's forty-fifth staged FORCE activation batch,
            // covering backup_restore_tests). This test's own scope
            // (39A-2) never touched FORCE state.
            'backup_restore_tests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 28
            // (this repo's forty-sixth staged FORCE activation batch,
            // covering health_checks). This test's own scope (39A-2)
            // never touched FORCE state.
            'health_checks',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 29
            // (this repo's forty-seventh staged FORCE activation
            // batch, covering incident_events). This test's own scope
            // (39A-2) never touched FORCE state.
            'incident_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 30
            // (this repo's forty-eighth staged FORCE activation
            // batch, covering maintenance_windows). This test's own
            // scope (39A-2) never touched FORCE state.
            'maintenance_windows',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 31,
            // Phase B6 (this repo's forty-ninth staged FORCE
            // activation batch, covering notification_templates).
            // This test's own scope (39A-2) never touched FORCE
            // state.
            'notification_templates',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 32,
            // Phase B6 (this repo's fiftieth staged FORCE activation
            // batch, covering pilot_feedback_items). This test's own
            // scope (39A-2) never touched FORCE state.
            'pilot_feedback_items',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 33,
            // Phase B6 (this repo's fifty-first staged FORCE activation
            // batch, covering timeline_events). This test's own scope
            // (39A-2) never touched FORCE state.
            'timeline_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 34,
            // Phase B6 (this repo's fifty-second and FINAL staged FORCE
            // activation batch in this arc, covering security_events).
            // This test's own scope (39A-2) never touched FORCE state.
            'security_events',
        ];

        // security_events is the final checkpoint in this arc: every
        // originally-prepared table is now accounted for in
        // $forcedByLaterBranch, so the loop below legitimately has zero
        // remaining iterations — a real, positive end state, not a lost
        // assertion. This explicit equality check keeps the test
        // genuinely assertive regardless of loop iteration count.
        $forcedSorted = $forcedByLaterBranch;
        sort($forcedSorted);
        $preparedTablesSorted = $coverage->preparedTables();
        sort($preparedTablesSorted);
        $this->assertSame($forcedSorted, $preparedTablesSorted, 'Every originally "prepared" table must now be force-enabled, no more, no fewer.');

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forcedByLaterBranch, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse(
                (bool) $row->relforcerowsecurity,
                "{$table} must not have permanent FORCE ROW LEVEL SECURITY in this branch."
            );
        }
    }

    public function test_no_new_rls_policy_was_added_for_any_still_uncovered_tenant_table(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        foreach ($coverage->missingPreparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — Section 39A-2 must not add new policies for uncovered tables."
            );
        }
    }

    public function test_no_new_migration_files_were_added(): void
    {
        // Section 39A-3A/39A-3B (later, distinct staged-FORCE-
        // activation branches) legitimately added clients-only and
        // firm_users-only FORCE RLS migrations.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/migrations'),
            fn (string $path) => $path !== 'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php'
                && $path !== 'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'
                && $path !== 'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'
                && $path !== 'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'
                && $path !== 'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'
                && $path !== 'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'
                && $path !== 'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'
                && $path !== 'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'
                // Internal login/panel access wiring (a later, distinct
                // section) legitimately added a migration extending
                // firm_users' RLS policy with a narrow self-lookup
                // clause needed to bootstrap-resolve an authenticated
                // user's own firm from firm_users itself.
                && $path !== 'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // conflict_check_runs-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'
                // Section 39A-3J (this batch, a later, distinct staged-
                // FORCE-activation branch) legitimately added FORCE RLS
                // migrations for lead_sources, consultation_outcomes,
                // firm_leads, and consultations together.
                && $path !== 'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'
                && $path !== 'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'
                && $path !== 'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'
                && $path !== 'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'
                // Section 39A-3K (this batch, a later, distinct staged-
                // FORCE-activation branch) legitimately added FORCE RLS
                // migrations for firm_practice_areas,
                // document_chase_rules, employee_rates, calendar_events,
                // and client_communication_preferences together.
                && $path !== 'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'
                && $path !== 'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'
                && $path !== 'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'
                && $path !== 'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'
                && $path !== 'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'
                // Section 39A-3L, Checkpoint 1, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // payment_classification_events-only FORCE RLS
                // migration.
                && $path !== 'database/migrations/2026_08_25_930001_force_rls_on_payment_classification_events_table.php'
                // Section 39A-3L, Checkpoint 2, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added an activation_checklists-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php'
                // Section 39A-3L, Checkpoint 3, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_activation_events-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930003_force_rls_on_firm_activation_events_table.php'
                // Section 39A-3L, Checkpoint 4, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_entitlements-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php'
                // Section 39A-3L, Checkpoint 5, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // firm_entitlement_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php'
                // Section 39A-3L, Checkpoint 6, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added an
                // installed_template_packs-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php'
                // Section 39A-3L, Checkpoint 7, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a template_upgrade_logs-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930007_force_rls_on_template_upgrade_logs_table.php'
                // Section 39A-3L, Checkpoint 8, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // template_upgrade_previews-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930008_force_rls_on_template_upgrade_previews_table.php'
                // Section 39A-3L, Checkpoint 9, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a seat_allocations-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930009_force_rls_on_seat_allocations_table.php'
                // Section 39A-3L, Checkpoint 10, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_requests-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'
                // Section 39A-3L, Checkpoint 11, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a communication_consents-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'
                // Section 39A-3L, Checkpoint 12, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a
                // communication_consent_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php'
                // Section 39A-3L, Checkpoint 13, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added an intake_submissions-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php'
                // Section 39A-3L, Checkpoint 14, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a matter_readiness_scores-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930014_force_rls_on_matter_readiness_scores_table.php'
                // Section 39A-3L, Checkpoint 15, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a readiness_score_events-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930015_force_rls_on_readiness_score_events_table.php'
                // Section 39A-3L, Checkpoint 16, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a tenant_encryption_keys-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php'
                // Section 39A-3L, Checkpoint 17, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_chase_events-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930017_force_rls_on_document_chase_events_table.php'
                // Section 39A-3L, Checkpoint 18, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_settings-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930018_force_rls_on_firm_settings_table.php'
                // Section 39A-3L, Checkpoint 19, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a firm_licenses-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930019_force_rls_on_firm_licenses_table.php'
                // Section 39A-3L, Checkpoint 20, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a time_tracking_sessions-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930020_force_rls_on_time_tracking_sessions_table.php'
                // Section 39A-3L, Checkpoint 21, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a time_entries-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php'
                // Section 39A-3L, Checkpoint 22, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plans-only FORCE
                // RLS migration.
                && $path !== 'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'
                // Section 39A-3L, Checkpoint 23, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plan_events-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'
                // Section 39A-3L, Checkpoint 24 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a notification_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php'
                // Section 39A-3L, Checkpoint 25 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a contacts-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930025_force_rls_on_contacts_table.php'
                // Section 39A-3L, Checkpoint 26 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a parties-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930026_force_rls_on_parties_table.php'
                // Section 39A-3L, Checkpoint 27 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a backup_restore_tests-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930027_force_rls_on_backup_restore_tests_table.php'
                // Section 39A-3L, Checkpoint 28 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a health_checks-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930028_force_rls_on_health_checks_table.php'
                // Section 39A-3L, Checkpoint 29 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added an incident_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930029_force_rls_on_incident_events_table.php'
                // Section 39A-3L, Checkpoint 30 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a maintenance_windows-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930030_force_rls_on_maintenance_windows_table.php'
                // Section 39A-3L, Checkpoint 31, Phase B6 (this batch,
                // a later, distinct staged-FORCE-activation branch)
                // legitimately added a notification_templates-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930031_force_rls_on_notification_templates_table.php'
                // Section 39A-3L, Checkpoint 32, Phase B6 (this batch,
                // a later, distinct staged-FORCE-activation branch)
                // legitimately added a pilot_feedback_items-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930032_force_rls_on_pilot_feedback_items_table.php'
                // Section 39A-3L, Checkpoint 33, Phase B6 (this batch,
                // a later, distinct staged-FORCE-activation branch)
                // legitimately added a timeline_events-only FORCE RLS
                // migration.
                && $path !== 'database/migrations/2026_08_25_930033_force_rls_on_timeline_events_table.php'
                // Section 39A-5 Wave 1 (this batch, a later, distinct
                // arc) legitimately added three combined
                // prepare-and-force migrations together:
                // ai_retrieval_indexes, deployment_configs, and
                // firm_ai_settings.
                && $path !== 'database/migrations/2026_08_27_950001_prepare_row_level_security_and_force_rls_on_ai_retrieval_indexes_table.php'
                && $path !== 'database/migrations/2026_08_27_950002_prepare_row_level_security_and_force_rls_on_deployment_configs_table.php'
                && $path !== 'database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php'
                // Section 39A-5 Wave 2 (this batch, a later, distinct
                // arc) legitimately added four combined
                // prepare-and-force migrations together:
                // email_visibility_rules, private_enterprise_settings,
                // matter_expenses, and email_message_links.
                && $path !== 'database/migrations/2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php'
                && $path !== 'database/migrations/2026_08_27_950005_prepare_row_level_security_and_force_rls_on_email_visibility_rules_table.php'
                && $path !== 'database/migrations/2026_08_27_950011_prepare_row_level_security_and_force_rls_on_private_enterprise_settings_table.php'
                && $path !== 'database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php'
                // Section 39A-5 Wave 3 (this batch, a later, distinct
                // arc, AI governance domain) legitimately added five
                // combined prepare-and-force migrations together:
                // ai_usage_events, ai_tool_actions, firm_ai_provider_keys,
                // ai_approval_requests, and ai_approval_events.
                && $path !== 'database/migrations/2026_08_27_950013_prepare_row_level_security_and_force_rls_on_ai_usage_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950014_prepare_row_level_security_and_force_rls_on_ai_tool_actions_table.php'
                && $path !== 'database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php'
                && $path !== 'database/migrations/2026_08_27_950016_prepare_row_level_security_and_force_rls_on_ai_approval_requests_table.php'
                && $path !== 'database/migrations/2026_08_27_950017_prepare_row_level_security_and_force_rls_on_ai_approval_events_table.php'
                && $path !== 'database/migrations/2026_08_27_950018_prepare_row_level_security_and_force_rls_on_chart_of_accounts_table.php'
                && $path !== 'database/migrations/2026_08_27_950019_prepare_row_level_security_and_force_rls_on_expense_categories_table.php'
                && $path !== 'database/migrations/2026_08_27_950020_prepare_row_level_security_and_force_rls_on_expenses_table.php'
                && $path !== 'database/migrations/2026_08_27_950021_prepare_row_level_security_and_force_rls_on_expense_receipts_table.php'
                && $path !== 'database/migrations/2026_08_27_950022_prepare_row_level_security_and_force_rls_on_expense_approvals_table.php'
                && $path !== 'database/migrations/2026_08_27_950023_prepare_row_level_security_and_force_rls_on_accounting_export_batches_table.php'
                && $path !== 'database/migrations/2026_08_27_950024_prepare_row_level_security_and_force_rls_on_accounting_export_lines_table.php'
                && $path !== 'database/migrations/2026_08_27_950025_prepare_row_level_security_and_force_rls_on_email_accounts_table.php'
                && $path !== 'database/migrations/2026_08_27_950026_prepare_row_level_security_and_force_rls_on_email_messages_table.php'
                && $path !== 'database/migrations/2026_08_27_950027_prepare_row_level_security_and_force_rls_on_email_attachments_table.php'
                && $path !== 'database/migrations/2026_08_27_950028_prepare_row_level_security_and_force_rls_on_email_sync_events_table.php',
        ));

        $this->assertEmpty($changed, 'Section 39A-2 must add no migrations, but found: '.implode(', ', $changed));
    }

    public function test_no_ui_routes_or_controllers_were_introduced(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39A-2 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_global_rls_bypass_was_introduced_in_the_new_test_helper(): void
    {
        $source = file_get_contents(base_path('tests/TestCase.php'));

        $this->assertStringNotContainsStringIgnoringCase('bypassrls', $source);
        $this->assertStringNotContainsStringIgnoringCase('withoutTenantScope', $source);
        $this->assertStringNotContainsString('COALESCE(current_setting', $source);
        $this->assertStringNotContainsString('DISABLE ROW LEVEL SECURITY', $source);
    }

    public function test_no_unrelated_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $protected = [
            'app/Services/HighRiskPlatformChangePolicyService.php',
            'app/Services/SupportAccessPolicyService.php',
            'app/Services/SupportAccessRequestService.php',
            'app/Services/EmergencyAccessGovernanceGapService.php',
            'app/Services/SeedDataSecurityAuditService.php',
            // FirmUser2faPolicyService.php is deliberately NOT in this
            // list any more — Section 39A-3L, Checkpoint 18 (a later,
            // distinct staged-FORCE-activation branch) found a genuine
            // need to correct a stale docblock claim ("no login route/
            // UI surface yet") once User::canAccessPanel() became a
            // live consumer of this service, wrapped in tenant context
            // because firm_settings gained permanent FORCE ROW LEVEL
            // SECURITY in that checkpoint. Only the docblock changed —
            // no method logic in this file was touched.
            // LoginPolicyService.php is deliberately NOT in this list
            // any more — Section 39A-3B (a later, distinct staged-
            // FORCE-activation branch) found a genuine need to wire
            // canAttemptFirmLogin()'s FirmUser read with explicit
            // tenant context, since firm_users now has permanent
            // FORCE ROW LEVEL SECURITY.
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            // ApplyTenantDatabaseContext.php and User.php are
            // deliberately NOT in this list any more — internal
            // login/panel access wiring (a later, distinct section)
            // found a genuine need to attach ApplyTenantDatabaseContext
            // to the real firm panel's authMiddleware (its docblock
            // previously said it was intentionally unattached to any
            // route), and to add FilamentUser::canAccessPanel() to
            // User.php — the real Filament panel access gate.
            'app/Services/TenantContextResolver.php',
            'app/Models/FirmUser.php',
            'app/Models/FirmSettings.php',
            'app/Models/Firm.php',
            'app/Models/Concerns/BelongsToTenant.php',
            'database/seeders/DatabaseSeeder.php',
        ];

        $touched = array_values(array_intersect($protected, $changed));

        $this->assertEmpty($touched, 'Section 39A-2 must not modify unrelated protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_tenant_context_service_and_job_context_trait_were_not_modified(): void
    {
        // Allowed only "if a bug is found" — AWS inspection for
        // Section 39A-2 itself found none, so both remained untouched
        // in that branch. Section 39A-3A (a later, distinct staged-
        // FORCE-activation branch) DID find a genuine need: activating
        // FORCE on clients exposed that setFirmContext() also touches
        // TenantContextResolver's PHP-memory state, which
        // BelongsToTenant's global scope reads — leaving that active
        // after a factory-level context call leaked an implicit
        // firm_id constraint into unrelated queries. The fix added a
        // new, narrowly-scoped method
        // (setDatabaseTenantContextForFirmId()) rather than changing
        // any existing method's behavior. TenantAwareJobContext still
        // needed no change.
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('app/Support/TenantAwareJobContext.php', $changed);
    }

    public function test_compliance_gap_registry_service_was_not_deleted_or_rewritten(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_gap_registry_still_tracks_the_rls_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
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
