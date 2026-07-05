<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Phase8NoDuplicateTableTest extends TestCase
{
    #[DataProvider('phase8TableProvider')]
    public function test_phase_8_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    public static function phase8TableProvider(): array
    {
        return [
            ['api_keys'],
            ['api_key_scopes'],
            ['api_requests'],
            ['import_batches'],
            ['import_mappings'],
            ['import_rows'],
            ['import_errors'],
            ['import_audit_events'],
            ['export_jobs'],
            ['export_files'],
            ['migration_projects'],
            ['import_rollback_records'],
        ];
    }

    public function test_phase_8_migrations_do_not_modify_phase_3_billing_tables(): void
    {
        $phase8Migrations = glob(database_path('migrations/2026_07_11_9*.php'));

        $this->assertNotEmpty($phase8Migrations);

        $contents = '';
        foreach ($phase8Migrations as $migration) {
            $contents .= file_get_contents($migration) . PHP_EOL;
        }

        foreach ([
            'invoices',
            'payment_plans',
            'payments',
            'manual_payment_records',
        ] as $table) {
            $this->assertStringNotContainsString("Schema::table('{$table}'", $contents);
            $this->assertStringNotContainsString('Schema::table("' . $table . '"', $contents);
        }
    }

    public function test_phase_8_migration_filenames_do_not_reference_phase_3_billing_tables(): void
    {
        $phase8Migrations = glob(database_path('migrations/2026_07_11_9*.php'));

        foreach ($phase8Migrations as $migration) {
            foreach ([
                'invoices',
                'payment_plans',
                'payments',
                'manual_payment_records',
            ] as $table) {
                $this->assertStringNotContainsString($table, basename($migration));
            }
        }
    }

    public function test_api_keys_is_a_single_table_covering_both_platform_and_firm_keys(): void
    {
        $this->assertTrue(Schema::hasTable('api_keys'));
        $this->assertFalse(Schema::hasTable('platform_api_keys'));
        $this->assertFalse(Schema::hasTable('firm_api_keys'));
        $this->assertTrue(Schema::hasColumn('api_keys', 'key_type'));
    }
}
