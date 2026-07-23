<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationOutboxEventsForceRlsActivationTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §6/§7/§5;
 * agent-6h-test-plan-and-review.md §6 items 1-5, 14). This is the LAST
 * migration of the Checkpoint 6 date block — PREPARED_TABLES goes
 * 116 -> 122 once all six tables are registered (see
 * RowLevelSecurityCoverageMappingServiceTest.php).
 */
class IntegrationOutboxEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_05_055001_create_integration_outbox_events_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_05_055001_create_integration_outbox_events_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table';

    /**
     * POST-CHECKPOINT-9 UPDATE (part 1): Checkpoint 9's
     * `2026_09_08_081001_add_requeue_columns_to_integration_outbox_events_and_integration_sync_items_table`
     * migration ALTERs this table directly (adds requeue_count/
     * requeued_at/max_requeues plus a supporting index), positioned
     * chronologically AFTER this table's own 2 migrations. It must be
     * rolled back FIRST (before this table's own migrations below), or
     * the requeue columns would be silently, permanently lost on
     * reapply — the migrations table would still record it as "ran"
     * even though the live columns would be gone, exactly the same bug
     * class `integration_sync_runs.triggering_webhook_event_id`
     * (migration 060005) already guards against in
     * IntegrationSyncRunsForceRlsActivationTest. Reapplied LAST, after
     * this table's own migrations are restored.
     */
    private const REQUEUE_COLUMNS_MIGRATION_PATH = 'database/migrations/2026_09_08_081001_add_requeue_columns_to_integration_outbox_events_and_integration_sync_items_table.php';

    /**
     * POST-CHECKPOINT-9 UPDATE (part 2): Checkpoint 9's
     * `2026_09_08_080001_create_integration_usage_records_table` migration
     * adds a real (bare, single-column) FK `outbox_event_id` ->
     * integration_outbox_events(id) (nullOnDelete()) — a bare FK still
     * blocks dropping the referenced table in PostgreSQL exactly like a
     * composite one does, so integration_usage_records must now ALSO be
     * rolled back before this table's own migrations, or dropping this
     * table fails with "cannot drop table ... because other objects
     * depend on it". Reapplied LAST, after this table's own migrations
     * are restored (and after REQUEUE_COLUMNS_MIGRATION_PATH above,
     * chronologically the older of the two).
     *
     * @var list<string>
     */
    private const CP9_USAGE_RECORDS_MIGRATION_PATHS = [
        'database/migrations/2026_09_08_080001_create_integration_usage_records_table.php',
        'database/migrations/2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php',
    ];

    private const POLICY_NAME = 'integration_outbox_events_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'firm_integration_id', 'domain_event_id', 'event_type', 'resource_type',
        'resource_id', 'payload_json', 'payload_hash', 'status', 'lock_token', 'locked_at', 'attempts',
        'max_attempts', 'next_attempt_at', 'last_error', 'completed_at', 'dead_lettered_at', 'cancelled_at',
        'requeue_count', 'requeued_at', 'max_requeues',
        'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_outbox_events'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_outbox_events');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists_and_is_nullable(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_outbox_events'::regclass and contype = 'f' and conname = 'integration_outbox_events_firm_integration_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('firm_integrations', $row->foreign_table);
    }

    public function test_firm_integration_id_can_be_null(): void
    {
        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('integration_outbox_events')->insert(array_merge(
                $this->rawRowAttributes($firm),
                ['firm_integration_id' => null],
            ));
        });

        $this->assertTrue($affected, 'Not every internal async event is tied to a specific provider connection.');
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB) {
            DB::table('integration_outbox_events')->insert(array_merge(
                $this->rawRowAttributes($firmA),
                ['firm_integration_id' => $connectionB->id],
            ));
        });
    }

    public function test_domain_event_id_is_a_native_uuid_column(): void
    {
        $row = DB::selectOne(
            "select data_type from information_schema.columns where table_name = 'integration_outbox_events' and column_name = 'domain_event_id'"
        );

        $this->assertNotNull($row);
        $this->assertSame('uuid', $row->data_type);
    }

    public function test_duplicate_domain_event_id_for_the_same_firm_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $domainEventId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $domainEventId) {
            DB::table('integration_outbox_events')->insert(array_merge(
                $this->rawRowAttributes($firm),
                ['domain_event_id' => $domainEventId],
            ));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $domainEventId) {
            DB::table('integration_outbox_events')->insert(array_merge(
                $this->rawRowAttributes($firm),
                ['domain_event_id' => $domainEventId],
            ));
        });
    }

    public function test_processing_lock_consistency_check_rejects_processing_status_without_a_lock_token(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint|violates check/i');

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('integration_outbox_events')->insert(array_merge(
                $this->rawRowAttributes($firm),
                ['status' => 'processing', 'lock_token' => null, 'locked_at' => null],
            ));
        });
    }

    public function test_processing_lock_consistency_check_accepts_processing_status_with_both_lock_token_and_locked_at(): void
    {
        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('integration_outbox_events')->insert(array_merge(
                $this->rawRowAttributes($firm),
                ['status' => 'processing', 'lock_token' => (string) Str::uuid(), 'locked_at' => now()],
            ));
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_outbox_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_outbox_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_outbox_events'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_outbox_events'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    // ------------------------------------------------------------
    // 3. Cross-firm tenant isolation
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read(): void
    {
        IntegrationOutboxEvent::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_outbox_events')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_outbox_events')->insert($this->rawRowAttributes($firm));
    }

    public function test_firm_a_context_can_read_its_own_event(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->pluck('id')->all());

        $this->assertSame([$event->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();
        $eventB = IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_outbox_events')->pluck('id')->all());

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_an_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('integration_outbox_events')->insert($this->rawRowAttributes($firmB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_outbox_events')->where('id', $eventB->id)->update(['status' => 'cancelled']),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_outbox_events')->where('id', $eventB->id)->value('status'));
        $this->assertSame('pending', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_outbox_events')->where('id', $eventB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_outbox_events')->where('id', $eventB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($event, $firmB) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new RuntimeException('simulated failure inside firm context');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    // ------------------------------------------------------------
    // 4. Migration rollback and reapplication
    // ------------------------------------------------------------

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));
        $this->assertTrue(Schema::hasTable('integration_outbox_events'));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_outbox_events'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_outbox_events'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        // Checkpoint 9's requeue-columns migration ALTERs this table
        // directly and must be undone first (see
        // REQUEUE_COLUMNS_MIGRATION_PATH docblock above).
        $requeueRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::REQUEUE_COLUMNS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $requeueRollbackExit, 'migrate:rollback of the Checkpoint 9 requeue-columns migration failed: '.Artisan::output());

        // Checkpoint 9's integration_usage_records FK-references this
        // table (see CP9_USAGE_RECORDS_MIGRATION_PATHS docblock above) —
        // it must be rolled back next, before this table's own migrations
        // below.
        foreach (array_reverse(self::CP9_USAGE_RECORDS_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'), 'integration_usage_records must not survive its own rollback.');

        $tableRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableRollbackExit, Artisan::output());
        $this->assertFalse(Schema::hasTable('integration_outbox_events'));

        $tableMigrateExit = Artisan::call('migrate', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableMigrateExit, Artisan::output());
        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        // Reapply Checkpoint 9's requeue-columns migration and
        // integration_usage_records LAST — after this table already
        // exists again — in forward (creation) order.
        $requeueMigrateExit = Artisan::call('migrate', ['--path' => self::REQUEUE_COLUMNS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $requeueMigrateExit, Artisan::output());

        foreach (self::CP9_USAGE_RECORDS_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'), 'integration_usage_records must be restored by its own reapplication.');

        $this->assertTrue(Schema::hasTable('integration_outbox_events'));

        $columns = Schema::getColumnListing('integration_outbox_events');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_outbox_events'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_outbox_events'");
        $this->assertCount(1, $policiesAfterReapply);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        // Checkpoint 9's requeue-columns migration ALTERs this table
        // directly (see REQUEUE_COLUMNS_MIGRATION_PATH docblock above) and
        // integration_usage_records FK-references it (see
        // CP9_USAGE_RECORDS_MIGRATION_PATHS docblock above) — both must
        // be torn down FIRST, before this table's own migrations below.
        $requeueMigration = include base_path(self::REQUEUE_COLUMNS_MIGRATION_PATH);
        $usageRecordsMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP9_USAGE_RECORDS_MIGRATION_PATHS,
        );

        $requeueMigration->down();
        foreach (array_reverse($usageRecordsMigrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'));

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_outbox_events'));

        $tableMigration->up();
        $rlsMigration->up();

        // Rebuild Checkpoint 9's requeue-columns migration and
        // integration_usage_records LAST — after this table already
        // exists again.
        $requeueMigration->up();
        foreach ($usageRecordsMigrations as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'));

        $this->assertTrue(Schema::hasTable('integration_outbox_events'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_outbox_events'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Registry integration (this is the LAST migration of the batch)
    // ------------------------------------------------------------

    public function test_prepared_tables_registry_includes_all_six_checkpoint_6_tables(): void
    {
        $service = new \App\Services\RowLevelSecurityCoverageMappingService();

        foreach ([
            'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings',
            'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events',
        ] as $table) {
            $this->assertTrue($service->isPrepared($table), "{$table} must be registered in PREPARED_TABLES.");
            $this->assertTrue($service->isForced($table), "{$table} must be registered as forced.");
        }
    }

    // ------------------------------------------------------------
    // 6. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_outbox_events', (new IntegrationOutboxEvent())->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationOutboxEvent::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $events = IntegrationOutboxEvent::factory()->count(3)->create();

        $this->assertSame(3, $events->pluck('id')->unique()->count());
        $this->assertSame(3, $events->pluck('domain_event_id')->unique()->count());
        foreach ($events as $event) {
            $this->assertNotNull($event->firm_id);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm): array
    {
        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => null,
            'domain_event_id' => (string) Str::uuid(),
            'event_type' => 'token_refresh_retry',
            'payload_json' => json_encode(['resource_type' => null, 'resource_id' => null, 'fields' => []]),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 10,
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
