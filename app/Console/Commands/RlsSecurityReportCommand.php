<?php

namespace App\Console\Commands;

use App\Services\RlsSecurityReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `security:rls-report` — a point-in-time, read-only snapshot of the
 * current PostgreSQL row-level security enforcement state.
 *
 * This command never activates, alters, or drops any RLS policy — it
 * only reads: the static registry (RowLevelSecurityCoverageMappingService),
 * the live database catalog (pg_policies, pg_class-derived current_user,
 * migration repository), git metadata, and the test tree on disk. It is
 * meant to be run by a human operator or CI against a real,
 * already-migrated database connection — several of its sections
 * (policy names, current_user, migration-applied state) are
 * intentionally unavailable/degraded when no usable database connection
 * exists, rather than faking or hardcoding that data.
 *
 * Phase 1 FirmsVault Admin Control Center change: report assembly was
 * extracted into RlsSecurityReportService::generate() (byte-for-byte
 * the same logic this command used to run inline) so a future Tenant
 * Isolation Filament page can call the identical service method rather
 * than re-implementing this report or shelling out to this command.
 * This class is now a thin wrapper: call the service, then render
 * (JSON, JSON-to-file, or human-readable table) — no report-assembly
 * logic lives here anymore. Output format/behavior is unchanged from
 * before this extraction.
 */
class RlsSecurityReportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'security:rls-report
        {--json : Print the report as JSON to stdout instead of a human-readable table}
        {--output= : Also write the JSON report to this file path}';

    /**
     * @var string
     */
    protected $description = 'Snapshot the current PostgreSQL row-level security enforcement state: prepared/forced/exempt/uncovered tables, live pg_policies, migration state, database role, git commit, and FORCE-activation test evidence.';

    public function handle(RlsSecurityReportService $reportService): int
    {
        $report = $reportService->generate();

        if ($outputPath = $this->option('output')) {
            $this->writeJsonToFile($outputPath, $report);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHumanReadable($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeJsonToFile(string $path, array $report): void
    {
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->components->info("RLS security report written to {$path}");
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHumanReadable(array $report): void
    {
        $this->components->twoColumnDetail('Git commit', $report['git_commit'] ?? '<unknown>');
        $this->components->twoColumnDetail('Database role (current_user)', $report['database']['current_user'] ?? '<unavailable>');
        $this->components->twoColumnDetail('Database driver', $report['database']['driver'] ?? '<unavailable>');

        if (! $report['database']['connected']) {
            $this->components->warn('No usable database connection — policies, current_user, and migration state are unavailable: '.($report['database']['error'] ?? 'unknown error'));
        }

        $migrations = $report['migrations'];

        if ($migrations['all_applied'] === true) {
            $this->components->twoColumnDetail('Migrations', "all applied ({$migrations['ran_count']}/{$migrations['total_count']})");
        } elseif ($migrations['all_applied'] === false) {
            $this->components->twoColumnDetail('Migrations', "{$migrations['ran_count']}/{$migrations['total_count']} applied, ".count($migrations['pending']).' pending');
        } else {
            $this->components->twoColumnDetail('Migrations', '<unknown> '.($migrations['error'] ?? ''));
        }

        $this->newLine();

        $rows = [];

        foreach ($report['tables'] as $table => $data) {
            $policies = $data['policies'];
            $policyCount = $policies === null ? 'unknown' : (string) count($policies);

            $testEvidence = '—';

            if ($data['force_activation_test'] !== null) {
                $testEvidence = $data['force_activation_test']['found'] ? 'yes' : 'MISSING';
            }

            $rows[] = [
                $table,
                $data['classification'],
                $data['rls_enabled'] ? 'yes' : 'no',
                $data['force_enabled'] ? 'yes' : 'no',
                $policyCount,
                $testEvidence,
            ];
        }

        $this->table(
            ['Table', 'Classification', 'RLS enabled', 'FORCE enabled', 'Policies', 'Force test evidence'],
            $rows,
        );

        $summary = $report['summary'];

        $this->newLine();
        $this->components->info(sprintf(
            '%d prepared, %d forced, %d uncovered, %d exempt.',
            $summary['prepared'],
            $summary['forced'],
            $summary['uncovered'],
            $summary['exempt'],
        ));
    }
}
