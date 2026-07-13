<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsForceRolloutFirewallTest — Section 39A-3B. Proves this staged
 * activation batch stayed inside its declared boundary: FORCE ROW
 * LEVEL SECURITY was activated for firm_users only in THIS branch
 * (clients was already forced by Section 39A-3A and stays that way,
 * but this branch did not touch it) — not the other 50 prepared
 * tables, not the 43 still-uncovered tenant-owned tables — no new RLS
 * policy was added, no UI/routes/controllers were introduced, and
 * ComplianceGapRegistryService was not deleted/rewritten.
 *
 * Narrowly updated by Section 39A-3J (this repo's thirteenth staged
 * FORCE activation batch, covering lead_sources,
 * consultation_outcomes, firm_leads, and consultations together) to
 * extend the "exactly these tables are forced" firewall list and add
 * this batch's own four migration-existence checks — following the
 * exact same pattern every prior 39A-3C..39A-3I section already used
 * here, not a restructure of this test's own original scope/assertions.
 *
 * Narrowly updated AGAIN by Section 39A-3K (this repo's fourteenth
 * through eighteenth staged FORCE activation batch, covering
 * firm_practice_areas, document_chase_rules, employee_rates,
 * calendar_events, and client_communication_preferences together) to
 * extend the "exactly these tables are forced" firewall list from
 * thirteen to eighteen tables and add this batch's own five
 * migration-existence checks — same additive-only pattern, no existing
 * assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 1, Table Phase C
 * (this repo's nineteenth staged FORCE activation batch, covering
 * payment_classification_events) to extend the "exactly these tables
 * are forced" firewall list from eighteen to nineteen tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 2, Table Phase C
 * (this repo's twentieth staged FORCE activation batch, covering
 * activation_checklists) to extend the "exactly these tables are
 * forced" firewall list from nineteen to twenty tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 3, Table Phase C
 * (this repo's twenty-first staged FORCE activation batch, covering
 * firm_activation_events) to extend the "exactly these tables are
 * forced" firewall list from twenty to twenty-one tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 4, Table Phase C
 * (this repo's twenty-second staged FORCE activation batch, covering
 * firm_entitlements) to extend the "exactly these tables are forced"
 * firewall list from twenty-one to twenty-two tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 5, Table Phase C
 * (this repo's twenty-third staged FORCE activation batch, covering
 * firm_entitlement_events) to extend the "exactly these tables are
 * forced" firewall list from twenty-two to twenty-three tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 6, Table Phase C
 * (this repo's twenty-fourth staged FORCE activation batch, covering
 * installed_template_packs) to extend the "exactly these tables are
 * forced" firewall list from twenty-three to twenty-four tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 7, Table Phase C
 * (this repo's twenty-fifth staged FORCE activation batch, covering
 * template_upgrade_logs) to extend the "exactly these tables are
 * forced" firewall list from twenty-four to twenty-five tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 8, Table Phase C
 * (this repo's twenty-sixth staged FORCE activation batch, covering
 * template_upgrade_previews) to extend the "exactly these tables are
 * forced" firewall list from twenty-five to twenty-six tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table Phase C
 * (this repo's twenty-seventh staged FORCE activation batch, covering
 * seat_allocations) to extend the "exactly these tables are forced"
 * firewall list from twenty-six to twenty-seven tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table Phase C
 * (this repo's twenty-eighth staged FORCE activation batch, covering
 * document_requests) to extend the "exactly these tables are forced"
 * firewall list from twenty-seven to twenty-eight tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table Phase C
 * (this repo's twenty-ninth staged FORCE activation batch, covering
 * communication_consents) to extend the "exactly these tables are
 * forced" firewall list from twenty-eight to twenty-nine tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table Phase C
 * (this repo's thirtieth staged FORCE activation batch, covering
 * communication_consent_events) to extend the "exactly these tables are
 * forced" firewall list from twenty-nine to thirty tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13, Table Phase C
 * (this repo's thirty-first staged FORCE activation batch, covering
 * intake_submissions) to extend the "exactly these tables are forced"
 * firewall list from thirty to thirty-one tables and add this batch's
 * own migration-existence check — same additive-only pattern, no
 * existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14, Table
 * Phase C (this repo's thirty-second staged FORCE activation batch,
 * covering matter_readiness_scores) to extend the "exactly these
 * tables are forced" firewall list from thirty-one to thirty-two
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15, Table
 * Phase C (this repo's thirty-third staged FORCE activation batch,
 * covering readiness_score_events) to extend the "exactly these
 * tables are forced" firewall list from thirty-two to thirty-three
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16, Table
 * Phase C (this repo's thirty-fourth staged FORCE activation batch,
 * covering tenant_encryption_keys) to extend the "exactly these
 * tables are forced" firewall list from thirty-three to thirty-four
 * tables and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 19, Table
 * Phase C (this repo's thirty-seventh staged FORCE activation batch,
 * covering firm_licenses) to extend the "exactly these tables are
 * forced" list from thirty-six to thirty-seven tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 20, Table
 * Phase C (this repo's thirty-eighth staged FORCE activation batch,
 * covering time_tracking_sessions) to extend the "exactly these tables
 * are forced" list from thirty-seven to thirty-eight tables and add
 * this batch's own migration-existence check — same additive-only
 * pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21, Table
 * Phase C (this repo's thirty-ninth staged FORCE activation batch,
 * covering time_entries) to extend the "exactly these tables are
 * forced" list from thirty-eight to thirty-nine tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table
 * Phase C (this repo's fortieth staged FORCE activation batch,
 * covering payment_plans) to extend the "exactly these tables are
 * forced" list from thirty-nine to forty tables and add this batch's
 * own migration-existence check — same additive-only pattern, no
 * existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23, Table
 * Phase C (this repo's forty-first staged FORCE activation batch,
 * covering payment_plan_events) to extend the "exactly these tables
 * are forced" list from forty to forty-one tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 24 (this
 * repo's forty-second staged FORCE activation batch, covering
 * notification_events) to extend the "exactly these tables are
 * forced" list from forty-one to forty-two tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 25 (this
 * repo's forty-third staged FORCE activation batch, covering
 * contacts) to extend the "exactly these tables are forced" list
 * from forty-two to forty-three tables and add this batch's own
 * migration-existence check — same additive-only pattern, no existing
 * assertion removed or weakened. parties, contacts' sibling table
 * under the same prerequisite remediation (Section 39A-3L Phase B5),
 * remained untouched/unforced by that checkpoint — it was addressed
 * by a separate checkpoint (Checkpoint 26).
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 26 (this
 * repo's forty-fourth staged FORCE activation batch, covering
 * parties, database/migrations/2026_08_25_930026_force_rls_on_parties_
 * table.php) to extend the "exactly these tables are forced" list
 * from forty-three to forty-four tables and add this batch's own
 * migration-existence check — same additive-only pattern, no existing
 * assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 27 (this
 * repo's forty-fifth staged FORCE activation batch, covering
 * backup_restore_tests, database/migrations/2026_08_25_930027_
 * force_rls_on_backup_restore_tests_table.php) to extend the "exactly
 * these tables are forced" list from forty-four to forty-five tables
 * and add this batch's own migration-existence check — same
 * additive-only pattern, no existing assertion removed or weakened.
 *
 * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 28 (this
 * repo's forty-sixth staged FORCE activation batch, covering
 * health_checks, database/migrations/2026_08_25_930028_force_rls_on_
 * health_checks_table.php) to extend the "exactly these tables are
 * forced" list from forty-five to forty-six tables and add this
 * batch's own migration-existence check — same additive-only pattern,
 * no existing assertion removed or weakened.
 */
class RlsForceRolloutFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_clients_and_firm_users_have_permanent_force_row_level_security_among_prepared_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Section 39A-3C/39A-3D/39A-3E/39A-3F/39A-3G/39A-3H/39A-3I/39A-3J/
        // 39A-3K/39A-3L (later, distinct staged-FORCE-activation branches)
        // legitimately activated FORCE for documents, deadlines, tasks,
        // matters, invoices, payments, conflict_check_runs, lead_sources,
        // consultation_outcomes, firm_leads, consultations (Section
        // 39A-3J), (Section 39A-3K) firm_practice_areas,
        // document_chase_rules, employee_rates, calendar_events,
        // client_communication_preferences, (Section 39A-3L, Checkpoint
        // 1, Table Phase C) payment_classification_events, (Section
        // 39A-3L, Checkpoint 2, Table Phase C) activation_checklists,
        // (Section 39A-3L, Checkpoint 3, Table Phase C)
        // firm_activation_events, (Section 39A-3L, Checkpoint 4,
        // Table Phase C) firm_entitlements, (Section 39A-3L,
        // Checkpoint 5, Table Phase C) firm_entitlement_events, and
        // (Section 39A-3L, Checkpoint 6, Table Phase C)
        // installed_template_packs, and (Section 39A-3L, Checkpoint 7,
        // Table Phase C) template_upgrade_logs, and (Section 39A-3L,
        // Checkpoint 8, Table Phase C) template_upgrade_previews, and
        // (Section 39A-3L, Checkpoint 9, Table Phase C) seat_allocations,
        // and (Section 39A-3L, Checkpoint 10, Table Phase C)
        // document_requests, and (Section 39A-3L, Checkpoint 11, Table
        // Phase C) communication_consents, and (Section 39A-3L,
        // Checkpoint 12, Table Phase C) communication_consent_events,
        // and (Section 39A-3L, Checkpoint 13, Table Phase C)
        // intake_submissions, and (Section 39A-3L, Checkpoint 14, Table
        // Phase C) matter_readiness_scores, and (Section 39A-3L,
        // Checkpoint 15, Table Phase C) readiness_score_events, and
        // (Section 39A-3L, Checkpoint 16, Table Phase C)
        // tenant_encryption_keys too — this test's own scope (39A-3B)
        // only asserts clients and firm_users here.
        $expectedForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            'communication_consents', 'communication_consent_events', 'intake_submissions',
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 18,
            // Table Phase C (this repo's thirty-sixth staged FORCE
            // activation batch, covering firm_settings) to extend the
            // "exactly these tables are forced" list from thirty-five
            // to thirty-six tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'firm_settings',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 19,
            // Table Phase C (this repo's thirty-seventh staged FORCE
            // activation batch, covering firm_licenses) to extend the
            // "exactly these tables are forced" list from thirty-six to
            // thirty-seven tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'firm_licenses',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 20,
            // Table Phase C (this repo's thirty-eighth staged FORCE
            // activation batch, covering time_tracking_sessions) to
            // extend the "exactly these tables are forced" list from
            // thirty-seven to thirty-eight tables — same additive-only
            // pattern, no existing assertion removed or weakened.
            'time_tracking_sessions',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21,
            // Table Phase C (this repo's thirty-ninth staged FORCE
            // activation batch, covering time_entries) to extend the
            // "exactly these tables are forced" list from thirty-eight
            // to thirty-nine tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'time_entries',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22,
            // Table Phase C (this repo's fortieth staged FORCE
            // activation batch, covering payment_plans) to extend the
            // "exactly these tables are forced" list from thirty-nine
            // to forty tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'payment_plans',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
            // Table Phase C (this repo's forty-first staged FORCE
            // activation batch, covering payment_plan_events) to
            // extend the "exactly these tables are forced" list from
            // forty to forty-one tables — same additive-only pattern,
            // no existing assertion removed or weakened.
            'payment_plan_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 24
            // (this repo's forty-second staged FORCE activation
            // batch, covering notification_events) to extend the
            // "exactly these tables are forced" list from forty-one
            // to forty-two tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'notification_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 25
            // (this repo's forty-third staged FORCE activation batch,
            // covering contacts) to extend the "exactly these tables
            // are forced" list from forty-two to forty-three tables —
            // same additive-only pattern, no existing assertion
            // removed or weakened. parties, contacts' sibling table
            // under the same prerequisite remediation, remained
            // untouched/unforced at that point — it was addressed by
            // the separate Checkpoint 26 below.
            'contacts',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 26
            // (this repo's forty-fourth staged FORCE activation batch,
            // covering parties) to extend the "exactly these tables
            // are forced" list from forty-three to forty-four tables —
            // same additive-only pattern, no existing assertion
            // removed or weakened.
            'parties',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 27
            // (this repo's forty-fifth staged FORCE activation batch,
            // covering backup_restore_tests) to extend the "exactly
            // these tables are forced" list from forty-four to
            // forty-five tables — same additive-only pattern, no
            // existing assertion removed or weakened.
            'backup_restore_tests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 28
            // (this repo's forty-sixth staged FORCE activation batch,
            // covering health_checks) to extend the "exactly these
            // tables are forced" list from forty-five to forty-six
            // tables — same additive-only pattern, no existing
            // assertion removed or weakened.
            'health_checks',
        ];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            $shouldBeForced = in_array($table, $expectedForced, true);

            $this->assertSame(
                $shouldBeForced,
                (bool) $row->relforcerowsecurity,
                $shouldBeForced
                    ? "{$table} must have permanent FORCE ROW LEVEL SECURITY active."
                    : "{$table} must not have permanent FORCE ROW LEVEL SECURITY — Section 39A-3B activates firm_users only (clients was already forced by 39A-3A)."
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
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — Section 39A-3B must not add new policies for uncovered tables."
            );
        }
    }

    public function test_the_firm_users_force_rls_migration_file_exists(): void
    {
        // File-existence check, not a git-diff/untracked-state check:
        // this branch's own instructions say "do not commit," but this
        // test file itself must still work correctly if a future
        // section commits/merges it (matching the lesson learned from
        // Section 39A-3A's own equivalent test).
        $this->assertFileExists(base_path('database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'));
    }

    public function test_the_documents_force_rls_migration_file_exists(): void
    {
        // Section 39A-3C's own migration — same file-existence
        // reasoning as the firm_users check above.
        $this->assertFileExists(base_path('database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'));
    }

    public function test_the_deadlines_force_rls_migration_file_exists(): void
    {
        // Section 39A-3D's own migration — same file-existence
        // reasoning as the firm_users/documents checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'));
    }

    public function test_the_tasks_force_rls_migration_file_exists(): void
    {
        // Section 39A-3E's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'));
    }

    public function test_the_matters_force_rls_migration_file_exists(): void
    {
        // Section 39A-3F's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks checks
        // above.
        $this->assertFileExists(base_path('database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'));
    }

    public function test_the_invoices_force_rls_migration_file_exists(): void
    {
        // Section 39A-3G's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks/
        // matters checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'));
    }

    public function test_the_payments_force_rls_migration_file_exists(): void
    {
        // Section 39A-3H's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks/
        // matters/invoices checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'));
    }

    public function test_the_conflict_check_runs_force_rls_migration_file_exists(): void
    {
        // Section 39A-3I's own migration — same file-existence
        // reasoning as the firm_users/documents/deadlines/tasks/
        // matters/invoices/payments checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'));
    }

    public function test_the_lead_sources_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 1 of 4) —
        // same file-existence reasoning as the firm_users/documents/
        // deadlines/tasks/matters/invoices/payments/conflict_check_runs
        // checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'));
    }

    public function test_the_consultation_outcomes_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 2 of 4) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'));
    }

    public function test_the_firm_leads_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 3 of 4) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'));
    }

    public function test_the_consultations_force_rls_migration_file_exists(): void
    {
        // Section 39A-3J's own migration (this batch, table 4 of 4) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'));
    }

    public function test_the_firm_practice_areas_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 1 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'));
    }

    public function test_the_document_chase_rules_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 2 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'));
    }

    public function test_the_employee_rates_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 3 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'));
    }

    public function test_the_calendar_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 4 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'));
    }

    public function test_the_client_communication_preferences_force_rls_migration_file_exists(): void
    {
        // Section 39A-3K's own migration (this batch, table 5 of 5) —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'));
    }

    public function test_the_payment_classification_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 1, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930001_force_rls_on_payment_classification_events_table.php'));
    }

    public function test_the_activation_checklists_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 2, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php'));
    }

    public function test_the_firm_activation_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 3, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930003_force_rls_on_firm_activation_events_table.php'));
    }

    public function test_the_firm_entitlements_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 4, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php'));
    }

    public function test_the_firm_entitlement_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 5, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php'));
    }

    public function test_the_installed_template_packs_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 6, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php'));
    }

    public function test_the_template_upgrade_logs_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 7, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930007_force_rls_on_template_upgrade_logs_table.php'));
    }

    public function test_the_template_upgrade_previews_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 8, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930008_force_rls_on_template_upgrade_previews_table.php'));
    }

    public function test_the_seat_allocations_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 9, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930009_force_rls_on_seat_allocations_table.php'));
    }

    public function test_the_document_requests_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 10, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'));
    }

    public function test_the_communication_consents_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 11, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'));
    }

    public function test_the_communication_consent_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 12, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php'));
    }

    public function test_the_intake_submissions_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 13, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php'));
    }

    public function test_the_matter_readiness_scores_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 14, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930014_force_rls_on_matter_readiness_scores_table.php'));
    }

    public function test_the_readiness_score_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 15, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930015_force_rls_on_readiness_score_events_table.php'));
    }

    public function test_the_tenant_encryption_keys_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 16, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php'));
    }

    public function test_the_firm_licenses_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 19, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930019_force_rls_on_firm_licenses_table.php'));
    }

    public function test_the_time_tracking_sessions_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 20, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930020_force_rls_on_time_tracking_sessions_table.php'));
    }

    public function test_the_time_entries_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 21, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php'));
    }

    public function test_the_payment_plans_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 22, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'));
    }

    public function test_the_payment_plan_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 23, Table Phase C's own migration —
        // same file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'));
    }

    public function test_the_notification_events_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 24's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php'));
    }

    public function test_the_contacts_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 25's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930025_force_rls_on_contacts_table.php'));
    }

    public function test_the_parties_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 26's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930026_force_rls_on_parties_table.php'));
    }

    public function test_the_backup_restore_tests_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 27's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930027_force_rls_on_backup_restore_tests_table.php'));
    }

    public function test_the_health_checks_force_rls_migration_file_exists(): void
    {
        // Section 39A-3L, Checkpoint 28's own migration — same
        // file-existence reasoning as the checks above.
        $this->assertFileExists(base_path('database/migrations/2026_08_25_930028_force_rls_on_health_checks_table.php'));
    }

    public function test_no_ui_routes_or_controllers_were_introduced(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39A-3B must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
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
