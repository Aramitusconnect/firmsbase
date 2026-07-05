<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Required: no extra tables beyond the 9 approved Phase 12 tables.
 * Also confirms the one approved existing-table alteration
 * (invoice_lines.expense_id) is present, and that no new
 * module_catalog migration was introduced (correction #6).
 */
class Phase12NoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    private const OWNED_TABLES = [
        'expenses', 'expense_categories', 'expense_receipts', 'expense_approvals',
        'chart_of_accounts', 'matter_expenses', 'accounting_export_batches',
        'accounting_export_lines', 'accounting_export_errors',
    ];

    #[DataProvider('phase12TableProvider')]
    public function test_phase_12_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    public static function phase12TableProvider(): array
    {
        return array_map(fn ($t) => [$t], self::OWNED_TABLES);
    }

    public function test_invoice_lines_gains_only_the_one_approved_expense_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('invoice_lines', 'expense_id'));
    }

    public function test_phase_12_migrations_never_create_a_rejected_table(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_16_9*.php'));
        $this->assertNotEmpty($migrationFiles, 'Expected Phase 12 migration files to be present.');

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
    }

    public function test_no_phase_12_migration_touches_a_table_other_than_invoice_lines_via_schema_table(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_16_9*.php'));

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);
            preg_match_all('/Schema::table\([\'"]([a-z_]+)[\'"]/', $source, $matches);

            foreach ($matches[1] ?? [] as $tableTouched) {
                $this->assertSame(
                    'invoice_lines',
                    $tableTouched,
                    basename($file).' uses Schema::table() on an unapproved existing table.'
                );
            }
        }
    }

    public function test_no_new_module_catalog_migration_was_added(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_16_9*.php'));

        foreach ($migrationFiles as $file) {
            $this->assertStringNotContainsString('module_catalog', file_get_contents($file));
        }
    }
}
