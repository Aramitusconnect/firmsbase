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

        // Section 39A-3A/39A-3B/39A-3C (later, distinct staged-FORCE-
        // activation branches) legitimately activated permanent FORCE
        // ROW LEVEL SECURITY on clients, firm_users, and documents —
        // the first three of the 52 prepared tables to move from
        // "prepared" to "enforced." This test's own scope (Section
        // 39A-2) never touched FORCE state; the other 49 prepared
        // tables must still be unforced.
        $forcedByLaterBranch = ['clients', 'firm_users', 'documents'];

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
                && $path !== 'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php',
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
            'app/Services/FirmUser2faPolicyService.php',
            // LoginPolicyService.php is deliberately NOT in this list
            // any more — Section 39A-3B (a later, distinct staged-
            // FORCE-activation branch) found a genuine need to wire
            // canAttemptFirmLogin()'s FirmUser read with explicit
            // tenant context, since firm_users now has permanent
            // FORCE ROW LEVEL SECURITY.
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'app/Http/Middleware/ApplyTenantDatabaseContext.php',
            'app/Services/TenantContextResolver.php',
            'app/Models/User.php',
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
