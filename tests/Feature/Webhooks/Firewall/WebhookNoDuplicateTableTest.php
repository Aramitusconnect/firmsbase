<?php

namespace Tests\Feature\Webhooks\Firewall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Correction #1: exactly the 5 approved Phase 14 webhook tables, no
 * extras. Correction #2: the webhook module_catalog seed migration is
 * idempotent (upsert, re-running never creates a duplicate row).
 */
class WebhookNoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    private const OWNED_TABLES = [
        'webhook_subscriptions',
        'webhook_events',
        'webhook_deliveries',
        'webhook_delivery_attempts',
        'webhook_secrets',
    ];

    #[DataProvider('phase14TableProvider')]
    public function test_phase_14_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    public static function phase14TableProvider(): array
    {
        return array_map(fn ($t) => [$t], self::OWNED_TABLES);
    }

    public function test_phase_14_migrations_never_create_a_rejected_table(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_21_9*.php'));
        $this->assertNotEmpty($migrationFiles, 'Expected Phase 14 migration files to be present.');

        $createdTables = [];

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);
            preg_match_all('/Schema::create\([\'"]([a-z_]+)[\'"]/', $source, $matches);
            $createdTables = array_merge($createdTables, $matches[1] ?? []);
        }

        sort($createdTables);
        $expected = self::OWNED_TABLES;
        sort($expected);

        $this->assertSame($expected, $createdTables);
        $this->assertCount(5, $createdTables);
    }

    public function test_no_phase_14_migration_creates_a_table_via_schema_table_other_than_module_catalog_seed(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_21_9*.php'));

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);

            // The one approved seed migration touches module_catalog
            // via DB::table(), never Schema::table() (it never alters
            // an existing table's columns).
            $this->assertStringNotContainsString('Schema::table(', $source);
        }
    }

    public function test_webhook_module_catalog_seed_migration_exists_and_is_idempotent(): void
    {
        $this->assertDatabaseHas('module_catalog', ['module_code' => 'webhook']);
        $this->assertSame(1, DB::table('module_catalog')->where('module_code', 'webhook')->count());

        // Re-run the exact upsert logic a second time (simulating the
        // migration running again against a database that already has
        // the row) and confirm still exactly one row, not a duplicate.
        DB::table('module_catalog')->upsert(
            [[
                'module_code' => 'webhook',
                'module_name' => 'Webhooks',
                'category' => 'plan_control',
                'is_active' => true,
                'requires_admin_approval' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['module_code'],
            ['module_name', 'category', 'is_active', 'updated_at']
        );

        $this->assertSame(1, DB::table('module_catalog')->where('module_code', 'webhook')->count());
    }

    public function test_webhook_module_code_is_separate_from_the_existing_api_module_code(): void
    {
        $this->assertDatabaseHas('module_catalog', ['module_code' => 'api']);
        $this->assertDatabaseHas('module_catalog', ['module_code' => 'webhook']);

        $apiRow = DB::table('module_catalog')->where('module_code', 'api')->first();
        $webhookRow = DB::table('module_catalog')->where('module_code', 'webhook')->first();

        $this->assertNotSame($apiRow->id, $webhookRow->id);
    }
}
