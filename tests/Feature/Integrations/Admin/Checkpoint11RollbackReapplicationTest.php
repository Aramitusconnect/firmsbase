<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Models\Firm;
use App\Services\RowLevelSecurityCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Checkpoint11RollbackReapplicationTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §5). Mirrors Checkpoint7RollbackReapplicationTest's
 * established style for the ONE new migration this checkpoint introduces:
 * `2026_09_09_090001_create_integration_platform_overview_summaries_table`.
 * Proves it rolls back cleanly (table dropped, no orphaned FK) and
 * reapplies cleanly, without affecting any of Checkpoints 3-10's tables.
 */
final class Checkpoint11RollbackReapplicationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_09_090001_create_integration_platform_overview_summaries_table';

    public function test_the_migration_exists_on_disk(): void
    {
        $this->assertFileExists(base_path('database/migrations/'.self::MIGRATION.'.php'));
    }

    public function test_the_migration_is_recorded_in_the_migrations_table(): void
    {
        $this->assertNotNull(DB::table('migrations')->where('migration', self::MIGRATION)->first());
    }

    public function test_rolling_back_and_reapplying_the_migration_directly_succeeds_with_no_orphaned_fk(): void
    {
        // Sanity: Checkpoints 3-10 tables exist and are untouched
        // structurally before we do anything.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertTrue(Schema::hasTable('integration_outbox_events'));
        $this->assertTrue(Schema::hasTable('integration_sync_items'));
        $this->assertTrue(Schema::hasTable('support_access_sessions'));
        $preExistingFirmsColumns = Schema::getColumnListing('firms');

        $migration = include base_path('database/migrations/'.self::MIGRATION.'.php');

        $this->assertTrue(Schema::hasTable('integration_platform_overview_summaries'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('integration_platform_overview_summaries'), 'The table must be fully dropped by down().');

        // No orphaned FK left behind on `firms` — its own column set
        // must be byte-for-byte identical before/after.
        $this->assertSame($preExistingFirmsColumns, Schema::getColumnListing('firms'));

        // Checkpoints 3-10 tables completely undamaged.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertTrue(Schema::hasTable('integration_outbox_events'));
        $this->assertTrue(Schema::hasTable('integration_sync_items'));
        $this->assertTrue(Schema::hasTable('support_access_sessions'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('integration_platform_overview_summaries'), 'The table must be cleanly recreated by up().');

        $expectedColumns = [
            'id', 'firm_id', 'firm_uuid', 'connection_count_active', 'connection_count_disconnected',
            'connection_count_other', 'health_summary_state', 'last_sync_outcome', 'last_sync_at',
            'failed_permanent_sync_item_count', 'dead_lettered_outbox_event_count', 'open_conflict_count',
            'entitlement_enabled', 'computed_at', 'created_at', 'updated_at',
        ];
        $actualColumns = Schema::getColumnListing('integration_platform_overview_summaries');

        sort($expectedColumns);
        sort($actualColumns);
        $this->assertSame($expectedColumns, $actualColumns);
    }

    public function test_the_table_still_carries_no_row_level_security_after_reapplication(): void
    {
        $migration = include base_path('database/migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $migration->up();

        $row = DB::selectOne(
            "select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_platform_overview_summaries'"
        );

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity);
        $this->assertFalse((bool) $row->relforcerowsecurity);
    }

    public function test_the_firm_id_foreign_key_cascade_delete_still_works_after_reapplication(): void
    {
        $migration = include base_path('database/migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $migration->up();

        $firm = Firm::factory()->activated()->create();

        DB::table('integration_platform_overview_summaries')->insert([
            'firm_id' => $firm->id,
            'firm_uuid' => $firm->uuid,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('integration_platform_overview_summaries')->where('firm_id', $firm->id)->count());

        $firm->delete();

        $this->assertSame(
            0,
            DB::table('integration_platform_overview_summaries')->where('firm_id', $firm->id)->count(),
            'The summary row must cascade-delete with its parent firm.'
        );
    }

    public function test_the_artisan_migrate_rollback_and_reapply_round_trip_succeeds(): void
    {
        $exit = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--force' => true,
        ]);
        $this->assertSame(0, $exit, 'Rollback failed: '.Artisan::output());
        $this->assertFalse(Schema::hasTable('integration_platform_overview_summaries'));
        $this->assertNull(DB::table('migrations')->where('migration', self::MIGRATION)->first());

        $exit = Artisan::call('migrate', [
            '--path' => 'database/migrations/'.self::MIGRATION.'.php',
            '--force' => true,
        ]);
        $this->assertSame(0, $exit, 'Reapplying failed: '.Artisan::output());
        $this->assertTrue(Schema::hasTable('integration_platform_overview_summaries'));
        $this->assertNotNull(DB::table('migrations')->where('migration', self::MIGRATION)->first());
    }

    public function test_the_rls_coverage_mapping_service_still_lists_the_table_as_exempt_after_reapplication(): void
    {
        $migration = include base_path('database/migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $migration->up();

        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('integration_platform_overview_summaries', $coverage->exemptTables());
        $this->assertNotContains('integration_platform_overview_summaries', $coverage->forcedTables());
    }
}
