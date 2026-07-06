<?php

namespace Tests\Feature\Ai\Firewall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms exactly the 8 approved Phase 15 tables exist — no extra AI
 * table was introduced, and no second AI data contract was created
 * alongside it.
 */
class Phase15ExactTablesTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_TABLES = [
        'firm_ai_settings',
        'firm_ai_provider_keys',
        'ai_usage_events',
        'ai_retrieval_indexes',
        'ai_approval_requests',
        'ai_approval_events',
        'ai_tool_actions',
        'ai_policy_settings',
    ];

    public function test_all_eight_phase_15_tables_exist(): void
    {
        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table {$table} to exist.");
        }
    }

    public function test_no_extra_ai_or_provider_key_table_exists(): void
    {
        $allTables = collect(Schema::getTables())->pluck('name')->all();

        $unexpectedAiTables = collect($allTables)
            ->filter(fn (string $name) => str_starts_with($name, 'ai_') || str_starts_with($name, 'firm_ai_'))
            ->reject(fn (string $name) => in_array($name, self::EXPECTED_TABLES, true))
            ->values()
            ->all();

        $this->assertEmpty($unexpectedAiTables, 'Unexpected AI table(s) found: '.implode(', ', $unexpectedAiTables));
    }

    public function test_ai_policy_settings_has_no_firm_id_column(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_policy_settings', 'firm_id'));
    }

    public function test_module_catalog_has_exactly_one_ai_entry(): void
    {
        $count = \Illuminate\Support\Facades\DB::table('module_catalog')->where('module_code', 'ai')->count();

        $this->assertSame(1, $count);
    }
}
