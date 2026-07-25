<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * RlsSecurityReportService — extraction of RlsSecurityReportCommand's
 * report-assembly logic (Phase 1 FirmsVault Admin Control Center,
 * "Tenant Isolation" area). The command becomes a thin wrapper that
 * calls generate() and renders the result; this service is the single
 * place the report is actually assembled, so a future consumer (a
 * Filament Tenant Isolation page — built separately, not part of this
 * checkpoint) can call the exact same generate() method the CLI command
 * uses rather than re-implementing or shelling out to it.
 *
 * generate()'s return shape is byte-for-byte identical to the array
 * RlsSecurityReportCommand::handle() used to build directly — this is a
 * pure extraction, not a rewrite: every private method below is the
 * command's own former private method, unchanged in behavior (only
 * relocated, and made public where the command's thin wrapper needs to
 * call it directly for JSON/file output).
 *
 * Deliberately returns clean, page-renderable structured data (nested
 * arrays of scalars/booleans/nulls) — never a raw ANSI-formatted CLI
 * table string — so a Filament page can consume generate()'s output
 * directly without parsing terminal output. The command's own
 * human-readable rendering (renderHumanReadable()) stays in the command
 * class, since that IS CLI-output-formatting logic and does not belong
 * in a service a Filament page also depends on.
 *
 * Never activates, alters, or drops any RLS policy — read-only, exactly
 * like the command it was extracted from. See the command's own
 * docblock for the RowLevelSecurityCoverageMappingService public-surface
 * assumptions this service's buildTableRows()/classify() depend on.
 */
class RlsSecurityReportService
{
    /**
     * Phase 1 FirmsVault Admin Control Center addition — used only by
     * cachedGenerate()/forgetCachedGenerate() below, never by generate()
     * itself. The mission's explicit requirement for the new Tenant
     * Isolation Filament page: "do NOT run the full report on every
     * page request" — this is the full report's own snapshot/pg_policies
     * query cost (a live pg_policies scan plus a migration-repository
     * read), amortized across a short window rather than paid on every
     * page load.
     */
    private const CACHE_KEY = 'platform_admin.tenant_isolation.rls_security_report';

    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly RowLevelSecurityCoverageMappingService $coverage,
        private readonly Migrator $migrator,
    ) {}

    /**
     * Cached wrapper around generate() for the Tenant Isolation page —
     * the CLI command (RlsSecurityReportCommand) intentionally does NOT
     * use this; a manually-run report command should always reflect
     * live state, never a stale cache. See TenantIsolationPage's own
     * "Refresh" action for the one legitimate, rate-limited way to bust
     * this cache early.
     *
     * @return array<string, mixed>
     */
    public function cachedGenerate(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->generate());
    }

    public function forgetCachedGenerate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{
     *     generated_at: string,
     *     git_commit: ?string,
     *     database: array{driver: ?string, connected: bool, current_user: ?string, error: ?string},
     *     migrations: array{repository_exists: bool, all_applied: ?bool, ran_count: int, total_count: int, pending: array<int, string>, error: ?string},
     *     summary: array{prepared: int, forced: int, uncovered: int, exempt: int},
     *     tables: array<string, array<string, mixed>>,
     * }
     */
    public function generate(): array
    {
        $report = [
            'generated_at' => now()->toIso8601String(),
            'git_commit' => $this->gitCommit(),
            'database' => $this->databaseState(),
            'migrations' => $this->migrationState($this->migrator),
            'summary' => [],
            'tables' => [],
        ];

        $policiesByTable = $this->livePoliciesByTable($report['database']['driver'] ?? null, $report['database']['connected']);

        $report['tables'] = $this->buildTableRows($this->coverage, $policiesByTable);
        $report['summary'] = $this->summarize($this->coverage);

        return $report;
    }

    /**
     * Phase 1 FirmsVault Admin Control Center addition (purely
     * additive — does not change generate()'s own behavior/output in
     * any way). Reads the connected runtime role's rolsuper/rolbypassrls
     * attributes directly from pg_roles, the same query this
     * repository's own DatabaseRoleProofTest/*ForceRlsActivationTest
     * suite already uses to prove the runtime role is never granted
     * either. Built for the new Tenant Isolation Filament page, which
     * needs to surface "runtime role superuser/BYPASSRLS status"
     * directly — information generate()'s existing `database` section
     * (current_user/driver/connected only) does not carry, and which
     * this method deliberately does NOT add to generate()'s output,
     * to keep that method's return shape byte-for-byte identical to the
     * original command's.
     *
     * Never exposes a connection string, host, port, or credential —
     * only the role name and two boolean role attributes.
     *
     * @return array{role: ?string, is_superuser: ?bool, has_bypass_rls: ?bool, error: ?string}
     */
    public function runtimeRoleSecurityState(): array
    {
        $state = [
            'role' => null,
            'is_superuser' => null,
            'has_bypass_rls' => null,
            'error' => null,
        ];

        try {
            $row = DB::selectOne('select rolname, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

            if ($row === null) {
                $state['error'] = 'Could not resolve the current runtime role from pg_roles.';

                return $state;
            }

            $state['role'] = $row->rolname;
            $state['is_superuser'] = (bool) $row->rolsuper;
            $state['has_bypass_rls'] = (bool) $row->rolbypassrls;
        } catch (Throwable $e) {
            $state['error'] = $e->getMessage();
        }

        return $state;
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
     * Static file-existence check only — this never executes any test.
     * See RlsSecurityReportCommand's original docblock for why every
     * immediate subdirectory of tests/Feature/Security/ is scanned.
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
}
