<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the exact 6 approved Phase 11 tables exist, that the 4
 * explicitly-rejected extra tables were never created, that Phase 11's
 * own migration files never Schema::table() an existing table from
 * Phases 1-10, and that no new module_catalog migration was added
 * (annotations reuse the existing e_signature entitlement's
 * settings_json).
 */
class Phase11NoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    private const OWNED_TABLES = [
        'signature_requests', 'signature_request_recipients', 'signature_events',
        'signature_certificates', 'pdf_view_events', 'document_hashes',
    ];

    #[DataProvider('phase11TableProvider')]
    public function test_phase_11_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    public static function phase11TableProvider(): array
    {
        return array_map(fn ($t) => [$t], self::OWNED_TABLES);
    }

    #[DataProvider('rejectedTableProvider')]
    public function test_rejected_table_does_not_exist(string $table): void
    {
        $this->assertFalse(Schema::hasTable($table));
    }

    public static function rejectedTableProvider(): array
    {
        return [
            ['signature_consents'],
            ['pdf_view_sessions'],
            ['pdf_annotation_events'],
            ['signature_field_placements'],
        ];
    }

    public function test_phase_11_migrations_never_alter_an_existing_table_from_phases_1_to_10(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_14_9*.php'));
        $this->assertNotEmpty($migrationFiles, 'Expected Phase 11 migration files to be present.');

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);

            $this->assertMatchesRegularExpression(
                '/Schema::create\(/',
                $source,
                "Phase 11 migration {$file} should only Schema::create() one of its own tables."
            );

            preg_match_all('/Schema::table\(([\'"])([a-z_]+)\1/', $source, $matches);

            foreach ($matches[2] ?? [] as $tableTouchedViaSchemaTable) {
                $this->assertContains(
                    $tableTouchedViaSchemaTable,
                    self::OWNED_TABLES,
                    "Phase 11 migration {$file} must not Schema::table() a table it does not own."
                );
            }
        }
    }

    public function test_no_phase_11_migration_file_targets_a_phase_1_to_10_table_by_filename(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_14_9*.php'));
        $foreignTables = [
            'invoices', 'payment_plans', 'payments', 'manual_payment_records',
            'api_keys', 'import_batches', 'documents', 'email_accounts', 'email_messages',
            'form_drafts', 'generated_documents', 'module_catalog',
        ];

        foreach ($migrationFiles as $file) {
            $filename = basename($file);

            foreach ($foreignTables as $table) {
                $this->assertStringNotContainsString(
                    "create_{$table}_table",
                    $filename,
                    "No Phase 11 migration file should attempt to (re)create the existing table {$table}."
                );
            }
        }
    }

    public function test_no_new_module_catalog_migration_was_added(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_14_9*.php'));

        foreach ($migrationFiles as $file) {
            $this->assertStringNotContainsString('module_catalog', file_get_contents($file));
        }
    }
}
