<?php

namespace Tests\Feature\Governance;

use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SchemaTenantFirewallTest — Section 39A-4B (Agent 1B).
 *
 * Exercises `security:schema-firewall`, the first automated CI gate
 * this repository has ever had against a new database table landing
 * with zero tenant classification or RLS policy coverage. Every prior
 * safety net here (RowLevelSecurityCoverageMappingServiceTest's one
 * information_schema diff, the per-section "Firewall" allowlist
 * tests) only ever ran when a human/agent manually invoked the test
 * suite — this command is designed to be wired into
 * .github/workflows/schema-tenant-firewall.yml and run on every PR.
 *
 * These tests exercise the command's behavior against THIS repository's
 * current, real state (real migrations, real registry, real test
 * files) rather than against synthetic fixtures — consistent with how
 * RowLevelSecurityCoverageMappingServiceTest itself already diffs
 * against the live schema. They do not mutate any migration, registry,
 * or test file to simulate a failure; the command's failure branches
 * are covered by static code inspection (see
 * app/Console/Commands/SchemaTenantFirewallCommand.php) rather than by
 * a red/green fixture per check.
 */
class SchemaTenantFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'security:schema-firewall',
            Artisan::all(),
            'security:schema-firewall must be registered as an Artisan command.'
        );
    }

    public function test_exits_successfully_with_skip_db_against_current_repo_state(): void
    {
        // Checks 2 and 3 rely on ownership-path/exemption-reason
        // metadata that a concurrent workstream (1A) is adding under
        // names this command cannot see yet — they SKIP rather than
        // FAIL until that metadata is merged. Checks 4 (static half),
        // 5, and 6 are pure static/filesystem checks that must already
        // pass against this repository's current, real state.
        $this->artisan('security:schema-firewall', ['--skip-db' => true])
            ->assertExitCode(0);
    }

    public function test_skip_db_output_reports_every_check_and_skips_the_db_dependent_ones(): void
    {
        Artisan::call('security:schema-firewall', ['--skip-db' => true]);
        $output = Artisan::output();

        // Deliberately asserting on the short "Check N" prefixes only,
        // not the full descriptive sentence: the console table renders
        // through Symfony's Table helper, which is free to word-wrap a
        // long cell depending on detected terminal width, and a strict
        // full-sentence-on-one-line assertion would be coupled to that
        // rendering detail rather than to what this test actually cares
        // about (that all six checks ran and at least one was skipped).
        foreach (['Check 1', 'Check 2', 'Check 3', 'Check 4', 'Check 5', 'Check 6'] as $checkPrefix) {
            $this->assertStringContainsString($checkPrefix, $output, "Expected output to mention {$checkPrefix}.");
        }

        $this->assertStringContainsString('SKIP', $output, 'Expected at least one SKIP status with --skip-db.');
    }

    public function test_check_4_static_migration_source_confirms_every_prepared_table(): void
    {
        // Directly mirrors what the command's own static half of check
        // 4 computes, so a future edit to the RLS-preparation
        // migrations that silently drops a CREATE POLICY or
        // ENABLE ROW LEVEL SECURITY statement is caught here too, not
        // only inside the command's own internals.
        $service = new RowLevelSecurityCoverageMappingService;
        $prepared = $service->preparedTables();

        $migrationFiles = glob(database_path('migrations/*row_level_security*.php')) ?: [];
        $this->assertNotEmpty($migrationFiles, 'Expected at least one RLS-preparation migration to exist.');

        $confirmed = [];

        foreach ($migrationFiles as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source);

            if (! preg_match('/private array \$tables\s*=\s*\[(.*?)\];/s', $source, $arrayMatch)) {
                continue;
            }

            preg_match_all("/'([a-z_][a-z0-9_]*)'/", $arrayMatch[1], $tableMatches);

            $hasEnable = stripos($source, 'ENABLE ROW LEVEL SECURITY') !== false;
            $hasPolicy = stripos($source, 'CREATE POLICY') !== false;

            $this->assertTrue(
                $hasEnable && $hasPolicy,
                basename($path).' must contain both ENABLE ROW LEVEL SECURITY and CREATE POLICY for the tables it declares.'
            );

            foreach ($tableMatches[1] as $table) {
                $confirmed[$table] = true;
            }
        }

        $unconfirmed = array_values(array_diff($prepared, array_keys($confirmed)));

        $this->assertEmpty(
            $unconfirmed,
            'The following prepared tables have no RLS-preparation migration source confirming ENABLE + CREATE POLICY: '
                .implode(', ', $unconfirmed)
        );
    }

    public function test_check_5_every_forced_table_has_a_matching_activation_test_file(): void
    {
        $service = new RowLevelSecurityCoverageMappingService;
        $forced = $service->forcedTables();

        $this->assertNotEmpty($forced, 'Expected at least one forced table to exist.');

        $missing = [];

        foreach ($forced as $table) {
            $expectedFilename = Str::studly($table).'ForceRlsActivationTest.php';

            if (! $this->testFileExistsAnywhereUnderTests($expectedFilename)) {
                $missing[] = "{$table} (expected {$expectedFilename})";
            }
        }

        $this->assertEmpty(
            $missing,
            'The following forced tables have no corresponding activation test file: '.implode(', ', $missing)
        );
    }

    public function test_check_6_finds_no_stale_hardcoded_counts_in_production_code_today(): void
    {
        // Directly mirrors the command's own check 6 grep logic against
        // the real app/ tree, rather than parsing the console table's
        // rendered output (which is coupled to Symfony Table's wrapping
        // behavior — see the note in the previous test).
        $excludedFiles = [
            app_path('Services/RowLevelSecurityCoverageMappingService.php'),
            app_path('Services/ComplianceGapRegistryService.php'),
            app_path('Console/Commands/SchemaTenantFirewallCommand.php'),
        ];

        $patterns = [
            '/\$\w*(prepared|forced|missingPrepared|tenantOwned|rlsCoverage|exempt)\w*count\w*\s*=\s*\d+\s*[;,]/i',
            '/\b\d+\s+of\s+(the\s+)?\d+\s+(prepared|tenant-owned|tenant owned|forced|rls|exempt)\b/i',
        ];

        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (in_array($path, $excludedFiles, true)) {
                continue;
            }

            $lines = file($path) ?: [];

            foreach ($lines as $lineNumber => $line) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = str_replace(base_path().'/', '', $path).':'.($lineNumber + 1).' — '.trim($line);
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Expected no stale hardcoded tenant/RLS counts in app/ outside the two registries. Found: '.implode(' | ', $violations)
        );
    }

    public function test_exits_successfully_against_the_live_database(): void
    {
        // Without --skip-db, checks 1 and the live half of check 4
        // query information_schema/pg_class/pg_policies directly —
        // the same live-schema diff RowLevelSecurityCoverageMappingServiceTest
        // already performs, now wired to fail a build rather than only
        // a manually-invoked test run.
        $this->artisan('security:schema-firewall')
            ->assertExitCode(0);
    }

    public function test_check_1_agrees_with_the_registry_service_live_schema_diff(): void
    {
        $service = new RowLevelSecurityCoverageMappingService;

        $rows = DB::select(<<<'SQL'
            select c.table_name
            from information_schema.columns c
            join information_schema.tables t
                on t.table_schema = c.table_schema
                and t.table_name = c.table_name
                and t.table_type = 'BASE TABLE'
            where c.table_schema = 'public'
              and c.column_name = 'firm_id'
              and c.is_nullable = 'NO'
            order by c.table_name
            SQL);

        $liveTables = array_map(fn ($row) => $row->table_name, $rows);

        $tracked = array_merge(
            $service->preparedTables(),
            $service->missingPreparedTables(),
            $service->exemptTables(),
        );

        $untracked = array_values(array_diff($liveTables, $tracked));

        $this->assertEmpty(
            $untracked,
            'security:schema-firewall check 1 would fail: the following live tables with a NOT NULL firm_id '
                .'column are not classified in the registry: '.implode(', ', $untracked)
        );
    }

    private function test_file_exists_anywhere_under_tests(string $filename): bool
    {
        $root = base_path('tests');

        if (! is_dir($root)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return true;
            }
        }

        return false;
    }
}
