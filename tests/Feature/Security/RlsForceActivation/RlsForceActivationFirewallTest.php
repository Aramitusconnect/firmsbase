<?php

namespace Tests\Feature\Security\RlsForceActivation;

use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsForceActivationFirewallTest — Section 39A-3A. Proves this staged
 * activation batch stayed inside its declared boundary: FORCE ROW
 * LEVEL SECURITY was activated for clients only (not the other 51
 * prepared tables, not the 43 still-uncovered tenant-owned tables), no
 * new RLS policy was added, no UI/routes/controllers were introduced,
 * and ComplianceGapRegistryService was not deleted/rewritten.
 */
class RlsForceActivationFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_clients_has_permanent_force_row_level_security_among_prepared_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Section 39A-3B/39A-3C/39A-3D/39A-3E/39A-3F/39A-3G/39A-3H/39A-3I/
        // 39A-3J/39A-3K/39A-3L (later, distinct staged-FORCE-activation
        // branches) legitimately activated FORCE for firm_users,
        // documents, deadlines, tasks, matters, invoices, payments,
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
        // Table Phase C) tenant_encryption_keys too — this test's own
        // scope (39A-3A) only asserts clients here.
        $forcedByLaterBranch = [
            'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            'communication_consents', 'communication_consent_events', 'intake_submissions',
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 18
            // (this repo's thirty-sixth staged FORCE activation batch,
            // covering firm_settings) — this test's own scope (39A-3A)
            // only asserts clients here.
            'firm_settings',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 19
            // (this repo's thirty-seventh staged FORCE activation
            // batch, covering firm_licenses) — this test's own scope
            // (39A-3A) only asserts clients here.
            'firm_licenses',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 20
            // (this repo's thirty-eighth staged FORCE activation
            // batch, covering time_tracking_sessions) — this test's own
            // scope (39A-3A) only asserts clients here.
            'time_tracking_sessions',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21
            // (this repo's thirty-ninth staged FORCE activation batch,
            // covering time_entries) — this test's own scope (39A-3A)
            // only asserts clients here.
            'time_entries',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22
            // (this repo's fortieth staged FORCE activation batch,
            // covering payment_plans) — this test's own scope (39A-3A)
            // only asserts clients here.
            'payment_plans',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23
            // (this repo's forty-first staged FORCE activation batch,
            // covering payment_plan_events) — this test's own scope
            // (39A-3A) only asserts clients here.
            'payment_plan_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 24
            // (this repo's forty-second staged FORCE activation batch,
            // covering notification_events) — this test's own scope
            // (39A-3A) only asserts clients here.
            'notification_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 25
            // (this repo's forty-third staged FORCE activation batch,
            // covering contacts) — this test's own scope (39A-3A) only
            // asserts clients here.
            'contacts',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 26
            // (this repo's forty-fourth staged FORCE activation batch,
            // covering parties) — this test's own scope (39A-3A) only
            // asserts clients here.
            'parties',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 27
            // (this repo's forty-fifth staged FORCE activation batch,
            // covering backup_restore_tests) — this test's own scope
            // (39A-3A) only asserts clients here.
            'backup_restore_tests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 28
            // (this repo's forty-sixth staged FORCE activation batch,
            // covering health_checks) — this test's own scope (39A-3A)
            // only asserts clients here.
            'health_checks',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 29
            // (this repo's forty-seventh staged FORCE activation
            // batch, covering incident_events) — this test's own
            // scope (39A-3A) only asserts clients here.
            'incident_events',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 30
            // (this repo's forty-eighth staged FORCE activation
            // batch, covering maintenance_windows) — this test's own
            // scope (39A-3A) only asserts clients here.
            'maintenance_windows',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 31,
            // Phase B6 (this repo's forty-ninth staged FORCE
            // activation batch, covering notification_templates) —
            // this test's own scope (39A-3A) only asserts clients
            // here.
            'notification_templates',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 32,
            // Phase B6 (this repo's fiftieth staged FORCE activation
            // batch, covering pilot_feedback_items) — this test's own
            // scope (39A-3A) only asserts clients here.
            'pilot_feedback_items',
        ];

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forcedByLaterBranch, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ($table === 'clients') {
                $this->assertTrue((bool) $row->relforcerowsecurity, 'clients must have permanent FORCE ROW LEVEL SECURITY active.');

                continue;
            }

            $this->assertFalse(
                (bool) $row->relforcerowsecurity,
                "{$table} must not have permanent FORCE ROW LEVEL SECURITY — Section 39A-3A activates clients only."
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
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — Section 39A-3A must not add new policies for uncovered tables."
            );
        }
    }

    public function test_the_clients_force_rls_migration_file_exists(): void
    {
        // Deliberately a file-existence check, not a git-diff/
        // untracked-state check like this project's other firewall
        // tests: this section's migration is expected to be committed
        // and merged (unlike every prior "do not commit" section), so
        // checking uncommitted/untracked state here would report
        // "missing" forever once merged.
        $this->assertFileExists(base_path('database/migrations/2026_07_30_900001_force_rls_on_clients_table.php'));
    }

    public function test_no_ui_routes_or_controllers_were_introduced(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39A-3A must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
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
