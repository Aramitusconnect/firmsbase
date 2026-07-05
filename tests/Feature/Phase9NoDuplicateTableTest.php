<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the exact 7 approved Phase 9 tables exist, that the 4
 * explicitly-rejected tables were never created, and that Phase 9's
 * own migration files never Schema::table() an existing table from
 * Phases 1-8 (learned from the Phase 8 hotfix: this asserts what
 * Phase 9's OWN migrations do, not a claim about the live column set
 * of a table this phase does not own).
 */
class Phase9NoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('phase9TableProvider')]
    public function test_phase_9_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table));
    }

    public static function phase9TableProvider(): array
    {
        return [
            ['email_accounts'],
            ['email_oauth_tokens'],
            ['email_messages'],
            ['email_message_links'],
            ['email_attachments'],
            ['email_sync_events'],
            ['email_visibility_rules'],
        ];
    }

    #[DataProvider('rejectedTableProvider')]
    public function test_rejected_table_does_not_exist(string $table): void
    {
        $this->assertFalse(Schema::hasTable($table));
    }

    public static function rejectedTableProvider(): array
    {
        return [
            ['email_threads'],
            ['email_sync_state'],
            ['email_send_requests'],
            ['email_audit_events'],
        ];
    }

    public function test_phase_9_migrations_never_alter_an_existing_table_from_phases_1_to_8(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_12_9*.php'));
        $this->assertNotEmpty($migrationFiles, 'Expected Phase 9 migration files to be present.');

        $ownedTables = [
            'email_accounts', 'email_oauth_tokens', 'email_messages',
            'email_message_links', 'email_attachments', 'email_sync_events',
            'email_visibility_rules',
        ];

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);

            $this->assertMatchesRegularExpression(
                '/Schema::create\(/',
                $source,
                "Phase 9 migration {$file} should only Schema::create() one of its own tables."
            );

            preg_match_all('/Schema::table\(([\'"])([a-z_]+)\1/', $source, $matches);

            foreach ($matches[2] ?? [] as $tableTouchedViaSchemaTable) {
                $this->assertContains(
                    $tableTouchedViaSchemaTable,
                    $ownedTables,
                    "Phase 9 migration {$file} must not Schema::table() a table it does not own."
                );
            }
        }
    }

    public function test_no_phase_9_migration_file_targets_a_phase_1_to_8_table_by_filename(): void
    {
        $migrationFiles = glob(database_path('migrations/2026_07_12_9*.php'));
        $foreignTables = ['invoices', 'payment_plans', 'payments', 'manual_payment_records', 'api_keys', 'import_batches', 'documents'];

        foreach ($migrationFiles as $file) {
            $filename = basename($file);

            foreach ($foreignTables as $table) {
                $this->assertStringNotContainsString(
                    "create_{$table}_table",
                    $filename,
                    "No Phase 9 migration file should attempt to (re)create the existing table {$table}."
                );
            }
        }
    }
}
