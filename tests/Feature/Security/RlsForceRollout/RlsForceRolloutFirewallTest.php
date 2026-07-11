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
        // firm_activation_events, and (Section 39A-3L, Checkpoint 4,
        // Table Phase C) firm_entitlements too — this test's own scope
        // (39A-3B) only asserts clients and firm_users here.
        $expectedForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements',
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
