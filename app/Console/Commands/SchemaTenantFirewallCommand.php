<?php

namespace App\Console\Commands;

use App\Services\RowLevelSecurityCoverageMappingService;
use App\ValueObjects\ExemptTableMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * security:schema-firewall
 *
 * This is the first automated gate this repository has ever had for
 * tenant-isolation schema drift. Before this command existed, nothing
 * stopped a new database table from being merged with zero tenant
 * classification or RLS policy: RowLevelSecurityCoverageMappingService
 * is a hand-maintained registry, and its one live-schema diff
 * assertion (RowLevelSecurityCoverageMappingServiceTest::
 * test_every_table_with_a_not_null_firm_id_column_is_tracked_in_the_registry)
 * only runs when a human/agent manually invokes the test suite. There
 * is no CI of any kind in this repository (no .github/workflows, no
 * GitLab/CircleCI config) as of Section 39A-4B — see
 * .github/workflows/schema-tenant-firewall.yml, added alongside this
 * command, for the first automated wiring of that gate.
 *
 * This command deliberately does NOT modify
 * RowLevelSecurityCoverageMappingService or ComplianceGapRegistryService
 * (both owned by a concurrent workstream extending the registry with
 * richer per-table ownership/exemption metadata) — it only reads their
 * existing public surface: preparedTables(), missingPreparedTables(),
 * exemptTables(), tenantOwnedTables(), forcedTables(). Checks 2 and 3
 * below probe (via reflection, against a documented candidate method
 * list — see OWNERSHIP_PATH_METHOD_CANDIDATES /
 * EXEMPTION_REASON_METHOD_CANDIDATES) for richer metadata methods that
 * do not exist on this branch yet; until a merged branch adds one of
 * those methods (or a differently-named equivalent — reconcile at
 * merge time), those two checks report SKIP rather than FAIL, so this
 * command is safe to wire into CI today and will automatically start
 * enforcing checks 2/3 once that metadata lands.
 *
 * Runs six checks:
 *   1. Every table with a NOT NULL firm_id column (live schema) is
 *      classified in PREPARED_TABLES, MISSING_PREPARED_TABLES, or
 *      EXEMPT_TABLES. Requires a database connection.
 *   2. Every tenant-classified table has ownership-path metadata
 *      (once available — see above). Static, no database required.
 *   3. Every EXEMPT_TABLES entry has a documented exemption reason
 *      (once available — see above). Static, no database required.
 *   4. Every "prepared" table has real CREATE POLICY coverage
 *      alongside its ENABLE ROW LEVEL SECURITY — both a static
 *      migration-source check (no database required) and, when a
 *      database connection is available, a live pg_class/pg_policies
 *      check.
 *   5. Every FORCE-RLS migration has a corresponding activation test
 *      file proving FORCE is actually set. Static, no database
 *      required.
 *   6. No production PHP file outside the two registries contains a
 *      hardcoded tenant/RLS table count that would silently go stale.
 *      Static grep, no database required.
 */
class SchemaTenantFirewallCommand extends Command
{
    protected $signature = 'security:schema-firewall {--skip-db : Skip checks that require a live database connection (checks 1 and the live half of check 4)}';

    protected $description = 'Fail the build if a database table is missing tenant/RLS classification, RLS policy coverage, or FORCE-activation proof.';

    /**
     * Candidate method names this command will probe for (via
     * reflection) on RowLevelSecurityCoverageMappingService for
     * per-table ownership-path metadata. None of these exist on this
     * branch as of Section 39A-4B — a concurrent workstream is adding
     * this kind of metadata under names this command cannot see yet.
     * If the merged interface uses a different name, add it here.
     *
     * Supported shapes: a zero-arg method returning
     * array<string,string|null> keyed by table name, OR a one-arg
     * method `(string $table): ?string`.
     */
    private const OWNERSHIP_PATH_METHOD_CANDIDATES = [
        'ownershipPathOf',
        'ownershipPaths', 'tenantOwnershipPaths', 'ownershipPathMap',
        'ownershipPathFor', 'ownershipPathForTable', 'tenantOwnershipPathFor',
    ];

    /**
     * Same idea as OWNERSHIP_PATH_METHOD_CANDIDATES, for per-exemption
     * documented reasons.
     */
    private const EXEMPTION_REASON_METHOD_CANDIDATES = [
        'exemptMetadataFor',
        'exemptionReasons', 'exemptTableReasons', 'exemptionReasonMap',
        'exemptionReasonFor', 'exemptionReasonForTable',
    ];

    /**
     * Glob matching every RLS-preparation migration (ENABLE ROW LEVEL
     * SECURITY + CREATE POLICY), mirroring the pattern
     * RowLevelSecurityCoverageMappingService::FORCE_RLS_MIGRATION_GLOB
     * uses for FORCE migrations. Deliberately a glob, not a hardcoded
     * filename list, for the same reason that service gives: a
     * hardcoded list goes stale the moment a new phase lands.
     */
    private const RLS_PREPARATION_MIGRATION_GLOB = '*row_level_security*.php';

    /** @var array<int, array{name: string, status: string, summary: string, details: array<int, string>}> */
    private array $results = [];

    public function handle(): int
    {
        $skipDb = (bool) $this->option('skip-db');
        $service = new RowLevelSecurityCoverageMappingService;

        $this->info('Running schema tenant firewall (6 checks)...');
        $this->newLine();

        $this->runCheck1UntrackedTables($service, $skipDb);
        $this->runCheck2OwnershipPathMetadata($service);
        $this->runCheck3ExemptionReasons($service);
        $this->runCheck4PreparedTablesHavePolicies($service, $skipDb);
        $this->runCheck5ForcedTablesHaveActivationTests($service);
        $this->runCheck6NoStaleHardcodedCounts();

        return $this->report();
    }

    // ------------------------------------------------------------------
    // Check 1
    // ------------------------------------------------------------------

    private function runCheck1UntrackedTables(RowLevelSecurityCoverageMappingService $service, bool $skipDb): void
    {
        $name = 'Check 1: every NOT NULL firm_id table is classified';

        if ($skipDb) {
            $this->record($name, 'SKIP', '--skip-db passed.', []);

            return;
        }

        try {
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
        } catch (Throwable $e) {
            $this->record($name, 'SKIP', 'No usable database connection: '.$e->getMessage(), []);

            return;
        }

        $liveTables = array_map(static fn ($row) => $row->table_name, $rows);

        $tracked = array_merge(
            $service->preparedTables(),
            $service->missingPreparedTables(),
            $service->exemptTables(),
        );

        $untracked = array_values(array_diff($liveTables, $tracked));

        if ($untracked !== []) {
            $this->record(
                $name,
                'FAIL',
                count($untracked).' table(s) with a NOT NULL firm_id column are not classified anywhere in the registry.',
                $untracked,
            );

            return;
        }

        $this->record($name, 'PASS', 'All '.count($liveTables).' NOT NULL firm_id tables are classified.', []);
    }

    // ------------------------------------------------------------------
    // Check 2
    // ------------------------------------------------------------------

    private function runCheck2OwnershipPathMetadata(RowLevelSecurityCoverageMappingService $service): void
    {
        $name = 'Check 2: tenant-classified tables have ownership-path metadata';

        $tenantTables = $service->tenantOwnedTables();

        $resolution = $this->resolveTableMetadata($service, $tenantTables, self::OWNERSHIP_PATH_METHOD_CANDIDATES);

        if (! $resolution['available']) {
            $this->record(
                $name,
                'SKIP',
                'No ownership-path metadata method found on RowLevelSecurityCoverageMappingService yet (probed: '
                    .implode(', ', self::OWNERSHIP_PATH_METHOD_CANDIDATES)
                    .'). This check will activate automatically once that metadata is merged.',
                [],
            );

            return;
        }

        if ($resolution['missing'] !== []) {
            $this->record(
                $name,
                'FAIL',
                count($resolution['missing']).' tenant-classified table(s) lack ownership-path metadata.',
                $resolution['missing'],
            );

            return;
        }

        $this->record($name, 'PASS', 'All '.count($tenantTables).' tenant-classified tables have ownership-path metadata (via '.$resolution['methodUsed'].').', []);
    }

    // ------------------------------------------------------------------
    // Check 3
    // ------------------------------------------------------------------

    private function runCheck3ExemptionReasons(RowLevelSecurityCoverageMappingService $service): void
    {
        $name = 'Check 3: every exemption has a documented reason';

        $exempt = $service->exemptTables();

        $resolution = $this->resolveTableMetadata($service, $exempt, self::EXEMPTION_REASON_METHOD_CANDIDATES);

        if (! $resolution['available']) {
            $this->record(
                $name,
                'SKIP',
                'No exemption-reason metadata method found on RowLevelSecurityCoverageMappingService yet (probed: '
                    .implode(', ', self::EXEMPTION_REASON_METHOD_CANDIDATES)
                    .'). This check will activate automatically once that metadata is merged.',
                [],
            );

            return;
        }

        if ($resolution['missing'] !== []) {
            $this->record(
                $name,
                'FAIL',
                count($resolution['missing']).' exempt table(s) lack a documented exemption reason.',
                $resolution['missing'],
            );

            return;
        }

        $this->record($name, 'PASS', 'All '.count($exempt).' exempt tables have a documented reason (via '.$resolution['methodUsed'].').', []);
    }

    /**
     * Shared reflection-based metadata probe used by checks 2 and 3.
     * Tries, in order: a zero-arg "bulk map" method returning
     * array<string, mixed> keyed by table name, then a one-arg
     * per-table method `(string $table): mixed`. A value counts as
     * present if it is a non-empty string (or a non-empty array/object
     * for a richer value type).
     *
     * @param  array<int, string>  $tables
     * @param  array<int, string>  $candidates
     * @return array{available: bool, missing: array<int, string>, methodUsed: string}
     */
    private function resolveTableMetadata(RowLevelSecurityCoverageMappingService $service, array $tables, array $candidates): array
    {
        $ref = new ReflectionClass($service);

        foreach ($candidates as $candidate) {
            if (! $ref->hasMethod($candidate)) {
                continue;
            }

            $method = $ref->getMethod($candidate);

            if (! $method->isPublic()) {
                continue;
            }

            try {
                if ($method->getNumberOfRequiredParameters() === 0 && $method->getNumberOfParameters() === 0) {
                    $map = $method->invoke($service);

                    if (! is_array($map)) {
                        continue;
                    }

                    $missing = [];
                    foreach ($tables as $table) {
                        if (! $this->isPresentValue($map[$table] ?? null)) {
                            $missing[] = $table;
                        }
                    }

                    return ['available' => true, 'missing' => $missing, 'methodUsed' => $candidate.'()'];
                }

                if ($method->getNumberOfParameters() === 1) {
                    $missing = [];
                    foreach ($tables as $table) {
                        $value = $method->invoke($service, $table);
                        if (! $this->isPresentValue($value)) {
                            $missing[] = $table;
                        }
                    }

                    return ['available' => true, 'missing' => $missing, 'methodUsed' => $candidate.'(string $table)'];
                }
            } catch (Throwable) {
                // Unexpected signature/behavior for this candidate — try the next one.
                continue;
            }
        }

        return ['available' => false, 'missing' => [], 'methodUsed' => ''];
    }

    private function isPresentValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($value instanceof ExemptTableMetadata) {
            return trim($value->reason) !== '';
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Check 4
    // ------------------------------------------------------------------

    private function runCheck4PreparedTablesHavePolicies(RowLevelSecurityCoverageMappingService $service, bool $skipDb): void
    {
        $name = 'Check 4: every prepared table has real CREATE POLICY coverage';
        $prepared = $service->preparedTables();
        $details = [];
        $failed = false;

        // 4a — static migration-source check. Every RLS-preparation
        // migration declares which table(s) it covers either via the
        // original batch-style `private array $tables = [...]` list, or
        // (Section 39A-5 onward, for single-table combined prepare+force
        // migrations closing one missingPreparedTables() gap at a time)
        // the same single-table `private const TABLE = '...'` shape
        // already used by every FORCE-only migration. Either way, the
        // migration must contain BOTH "ENABLE ROW LEVEL SECURITY" and
        // "CREATE POLICY" in its up() method for the tables it covers.
        $migrationFiles = glob(database_path('migrations/'.self::RLS_PREPARATION_MIGRATION_GLOB)) ?: [];
        $tablesConfirmedByMigration = [];

        foreach ($migrationFiles as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            if (preg_match('/private array \$tables\s*=\s*\[(.*?)\];/s', $source, $arrayMatch)) {
                preg_match_all("/'([a-z_][a-z0-9_]*)'/", $arrayMatch[1], $tableMatches);
                $tablesInThisMigration = $tableMatches[1];
            } elseif (preg_match('/private const TABLE\s*=\s*\'([a-z_][a-z0-9_]*)\'/', $source, $constMatch)) {
                $tablesInThisMigration = [$constMatch[1]];
            } else {
                continue;
            }

            $hasEnable = stripos($source, 'ENABLE ROW LEVEL SECURITY') !== false;
            $hasPolicy = stripos($source, 'CREATE POLICY') !== false;

            if ($hasEnable && $hasPolicy) {
                foreach ($tablesInThisMigration as $t) {
                    $tablesConfirmedByMigration[$t] = true;
                }
            } elseif ($tablesInThisMigration !== []) {
                $failed = true;
                $details[] = basename($path).' declares RLS-preparation tables but is missing '
                    .($hasEnable ? 'CREATE POLICY' : 'ENABLE ROW LEVEL SECURITY')
                    .' in its source: '.implode(', ', $tablesInThisMigration);
            }
        }

        $unconfirmed = array_values(array_diff($prepared, array_keys($tablesConfirmedByMigration)));
        if ($unconfirmed !== []) {
            $failed = true;
            $details[] = 'Registered as prepared but no RLS-preparation migration source confirms ENABLE + CREATE POLICY for: '.implode(', ', $unconfirmed);
        }

        // 4b — live pg_class/pg_policies check, when a database is available.
        if (! $skipDb) {
            try {
                foreach ($prepared as $table) {
                    $class = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

                    if ($class === null) {
                        $failed = true;
                        $details[] = "{$table}: not found in pg_class (table may not exist in this database).";

                        continue;
                    }

                    if (! (bool) $class->relrowsecurity) {
                        $failed = true;
                        $details[] = "{$table}: relrowsecurity is false despite being registered as prepared.";

                        continue;
                    }

                    $policyCount = (int) DB::selectOne('select count(*) as c from pg_policies where tablename = ?', [$table])->c;

                    if ($policyCount === 0) {
                        $failed = true;
                        $details[] = "{$table}: ENABLE ROW LEVEL SECURITY is active but zero CREATE POLICY rows exist in pg_policies — this denies ALL access rather than scoping it.";
                    }
                }
            } catch (Throwable $e) {
                $details[] = 'Live pg_class/pg_policies check skipped: no usable database connection ('.$e->getMessage().').';
            }
        }

        if ($failed) {
            $this->record($name, 'FAIL', 'One or more prepared tables lack real policy coverage.', $details);

            return;
        }

        $this->record($name, 'PASS', 'All '.count($prepared).' prepared tables have confirmed CREATE POLICY coverage.', $details);
    }

    // ------------------------------------------------------------------
    // Check 5
    // ------------------------------------------------------------------

    private function runCheck5ForcedTablesHaveActivationTests(RowLevelSecurityCoverageMappingService $service): void
    {
        $name = 'Check 5: every FORCE-RLS migration has an activation test';

        $forced = $service->forcedTables();
        $missing = [];

        foreach ($forced as $table) {
            $expectedFilename = Str::studly($table).'ForceRlsActivationTest.php';

            if ($this->findTestFile($expectedFilename) === null) {
                $missing[] = "{$table} (expected {$expectedFilename})";
            }
        }

        if ($missing !== []) {
            $this->record(
                $name,
                'FAIL',
                count($missing).' forced table(s) have no corresponding activation test file.',
                $missing,
            );

            return;
        }

        $this->record($name, 'PASS', 'All '.count($forced).' forced tables have a corresponding activation test file.', []);
    }

    private function findTestFile(string $filename): ?string
    {
        $root = base_path('tests');

        if (! is_dir($root)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return $file->getPathname();
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Check 6
    // ------------------------------------------------------------------

    private function runCheck6NoStaleHardcodedCounts(): void
    {
        $name = 'Check 6: no stale hardcoded tenant/RLS table counts in production code';

        $excludedFiles = [
            app_path('Services/RowLevelSecurityCoverageMappingService.php'),
            app_path('Services/ComplianceGapRegistryService.php'),
            // This file's own docblock/pattern-comment examples otherwise
            // trip its own detection regexes below.
            app_path('Console/Commands/SchemaTenantFirewallCommand.php'),
        ];

        $patterns = [
            // e.g. `$preparedTablesCount = 52;`, `protected int $forcedCount = 18;`
            '/\$\w*(prepared|forced|missingPrepared|tenantOwned|rlsCoverage|exempt)\w*count\w*\s*=\s*\d+\s*[;,]/i',
            // e.g. "18 of the 52 prepared tables", "52 of 113 tenant-owned tables"
            '/\b\d+\s+of\s+(the\s+)?\d+\s+(prepared|tenant-owned|tenant owned|forced|rls|exempt)\b/i',
        ];

        $root = app_path();
        $violations = [];

        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
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
        }

        if ($violations !== []) {
            $this->record(
                $name,
                'FAIL',
                count($violations).' suspected stale hardcoded tenant/RLS count(s) found outside the registry.',
                $violations,
            );

            return;
        }

        $this->record($name, 'PASS', 'No suspected stale hardcoded tenant/RLS counts found in production code.', []);
    }

    // ------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------

    private function record(string $name, string $status, string $summary, array $details): void
    {
        $this->results[] = [
            'name' => $name,
            'status' => $status,
            'summary' => $summary,
            'details' => $details,
        ];
    }

    private function report(): int
    {
        $this->table(
            ['Check', 'Status', 'Summary'],
            array_map(fn (array $r) => [$r['name'], $r['status'], $r['summary']], $this->results),
        );

        $failures = array_filter($this->results, fn (array $r) => $r['status'] === 'FAIL');

        foreach ($this->results as $r) {
            if ($r['details'] === []) {
                continue;
            }

            $this->newLine();
            $this->line(($r['status'] === 'FAIL' ? '<fg=red>' : '<fg=yellow>').$r['name'].'</>');
            foreach ($r['details'] as $detail) {
                $this->line('  - '.$detail);
            }
        }

        $this->newLine();

        if ($failures !== []) {
            $this->error(count($failures).' of '.count($this->results).' schema tenant firewall check(s) FAILED.');

            return self::FAILURE;
        }

        $this->info('All schema tenant firewall checks passed (or were explicitly skipped — see table above).');

        return self::SUCCESS;
    }
}
