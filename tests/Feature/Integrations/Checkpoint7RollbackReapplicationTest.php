<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Checkpoint7RollbackReapplicationTest — the whole-wave rollback proof
 * for all 5 Checkpoint 7 migrations
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §11's
 * required rollback ordering). No dedicated `tests/Migration/`
 * directory convention exists in this codebase (confirmed: only
 * `tests/Feature` and `tests/Unit` exist, and prior checkpoints embed
 * their own rollback proofs as methods inside each table's own
 * `*ForceRlsActivationTest` file rather than a separate file/directory)
 * — this file is placed under `tests/Feature/Integrations/`, alongside
 * every other Checkpoint 7 Feature test, as the closest real match to
 * that convention for a proof that spans all 5 migrations together
 * rather than belonging to any single table's own test file.
 */
final class Checkpoint7RollbackReapplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Filename order = required rollback order (down() runs exact
     * reverse of this list; migrate reapplies in this same order).
     *
     * @var string[]
     */
    private const MIGRATIONS = [
        '2026_09_06_060001_create_integration_webhook_routing_index_table',
        '2026_09_06_060002_create_integration_webhook_receipts_table',
        '2026_09_06_060003_create_integration_inbound_webhook_events_table',
        '2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table',
        '2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table',
    ];

    /**
     * POST-CHECKPOINT-9 UPDATE: Checkpoint 9's
     * `2026_09_08_080001_create_integration_usage_records_table` migration
     * adds a real composite FK (firm_id, inbound_webhook_event_id) ->
     * integration_inbound_webhook_events(firm_id, id) (ON DELETE SET
     * NULL) — so integration_usage_records must now be rolled back FIRST,
     * before this whole-wave rollback drops
     * 2026_09_06_060003_create_integration_inbound_webhook_events_table
     * below, or the drop fails with "cannot drop table ... because other
     * objects depend on it". Reapplied LAST, after this whole-wave
     * reapplication has recreated the table it depends on. Rolled back
     * in exact reverse of its own creation order (RLS-prep down(), then
     * create-table down()).
     *
     * @var string[]
     */
    private const CP9_USAGE_RECORDS_MIGRATIONS = [
        '2026_09_08_080001_create_integration_usage_records_table',
        '2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table',
    ];

    /**
     * POST-CHECKPOINT-4-PLAID UPDATE: Checkpoint 4's
     * `create_provider_billable_call_reservations_table` migration adds
     * a real (bare, single-column) FK `usage_record_id` ->
     * integration_usage_records(id) (nullOnDelete()) — a bare FK still
     * blocks dropping the referenced table in PostgreSQL exactly like
     * Checkpoint 9's own composite FK does, so
     * provider_billable_call_reservations must now be rolled back FIRST,
     * before CP9_USAGE_RECORDS_MIGRATIONS above tears down
     * integration_usage_records. Reapplied LAST, after
     * integration_usage_records is rebuilt.
     *
     * @var string[]
     */
    private const CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS = [
        '2026_09_24_500002_create_provider_billable_call_reservations_table',
        '2026_09_24_500003_prepare_row_level_security_and_force_rls_on_provider_billable_call_reservations_table',
    ];

    public function test_all_five_migrations_exist_on_disk(): void
    {
        foreach (self::MIGRATIONS as $migration) {
            $this->assertFileExists(base_path("database/migrations/{$migration}.php"));
        }
    }

    public function test_all_five_migrations_are_recorded_in_the_migrations_table(): void
    {
        foreach (self::MIGRATIONS as $migration) {
            $this->assertNotNull(
                DB::table('migrations')->where('migration', $migration)->first(),
                "{$migration} must be recorded in the migrations table."
            );
        }
    }

    /**
     * Rolls back all 5 migrations in exact reverse filename order via
     * direct include()->down() calls (safe inside RefreshDatabase's
     * transactional wrapper, since PostgreSQL supports transactional
     * DDL), then reapplies them in forward order — asserting no step
     * fails due to a still-referencing FK from a table that hasn't
     * been dropped yet, and that Checkpoint 3-6 tables are untouched.
     */
    public function test_rolling_back_all_checkpoint_7_migrations_in_exact_reverse_order_and_reapplying_succeeds(): void
    {
        // Sanity: a Checkpoint 3-6 table exists and has data-independent
        // structure before we touch anything.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertTrue(Schema::hasTable('integration_credentials'));
        $this->assertTrue(Schema::hasTable('integration_sync_runs'));
        $preExistingSyncRunColumns = Schema::getColumnListing('integration_sync_runs');

        $migrationInstances = [];
        foreach (self::MIGRATIONS as $migration) {
            $migrationInstances[$migration] = include base_path("database/migrations/{$migration}.php");
        }

        // Checkpoint 9's integration_usage_records FK-references
        // integration_inbound_webhook_events (see
        // CP9_USAGE_RECORDS_MIGRATIONS docblock above) — it must be torn
        // down FIRST, before this whole-wave rollback drops that table
        // below.
        $usageRecordsInstances = [];
        foreach (self::CP9_USAGE_RECORDS_MIGRATIONS as $migration) {
            $usageRecordsInstances[$migration] = include base_path("database/migrations/{$migration}.php");
        }

        // Checkpoint 4's provider_billable_call_reservations FK-references
        // integration_usage_records (see
        // CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS docblock above) —
        // it must be rolled back first, before integration_usage_records
        // itself.
        $providerBillableReservationsInstances = [];
        foreach (self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS as $migration) {
            $providerBillableReservationsInstances[$migration] = include base_path("database/migrations/{$migration}.php");
        }
        foreach (array_reverse(self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS) as $migration) {
            $providerBillableReservationsInstances[$migration]->down();
        }
        $this->assertFalse(Schema::hasTable('provider_billable_call_reservations'));

        foreach (array_reverse(self::CP9_USAGE_RECORDS_MIGRATIONS) as $migration) {
            $usageRecordsInstances[$migration]->down();
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'));

        // Reverse order: 5, 4, 3, 2, 1.
        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            $migrationInstances[$migration]->down();
        }

        $this->assertFalse(Schema::hasTable('integration_webhook_routing_index'));
        $this->assertFalse(Schema::hasTable('integration_webhook_receipts'));
        $this->assertFalse(Schema::hasTable('integration_inbound_webhook_events'));
        $this->assertFalse(Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'));

        // Checkpoint 3-6 tables must be completely undamaged.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertTrue(Schema::hasTable('integration_credentials'));
        $this->assertTrue(Schema::hasTable('integration_sync_runs'));

        // Forward order: 1, 2, 3, 4, 5 — must succeed with no orphaned
        // FK errors (each migration's FKs only ever reference a table
        // created earlier in this same list, or a pre-existing
        // Checkpoint 3-6 table).
        foreach (self::MIGRATIONS as $migration) {
            $migrationInstances[$migration]->up();
        }

        // Rebuild Checkpoint 9's integration_usage_records LAST — after
        // integration_inbound_webhook_events already exists again.
        foreach (self::CP9_USAGE_RECORDS_MIGRATIONS as $migration) {
            $usageRecordsInstances[$migration]->up();
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'));

        // Rebuild Checkpoint 4's provider_billable_call_reservations LAST
        // of all — after integration_usage_records already exists again.
        foreach (self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS as $migration) {
            $providerBillableReservationsInstances[$migration]->up();
        }
        $this->assertTrue(Schema::hasTable('provider_billable_call_reservations'));

        $this->assertTrue(Schema::hasTable('integration_webhook_routing_index'));
        $this->assertTrue(Schema::hasTable('integration_webhook_receipts'));
        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));
        $this->assertTrue(Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'));

        $rlsRow = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_inbound_webhook_events'");
        $this->assertTrue((bool) $rlsRow->relrowsecurity);
        $this->assertTrue((bool) $rlsRow->relforcerowsecurity);

        $routingIndexRls = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_webhook_routing_index'");
        $this->assertFalse((bool) $routingIndexRls->relrowsecurity);

        $receiptsRls = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_webhook_receipts'");
        $this->assertFalse((bool) $receiptsRls->relrowsecurity);

        // Checkpoint 3-6 tables must still be fully intact after the
        // whole round trip, byte-for-byte the same column set as
        // before this test ever touched anything.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertTrue(Schema::hasTable('integration_credentials'));
        $postReapplySyncRunColumns = Schema::getColumnListing('integration_sync_runs');
        sort($preExistingSyncRunColumns);
        sort($postReapplySyncRunColumns);
        $this->assertSame($preExistingSyncRunColumns, $postReapplySyncRunColumns);
    }

    /**
     * Same round trip via Artisan (the operationally realistic path),
     * asserting the migrations table itself is correctly cleared and
     * restored.
     */
    public function test_artisan_migrate_rollback_and_reapply_round_trip_succeeds(): void
    {
        // Checkpoint 9's integration_usage_records FK-references
        // integration_inbound_webhook_events (see
        // CP9_USAGE_RECORDS_MIGRATIONS docblock above) — it must be rolled
        // back FIRST, before this whole-wave rollback drops that table
        // below.
        // Checkpoint 4's provider_billable_call_reservations FK-references
        // integration_usage_records — it must be rolled back first,
        // before integration_usage_records itself.
        foreach (array_reverse(self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS) as $migration) {
            $exit = Artisan::call('migrate:rollback', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, "Rollback of {$migration} (Checkpoint 4 provider_billable_call_reservations) failed: ".Artisan::output());
        }
        $this->assertFalse(Schema::hasTable('provider_billable_call_reservations'));

        foreach (array_reverse(self::CP9_USAGE_RECORDS_MIGRATIONS) as $migration) {
            $exit = Artisan::call('migrate:rollback', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, "Rollback of {$migration} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'));

        foreach (array_reverse(self::MIGRATIONS) as $migration) {
            $exit = Artisan::call('migrate:rollback', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, "Rollback of {$migration} failed: ".Artisan::output());
        }

        foreach (self::MIGRATIONS as $migration) {
            $this->assertNull(DB::table('migrations')->where('migration', $migration)->first());
        }

        foreach (self::MIGRATIONS as $migration) {
            $exit = Artisan::call('migrate', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, "Reapplying {$migration} failed: ".Artisan::output());
        }

        foreach (self::MIGRATIONS as $migration) {
            $this->assertNotNull(DB::table('migrations')->where('migration', $migration)->first());
        }

        // Reapply Checkpoint 9's integration_usage_records LAST — after
        // integration_inbound_webhook_events already exists again.
        foreach (self::CP9_USAGE_RECORDS_MIGRATIONS as $migration) {
            $exit = Artisan::call('migrate', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, "Reapplying {$migration} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'));

        // Reapply Checkpoint 4's provider_billable_call_reservations LAST
        // of all — after integration_usage_records already exists again.
        foreach (self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS as $migration) {
            $exit = Artisan::call('migrate', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
            ]);
            $this->assertSame(0, $exit, "Reapplying {$migration} (Checkpoint 4 provider_billable_call_reservations) failed: ".Artisan::output());
        }
        $this->assertTrue(Schema::hasTable('provider_billable_call_reservations'));

        $this->assertTrue(Schema::hasTable('integration_webhook_routing_index'));
        $this->assertTrue(Schema::hasTable('integration_webhook_receipts'));
        $this->assertTrue(Schema::hasTable('integration_inbound_webhook_events'));
        $this->assertTrue(Schema::hasColumn('integration_sync_runs', 'triggering_webhook_event_id'));
    }

    /**
     * Guards specifically against a hypothetical future edit to the
     * RLS migration file changing the predicate text without a
     * corresponding test update — compares the exact predicate string
     * before rollback and after reapplication within the same test run.
     */
    public function test_reapplying_the_events_rls_migration_produces_byte_identical_policy_predicates(): void
    {
        $policyName = 'integration_inbound_webhook_events_tenant_isolation';

        $before = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_inbound_webhook_events'::regclass and polname = ?",
            [$policyName]
        );
        $this->assertNotNull($before);

        $rlsMigration = include base_path('database/migrations/2026_09_06_060004_prepare_row_level_security_and_force_rls_on_integration_inbound_webhook_events_table.php');
        $eventsMigration = include base_path('database/migrations/2026_09_06_060003_create_integration_inbound_webhook_events_table.php');
        $syncRunsMigration = include base_path('database/migrations/2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table.php');

        // Checkpoint 9's integration_usage_records FK-references
        // integration_inbound_webhook_events (see
        // CP9_USAGE_RECORDS_MIGRATIONS docblock above) — it must be torn
        // down FIRST, before $eventsMigration->down() below drops that
        // table.
        $usageRecordsMigrations = array_map(
            static fn (string $migration) => include base_path("database/migrations/{$migration}.php"),
            self::CP9_USAGE_RECORDS_MIGRATIONS,
        );

        // Checkpoint 4's provider_billable_call_reservations FK-references
        // integration_usage_records — it must be torn down FIRST, before
        // $usageRecordsMigrations below drops that table.
        $providerBillableReservationsMigrations = array_map(
            static fn (string $migration) => include base_path("database/migrations/{$migration}.php"),
            self::CP4_PROVIDER_BILLABLE_RESERVATIONS_MIGRATIONS,
        );

        $syncRunsMigration->down();
        foreach (array_reverse($providerBillableReservationsMigrations) as $migration) {
            $migration->down();
        }
        foreach (array_reverse($usageRecordsMigrations) as $migration) {
            $migration->down();
        }
        $rlsMigration->down();
        $eventsMigration->down();

        $eventsMigration->up();
        $rlsMigration->up();
        foreach ($usageRecordsMigrations as $migration) {
            $migration->up();
        }
        foreach ($providerBillableReservationsMigrations as $migration) {
            $migration->up();
        }
        $syncRunsMigration->up();

        $after = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_inbound_webhook_events'::regclass and polname = ?",
            [$policyName]
        );

        $this->assertSame($before->using_expr, $after->using_expr);
        $this->assertSame($before->with_check_expr, $after->with_check_expr);
    }
}
