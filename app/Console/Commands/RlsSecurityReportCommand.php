<?php

namespace App\Console\Commands;

use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * `security:rls-report` — a point-in-time, read-only snapshot of the
 * current PostgreSQL row-level security enforcement state.
 *
 * This command never activates, alters, or drops any RLS policy — it
 * only reads: the static registry
 * (RowLevelSecurityCoverageMappingService), the live database catalog
 * (pg_policies, pg_class-derived current_user, migration repository),
 * git metadata, and the test tree on disk. It is meant to be run by a
 * human operator or CI against a real, already-migrated database
 * connection — several of its sections (policy names, current_user,
 * migration-applied state) are intentionally unavailable/degraded
 * when no usable database connection exists, rather than faking or
 * hardcoding that data.
 *
 * Assumptions about RowLevelSecurityCoverageMappingService's public
 * surface (current as of Section 39A-3L; a concurrent sibling effort,
 * Section 39A-4A "1A", is extending this same service on another
 * branch with fuller per-table classification metadata — verify at
 * merge time that these still hold):
 *  - preparedTables(): array<int, string>
 *  - missingPreparedTables(): array<int, string>
 *  - exemptTables(): array<int, string>
 *  - forcedTables(): array<int, string>
 *  - isPrepared(string $table): bool
 *  - isMissing(string $table): bool
 *  - isForced(string $table): bool
 *
 * If 1A's merged interface renames/reshapes any of these (e.g.
 * replaces the three flat arrays with a per-table classification
 * DTO/enum), buildTableRows() and classify() below are the only two
 * places that need updating to match — everything else in this
 * command (pg_policies query, migration state, git commit, test
 * evidence, rendering) is independent of that surface.
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

    public function handle(RowLevelSecurityCoverageMappingService $coverage, Migrator $migrator): int
    {
        $report = [
            'generated_at' => now()->toIso8601String(),
            'git_commit' => $this->gitCommit(),
            'database' => $this->databaseState(),
            'migrations' => $this->migrationState($migrator),
            'summary' => [],
            'tables' => [],
        ];

        $policiesByTable = $this->livePoliciesByTable($report['database']['driver'] ?? null, $report['database']['connected']);

        $report['tables'] = $this->buildTableRows($coverage, $policiesByTable);
        $report['summary'] = $this->summarize($coverage);

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
     * @return array{driver: ?string, connected: bool, current_user: ?string, error: ?string}
     */
    private function databaseState(): array
    {
        $state = [
            'driver' => null,
            'connected' => false,
            'current_user' => null,
            'error' => null,
        ];

        try {
            $state['driver'] = DB::connection()->getDriverName();
            $currentUser = DB::selectOne('select current_user');
            $state['current_user'] = $currentUser?->current_user ?? null;
            $state['connected'] = true;
        } catch (Throwable $e) {
            $state['error'] = $e->getMessage();
        }

        return $state;
    }

    /**
     * Migration-applied state, derived the same way Laravel's own
     * `migrate:status` command does it (Migrator::repositoryExists(),
     * Migrator::getRepository()->getRan(), Migrator::getMigrationFiles())
     * — deliberately not shelling out to `php artisan migrate:status`
     * from within this command.
     *
     * @return array{repository_exists: bool, all_applied: ?bool, ran_count: int, total_count: int, pending: array<int, string>, error: ?string}
     */
    private function migrationState(Migrator $migrator): array
    {
        $state = [
            'repository_exists' => false,
            'all_applied' => null,
            'ran_count' => 0,
            'total_count' => 0,
            'pending' => [],
            'error' => null,
        ];

        try {
            if (! $migrator->repositoryExists()) {
                $state['error'] = 'Migration repository (migrations table) not found.';

                return $state;
            }

            $state['repository_exists'] = true;

            $ran = $migrator->getRepository()->getRan();

            $paths = array_merge($migrator->paths(), [database_path('migrations')]);
            $files = $migrator->getMigrationFiles($paths);
            $names = array_keys($files);

            $pending = array_values(array_diff($names, $ran));

            $state['ran_count'] = count($ran);
            $state['total_count'] = count($names);
            $state['pending'] = $pending;
            $state['all_applied'] = $pending === [];
        } catch (Throwable $e) {
            $state['error'] = $e->getMessage();
        }

        return $state;
    }

    /**
     * Queries pg_policies once (when connected to a pgsql driver) and
     * groups the results by table name. Never hardcoded — if the
     * connection is unavailable or is not PostgreSQL, this returns
     * null and every table's `policies` field reflects "unknown"
     * rather than an empty/false list.
     *
     * @return array<string, array<int, array{name: string, cmd: string, permissive: string, roles: mixed}>>|null
     */
    private function livePoliciesByTable(?string $driver, bool $connected): ?array
    {
        if (! $connected || $driver !== 'pgsql') {
            return null;
        }

        try {
            $rows = DB::select('select tablename, policyname, cmd, permissive, roles from pg_policies order by tablename, policyname');
        } catch (Throwable $e) {
            return null;
        }

        $byTable = [];

        foreach ($rows as $row) {
            $byTable[$row->tablename][] = [
                'name' => $row->policyname,
                'cmd' => $row->cmd,
                'permissive' => $row->permissive,
                'roles' => $row->roles,
            ];
        }

        return $byTable;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>|null  $policiesByTable
     * @return array<string, array<string, mixed>>
     */
    private function buildTableRows(RowLevelSecurityCoverageMappingService $coverage, ?array $policiesByTable): array
    {
        $tracked = array_values(array_unique(array_merge(
            $coverage->tenantOwnedTables(),
            $coverage->exemptTables(),
        )));
        sort($tracked);

        $forced = $coverage->forcedTables();

        $rows = [];

        foreach ($tracked as $table) {
            $classification = $this->classify($coverage, $table);
            $isForced = in_array($table, $forced, true);

            $rows[$table] = [
                'classification' => $classification,
                'rls_enabled' => $coverage->isPrepared($table),
                'force_enabled' => $isForced,
                'policies' => $policiesByTable === null ? null : ($policiesByTable[$table] ?? []),
                'force_activation_test' => $isForced ? $this->forceActivationTestEvidence($table) : null,
            ];
        }

        return $rows;
    }

    private function classify(RowLevelSecurityCoverageMappingService $coverage, string $table): string
    {
        if ($coverage->isPrepared($table)) {
            return 'prepared';
        }

        if ($coverage->isMissing($table)) {
            return 'missing_prepared';
        }

        return 'exempt';
    }

    /**
     * Static file-existence check only — this never executes any
     * test. Looks for `{Studly}ForceRlsActivationTest.php` under any
     * immediate subdirectory of tests/Feature/Security/. The task
     * brief named tests/Feature/Security/RlsForceActivation/
     * specifically, but the repository's actual convention (see
     * ClientsForceRlsActivationTest.php vs. the other 52 FORCE tables'
     * tests) splits these across at least two sibling directories
     * (RlsForceActivation/ and RlsForceRollout/); scanning every
     * immediate subdirectory keeps this accurate without having to
     * track every batch's directory choice. Flagged for the merge
     * lead as a deliberate widening of the literal brief.
     *
     * @return array{found: bool, files: array<int, string>}
     */
    private function forceActivationTestEvidence(string $table): array
    {
        $studly = Str::studly($table);
        $pattern = "{$studly}ForceRlsActivationTest.php";

        $matches = array_merge(
            glob(base_path("tests/Feature/Security/{$pattern}")) ?: [],
            glob(base_path("tests/Feature/Security/*/{$pattern}")) ?: [],
        );

        $relative = array_map(
            static fn (string $path): string => Str::after($path, base_path().DIRECTORY_SEPARATOR),
            $matches,
        );

        return [
            'found' => $relative !== [],
            'files' => array_values($relative),
        ];
    }

    /**
     * @return array{prepared: int, forced: int, uncovered: int, exempt: int}
     */
    private function summarize(RowLevelSecurityCoverageMappingService $coverage): array
    {
        return [
            'prepared' => count($coverage->preparedTables()),
            'forced' => count($coverage->forcedTables()),
            'uncovered' => count($coverage->missingPreparedTables()),
            'exempt' => count($coverage->exemptTables()),
        ];
    }

    private function gitCommit(): ?string
    {
        try {
            $result = Process::path(base_path())->run('git rev-parse --short HEAD');

            if ($result->successful()) {
                $sha = trim($result->output());

                return $sha !== '' ? $sha : null;
            }
        } catch (Throwable $e) {
            // fall through to null below
        }

        return null;
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
