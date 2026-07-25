<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\RlsSecurityReportService;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * RlsSecurityReportServiceTest — Phase 1 FirmsVault Admin Control
 * Center, item 2 (extraction of RlsSecurityReportCommand's logic).
 * Proves generate()'s output is IDENTICAL to what the command itself
 * produces (the command is now a thin wrapper around this exact
 * method — RlsSecurityReportCommandTest, unchanged, already proves the
 * command's own behavior is unaffected; this file proves the service
 * independently and proves the two stay in lockstep), plus the new
 * cachedGenerate()/forgetCachedGenerate()/runtimeRoleSecurityState()
 * additions this checkpoint layers on top.
 */
class RlsSecurityReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_matches_the_commands_json_output_exactly(): void
    {
        Artisan::call('security:rls-report', ['--json' => true]);
        $commandPayload = json_decode(Artisan::output(), true);

        $servicePayload = app(RlsSecurityReportService::class)->generate();

        // generated_at/git_commit can legitimately differ by the
        // microseconds between the two calls (or if git state changed
        // mid-test, which it will not) — compare every OTHER top-level
        // key exactly, and just shape-check the timestamp/commit keys.
        foreach (['database', 'migrations', 'summary', 'tables'] as $key) {
            $this->assertSame($commandPayload[$key], $servicePayload[$key], "Top-level key '{$key}' must match the command's own output exactly.");
        }

        $this->assertArrayHasKey('generated_at', $servicePayload);
        $this->assertArrayHasKey('git_commit', $servicePayload);
    }

    public function test_generate_summary_matches_the_coverage_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $report = app(RlsSecurityReportService::class)->generate();

        $this->assertSame(count($coverage->preparedTables()), $report['summary']['prepared']);
        $this->assertSame(count($coverage->forcedTables()), $report['summary']['forced']);
        $this->assertSame(count($coverage->missingPreparedTables()), $report['summary']['uncovered']);
        $this->assertSame(count($coverage->exemptTables()), $report['summary']['exempt']);
    }

    public function test_runtime_role_security_state_reports_no_superuser_and_no_bypass_rls(): void
    {
        $state = app(RlsSecurityReportService::class)->runtimeRoleSecurityState();

        $this->assertNotNull($state['role']);
        $this->assertFalse($state['is_superuser'], 'The mission test database role must never be superuser.');
        $this->assertFalse($state['has_bypass_rls'], 'The mission test database role must never have BYPASSRLS.');
        $this->assertNull($state['error']);
    }

    public function test_cached_generate_returns_the_same_data_as_generate_and_is_actually_cached(): void
    {
        Cache::flush();

        $service = app(RlsSecurityReportService::class);

        $fresh = $service->generate();
        $cached = $service->cachedGenerate();

        $this->assertSame($fresh['summary'], $cached['summary']);
        $this->assertSame($fresh['tables'], $cached['tables']);

        // A second call must return the exact same generated_at (proving
        // it came from cache, not a fresh generate() call).
        $cachedAgain = $service->cachedGenerate();
        $this->assertSame($cached['generated_at'], $cachedAgain['generated_at']);
    }

    public function test_forget_cached_generate_busts_the_cache(): void
    {
        Cache::flush();

        $service = app(RlsSecurityReportService::class);

        $first = $service->cachedGenerate();
        usleep(1100 * 1000); // ensure a distinguishable generated_at second boundary
        $service->forgetCachedGenerate();
        $second = $service->cachedGenerate();

        $this->assertNotSame($first['generated_at'], $second['generated_at'], 'forgetCachedGenerate() must force the next call to regenerate.');
    }
}
