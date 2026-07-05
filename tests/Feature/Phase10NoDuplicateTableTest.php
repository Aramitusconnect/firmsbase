<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the exact 14 approved Phase 10 tables exist, that Phase 10's
 * own migration files never Schema::table() an existing table from
 * Phases 1-9, and that no Phase 10 migration file attempts to recreate
 * a table owned by an earlier phase (learned from the Phase 8 hotfix:
 * this asserts what Phase 10's OWN migrations do, never a claim about
 * the live column set of a table this phase does not own). Also
 * confirms no new module_catalog migration was added — Phase 10 reuses
 * the already-seeded 'forms' and 'document_generation' codes from
 * Phase 6.
 */
class Phase10NoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    private const OWNED_TABLES = [
        'form_templates', 'form_template_versions', 'form_fields', 'form_mapping_rules',
        'form_drafts', 'form_draft_values', 'form_review_events', 'form_missing_data_items',
        'form_review_checklist_items', 'form_edition_watch_items',
        'document_templates', 'document_template_versions', 'generated_documents', 'generated_document_events',
    ];

    #[DataProvider('phase10TableProvider')]
    public function test_phase_10_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    public static function phase10TableProvider(): array
    {
        return array_map(fn ($t) => [$t], self::OWNED_TABLES);
    }

    public function test_phase_10_migrations_never_alter_an_existing_table_from_phases_1_to_9(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_13_9*.php'));
        $this->assertNotEmpty($migrationFiles, 'Expected Phase 10 migration files to be present.');

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);

            $this->assertMatchesRegularExpression(
                '/Schema::create\(/',
                $source,
                "Phase 10 migration {$file} should only Schema::create() one of its own tables."
            );

            preg_match_all('/Schema::table\(([\'"])([a-z_]+)\1/', $source, $matches);

            foreach ($matches[2] ?? [] as $tableTouchedViaSchemaTable) {
                $this->assertContains(
                    $tableTouchedViaSchemaTable,
                    self::OWNED_TABLES,
                    "Phase 10 migration {$file} must not Schema::table() a table it does not own."
                );
            }
        }
    }

    public function test_no_phase_10_migration_file_targets_a_phase_1_to_9_table_by_filename(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_13_9*.php'));
        $foreignTables = [
            'invoices', 'payment_plans', 'payments', 'manual_payment_records',
            'api_keys', 'import_batches', 'documents', 'email_accounts', 'email_messages',
            'module_catalog',
        ];

        foreach ($migrationFiles as $file) {
            $filename = basename($file);

            foreach ($foreignTables as $table) {
                $this->assertStringNotContainsString(
                    "create_{$table}_table",
                    $filename,
                    "No Phase 10 migration file should attempt to (re)create the existing table {$table}."
                );
            }
        }
    }

    public function test_no_new_module_catalog_migration_was_added(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_13_9*.php'));

        foreach ($migrationFiles as $file) {
            $this->assertStringNotContainsString('module_catalog', file_get_contents($file));
        }
    }
}
