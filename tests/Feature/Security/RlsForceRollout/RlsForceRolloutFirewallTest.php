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
 */
class RlsForceRolloutFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_clients_and_firm_users_have_permanent_force_row_level_security_among_prepared_tables(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Section 39A-3C/39A-3D/39A-3E (later, distinct staged-FORCE-
        // activation branches) legitimately activated FORCE for
        // documents, deadlines, and tasks too — this test's own scope
        // (39A-3B) only asserts clients and firm_users here.
        $expectedForced = ['clients', 'firm_users', 'documents', 'deadlines', 'tasks'];

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
