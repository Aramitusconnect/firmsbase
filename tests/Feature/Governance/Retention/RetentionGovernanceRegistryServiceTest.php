<?php

declare(strict_types=1);

namespace Tests\Feature\Governance\Retention;

use App\Services\RetentionGovernanceRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->service = new RetentionGovernanceRegistryService;
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

    // sync_runs and sync_items are tested independently (not in a shared
    // loop) so that a future regression in one is never masked by, or
    // mistaken for, a failure in the other — see the type-normalization
    // regression this checkpoint fixed, where CI's PHPUnit run stopped at
    // sync_runs and sync_items was never actually exercised that run.

    public function test_sync_runs_category_is_configured_default_and_has_a_real_positive_current_default(): void
    {
        $this->assertSame(RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT, $this->service->statusFor('sync_runs'));
        $entry = $this->service->categoryFor('sync_runs');
        $this->assertIsInt($entry['current_default']);
        $this->assertGreaterThan(0, $entry['current_default']);
    }

    public function test_sync_items_category_is_configured_default_and_has_a_real_positive_current_default(): void
    {
        $this->assertSame(RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT, $this->service->statusFor('sync_items'));
        $entry = $this->service->categoryFor('sync_items');
        $this->assertIsInt($entry['current_default']);
        $this->assertGreaterThan(0, $entry['current_default']);
    }

    // ------------------------------------------------------------
    // current_default type normalization — Laravel's env() helper
    // returns a raw STRING for any environment variable that is
    // actually present (only true/false/null/empty are special-cased),
    // so config('integrations.sync_runs.retention_days') resolves to
    // the string "180" the moment INTEGRATIONS_SYNC_RUNS_RETENTION_DAYS
    // exists in the process environment at all — exactly what CI's
    // schema-tenant-firewall.yml workflow triggers via `cp
    // .env.example .env`, which materializes every key in
    // .env.example (including this one) as a real environment string.
    // These prove the registry normalizes at the config() boundary
    // instead of ever surfacing that raw string.
    // ------------------------------------------------------------

    public function test_integer_config_value_normalizes_to_the_same_integer(): void
    {
        config(['integrations.sync_runs.retention_days' => 180]);
        $this->assertSame(180, $this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_numeric_string_config_value_normalizes_to_an_integer(): void
    {
        config(['integrations.sync_runs.retention_days' => '180']);
        $this->assertSame(180, $this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_empty_string_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => '']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_non_numeric_string_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => 'abc']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_decimal_string_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => '180.5']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_whitespace_padded_string_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => ' 180 ']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_non_numeric_suffixed_string_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => '180days']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_integer_zero_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => 0]);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_string_zero_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => '0']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_negative_integer_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => -1]);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_negative_string_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => '-1']);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_null_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => null]);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_missing_config_key_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs' => []]);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_array_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => [180]]);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_boolean_config_value_follows_the_existing_invalid_value_policy(): void
    {
        config(['integrations.sync_runs.retention_days' => true]);
        $this->assertNull($this->service->categoryFor('sync_runs')['current_default']);
    }

    public function test_every_configured_default_category_with_a_single_scalar_config_key_exposes_a_positive_integer_current_default(): void
    {
        $checked = 0;

        foreach ($this->service->categories() as $category => $entry) {
            if ($entry['status'] !== RetentionGovernanceRegistryService::STATUS_CONFIGURED_DEFAULT) {
                continue;
            }

            if ($entry['config_key'] === null || str_contains($entry['config_key'], ' / ') || str_contains($entry['config_key'], ' (')) {
                continue;
            }

            $this->assertIsInt($entry['current_default'], "{$category}'s current_default must be a real PHP int.");
            $this->assertGreaterThan(0, $entry['current_default'], "{$category}'s current_default must be positive.");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'Expected at least one STATUS_CONFIGURED_DEFAULT category with a single-scalar config_key.');
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
                'categoriesWithUnresolvedLegalHoldCoverage() must be exactly the set of categories individually flagged true.'
            );
        }
    }

    // ------------------------------------------------------------
    // Structural parallel to RowLevelSecurityCoverageMappingService,
    // and no dual-source-of-truth (no RetentionPolicy row seeded)
    // ------------------------------------------------------------

    public function test_no_retentionpolicy_row_is_seeded_for_any_integration_category(): void
    {
        if (! Schema::hasTable('retention_policies')) {
            $this->markTestSkipped('retention_policies table does not exist in this schema.');
        }

        $count = DB::table('retention_policies')
            ->where('record_type', 'like', 'integration_%')
            ->count();

        $this->assertSame(0, $count, 'This checkpoint must never seed a real RetentionPolicy row for an integration category — RetentionSweepJob reads config() directly, never RetentionPolicy, and a seeded row would create a dual-source-of-truth defect.');
    }
}
