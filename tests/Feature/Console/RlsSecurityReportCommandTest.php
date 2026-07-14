<?php

namespace Tests\Feature\Console;

use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RlsSecurityReportCommandTest — proves `security:rls-report` produces
 * a correctly structured snapshot in both JSON and human-readable
 * form, without altering any RLS state itself (it is read-only: no
 * migration, no policy, no schema change is expected as a side effect
 * of running it).
 *
 * This test requires a real PostgreSQL connection (the same
 * disposable RLS test database every other Feature/Security test in
 * this repository runs against) because the command itself queries
 * pg_policies, current_user, and the migration repository — it is not
 * meant to run against sqlite/in-memory.
 */
class RlsSecurityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_output_has_the_expected_top_level_shape(): void
    {
        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload, 'Command output with --json must be valid JSON.');

        foreach (['generated_at', 'git_commit', 'database', 'migrations', 'summary', 'tables'] as $key) {
            $this->assertArrayHasKey($key, $payload, "Report is missing top-level key '{$key}'.");
        }

        foreach (['current_user', 'driver', 'connected', 'error'] as $key) {
            $this->assertArrayHasKey($key, $payload['database'], "database section is missing '{$key}'.");
        }

        foreach (['repository_exists', 'all_applied', 'ran_count', 'total_count', 'pending', 'error'] as $key) {
            $this->assertArrayHasKey($key, $payload['migrations'], "migrations section is missing '{$key}'.");
        }

        foreach (['prepared', 'forced', 'uncovered', 'exempt'] as $key) {
            $this->assertArrayHasKey($key, $payload['summary'], "summary section is missing '{$key}'.");
        }
    }

    public function test_summary_counts_match_the_coverage_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(count($coverage->preparedTables()), $payload['summary']['prepared']);
        $this->assertSame(count($coverage->forcedTables()), $payload['summary']['forced']);
        $this->assertSame(count($coverage->missingPreparedTables()), $payload['summary']['uncovered']);
        $this->assertSame(count($coverage->exemptTables()), $payload['summary']['exempt']);
    }

    public function test_every_tracked_table_appears_exactly_once_with_a_valid_classification(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $expectedTables = array_unique(array_merge($coverage->tenantOwnedTables(), $coverage->exemptTables()));

        $this->assertSame(count($expectedTables), count($payload['tables']));

        foreach ($expectedTables as $table) {
            $this->assertArrayHasKey($table, $payload['tables'], "Report is missing tracked table '{$table}'.");
            $this->assertContains(
                $payload['tables'][$table]['classification'],
                ['prepared', 'missing_prepared', 'exempt'],
            );
        }
    }

    public function test_forced_tables_report_force_activation_test_evidence_and_non_forced_tables_report_null(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $forced = $coverage->forcedTables();

        foreach ($payload['tables'] as $table => $data) {
            if (in_array($table, $forced, true)) {
                $this->assertIsArray($data['force_activation_test'], "Forced table '{$table}' must report force_activation_test evidence.");
                $this->assertArrayHasKey('found', $data['force_activation_test']);
                $this->assertArrayHasKey('files', $data['force_activation_test']);
            } else {
                $this->assertNull($data['force_activation_test'], "Non-forced table '{$table}' must report null force_activation_test evidence.");
            }
        }
    }

    public function test_clients_force_activation_test_is_found_on_disk(): void
    {
        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertTrue(
            $payload['tables']['clients']['force_activation_test']['found'],
            'ClientsForceRlsActivationTest.php exists on disk and must be detected as force-activation test evidence for clients.'
        );
    }

    public function test_git_commit_is_a_short_sha_when_git_is_available(): void
    {
        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        if ($payload['git_commit'] === null) {
            $this->markTestSkipped('git rev-parse was unavailable in this environment.');
        }

        $this->assertMatchesRegularExpression('/^[0-9a-f]{4,40}$/', $payload['git_commit']);
    }

    public function test_database_current_user_is_reported_when_connected(): void
    {
        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        if (! $payload['database']['connected']) {
            $this->markTestSkipped('No live database connection available in this environment.');
        }

        $this->assertNotEmpty($payload['database']['current_user']);
    }

    public function test_migrations_are_reported_as_fully_applied_in_a_freshly_migrated_test_database(): void
    {
        Artisan::call('security:rls-report', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true);

        if (! $payload['migrations']['repository_exists']) {
            $this->markTestSkipped('Migration repository unavailable in this environment.');
        }

        $this->assertTrue(
            $payload['migrations']['all_applied'],
            'RefreshDatabase runs every migration before each test, so the report must show all_applied=true here.'
        );
        $this->assertSame([], $payload['migrations']['pending']);
    }

    public function test_output_option_writes_the_json_report_to_the_given_path(): void
    {
        $path = storage_path('framework/testing/rls-security-report-test.json');

        if (file_exists($path)) {
            unlink($path);
        }

        Artisan::call('security:rls-report', ['--output' => $path]);

        $this->assertFileExists($path);

        $payload = json_decode(file_get_contents($path), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('summary', $payload);

        unlink($path);
    }

    public function test_default_invocation_renders_a_human_readable_summary_line(): void
    {
        Artisan::call('security:rls-report');

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\d+ prepared, \d+ forced, \d+ uncovered, \d+ exempt\./', $output);
        $this->assertStringContainsString('Git commit', $output);
    }

    public function test_command_makes_no_schema_or_policy_changes(): void
    {
        $before = DB::select('select relname, relrowsecurity, relforcerowsecurity from pg_class where relkind = \'r\' order by relname');

        Artisan::call('security:rls-report', ['--json' => true]);

        $after = DB::select('select relname, relrowsecurity, relforcerowsecurity from pg_class where relkind = \'r\' order by relname');

        $this->assertEquals($before, $after, 'security:rls-report must be strictly read-only against the database schema.');
    }
}
