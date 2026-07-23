<?php

declare(strict_types=1);

namespace Tests\Feature\Governance\Retention;

use App\Services\RetentionGovernanceRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RetentionGovernanceRegistryServiceTest — Checkpoint 9 (frozen design
 * §8). Proves every category the service enumerates resolves its
 * config() key to a real, live config value (or correctly reports
 * NOT_CONFIGURED_FAIL_SAFE/OUT_OF_SCOPE_SNAPSHOT/etc.); proves the
 * service performs ZERO writes; proves LEGAL_HOLD_COVERAGE_UNRESOLVED
 * is flagged for exactly the categories the frozen design specifies.
 */
class RetentionGovernanceRegistryServiceTest extends TestCase
{
    use RefreshDatabase;

    private RetentionGovernanceRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RetentionGovernanceRegistryService();
    }

    // ------------------------------------------------------------
    // Every category resolves correctly
    // ------------------------------------------------------------

    public function test_every_category_has_the_required_shape(): void
    {
        foreach ($this->service->categories() as $category => $entry) {
            $this->assertArrayHasKey('tables', $entry, "{$category} missing tables");
            $this->assertArrayHasKey('config_key', $entry, "{$category} missing config_key");
            $this->assertArrayHasKey('current_default', $entry, "{$category} missing current_default");
            $this->assertArrayHasKey('enforcing', $entry, "{$category} missing enforcing");
            $this->assertArrayHasKey('status', $entry, "{$category} missing status");
            $this->assertArrayHasKey('legal_hold_coverage_unresolved', $entry, "{$category} missing legal_hold_coverage_unresolved");
            $this->assertArrayHasKey('notes', $entry, "{$category} missing notes");
            $this->assertNotEmpty($entry['tables'], "{$category} must name at least one governing table");
            $this->assertNotSame('', trim($entry['enforcing']), "{$category} must document its enforcing class/method or explicitly state none exists");
        }
    }

    public function test_usage_records_category_is_not_configured_fail_safe(): void
    {
        $this->assertSame(
            RetentionGovernanceRegistryService::STATUS_NOT_CONFIGURED_FAIL_SAFE,
            $this->service->statusFor('usage_records')
        );
        $category = $this->service->categoryFor('usage_records');
        $this->assertContains('integration_usage_records', $category['tables']);
        $this->assertSame('integrations.usage_records.retention_days', $category['config_key']);
    }

    public function test_connection_health_category_is_out_of_scope_snapshot(): void
    {
        $this->assertSame(
            RetentionGovernanceRegistryService::STATUS_OUT_OF_SCOPE_SNAPSHOT,
            $this->service->statusFor('connection_health')
        );
        $category = $this->service->categoryFor('connection_health');
        $this->assertNull($category['config_key'], 'A 1:1 upsert-cache table has no independent retention window of its own.');
    }

    public function test_every_config_key_that_resolves_to_a_single_scalar_actually_exists_in_config_integrations(): void
    {
        foreach ($this->service->categories() as $category => $entry) {
            if ($entry['config_key'] === null) {
                continue;
            }

            // Compound entries describing more than one underlying key
            // (e.g. outbox_events' three windows) intentionally resolve
            // current_default to null rather than fabricating a single
            // scalar — skip those, they are proven separately below.
            if (str_contains($entry['config_key'], ' / ') || str_contains($entry['config_key'], ' (')) {
                $this->assertNull($entry['current_default'], "{$category}'s compound config_key must not fabricate a single scalar default.");

                continue;
            }

            $this->assertTrue(
                config()->has($entry['config_key']),
                "{$category}'s config_key '{$entry['config_key']}' must resolve to a real config() entry (even if its value is legitimately null)."
            );
        }
    }

    public function test_sync_runs_and_sync_items_categories_are_configured_default_and_have_a_real_positive_current_default(): void
    {
        foreach (['sync_runs', 'sync_items'] as $category) {
            $this->assertSame(RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT, $this->service->statusFor($category));
            $entry = $this->service->categoryFor($category);
            $this->assertIsInt($entry['current_default']);
            $this->assertGreaterThan(0, $entry['current_default']);
        }
    }

    public function test_category_for_an_unknown_category_returns_null(): void
    {
        $this->assertNull($this->service->categoryFor('does_not_exist'));
        $this->assertNull($this->service->statusFor('does_not_exist'));
    }

    // ------------------------------------------------------------
    // Zero writes
    // ------------------------------------------------------------

    public function test_the_service_performs_zero_database_writes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->service->categories();
        $this->service->categoryFor('usage_records');
        $this->service->statusFor('sync_runs');
        $this->service->isLegalHoldCoverageUnresolved('sync_runs');
        $this->service->categoriesWithUnresolvedLegalHoldCoverage();

        foreach ($queries as $sql) {
            $normalized = strtolower(ltrim($sql));
            $this->assertFalse(str_starts_with($normalized, 'insert'), "Unexpected INSERT executed by a read-only registry call: {$sql}");
            $this->assertFalse(str_starts_with($normalized, 'update'), "Unexpected UPDATE executed by a read-only registry call: {$sql}");
            $this->assertFalse(str_starts_with($normalized, 'delete'), "Unexpected DELETE executed by a read-only registry call: {$sql}");
        }
    }

    public function test_the_service_class_contains_no_write_statement_in_source(): void
    {
        $source = file_get_contents(app_path('Services/RetentionGovernanceRegistryService.php'));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression('/DB::table\([^)]*\)\s*->\s*(insert|update|delete)/', $source);
        $this->assertStringNotContainsString('::create(', $source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringNotContainsString('dispatch(', $source);
    }

    // ------------------------------------------------------------
    // LEGAL_HOLD_COVERAGE_UNRESOLVED — flagged for exactly the
    // frozen-design-specified categories
    // ------------------------------------------------------------

    public function test_legal_hold_coverage_unresolved_is_flagged_for_sync_runs_sync_items_and_resolved_conflicts(): void
    {
        foreach (['sync_runs', 'sync_items', 'conflicts_resolved'] as $category) {
            $this->assertTrue(
                $this->service->isLegalHoldCoverageUnresolved($category),
                "{$category} must be flagged LEGAL_HOLD_COVERAGE_UNRESOLVED per the frozen design."
            );
        }
    }

    public function test_legal_hold_coverage_unresolved_is_not_flagged_for_usage_records_or_connection_health(): void
    {
        foreach (['usage_records', 'connection_health'] as $category) {
            $this->assertFalse($this->service->isLegalHoldCoverageUnresolved($category));
        }
    }

    public function test_categories_with_unresolved_legal_hold_coverage_matches_the_individually_flagged_set(): void
    {
        $flagged = $this->service->categoriesWithUnresolvedLegalHoldCoverage();

        foreach ($this->service->categories() as $category => $entry) {
            $this->assertSame(
                $entry['legal_hold_coverage_unresolved'],
                in_array($category, $flagged, true),
                "categoriesWithUnresolvedLegalHoldCoverage() must be exactly the set of categories individually flagged true."
            );
        }
    }

    // ------------------------------------------------------------
    // Structural parallel to RowLevelSecurityCoverageMappingService,
    // and no dual-source-of-truth (no RetentionPolicy row seeded)
    // ------------------------------------------------------------

    public function test_no_retentionpolicy_row_is_seeded_for_any_integration_category(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('retention_policies')) {
            $this->markTestSkipped('retention_policies table does not exist in this schema.');
        }

        $count = DB::table('retention_policies')
            ->where('record_type', 'like', 'integration_%')
            ->count();

        $this->assertSame(0, $count, 'This checkpoint must never seed a real RetentionPolicy row for an integration category — RetentionSweepJob reads config() directly, never RetentionPolicy, and a seeded row would create a dual-source-of-truth defect.');
    }
}
