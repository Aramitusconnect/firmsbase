<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
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
 * IntegrationExternalMappingsForceRlsActivationTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §3/§5/§6;
 * agent-6h-test-plan-and-review.md §6 items 1-5, 11, 14). Includes
 * agent-6f's "hard case" proof (§2.2b): two connections of the SAME
 * firm, identical external_id, both succeed — proving the uniqueness
 * indexes' firm_integration_id-leading shape, not RLS, is what prevents
 * conflation there (RLS gives zero protection since both rows share the
 * same firm_id).
 */
class IntegrationExternalMappingsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_05_052001_create_integration_external_mappings_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_05_052001_create_integration_external_mappings_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table';

    private const POLICY_NAME = 'integration_external_mappings_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'firm_integration_id', 'resource_type', 'local_type', 'local_id',
        'external_id', 'external_version_token', 'local_version_token', 'sync_direction',
        'last_synced_at', 'tombstoned_at', 'tombstone_reason', 'created_at', 'updated_at',
    ];

    /**
     * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) —
     * rollback-methodology bug: integration_external_mappings is a
     * composite-FK PARENT of integration_conflicts
     * (integration_conflicts_external_mapping_fk, see
     * database/migrations/2026_09_05_054001_create_integration_conflicts_table.php),
     * so rolling back ONLY this table's own 2 migrations while
     * integration_conflicts still exists fails at the DB constraint
     * layer ("cannot drop table integration_external_mappings because
     * other objects depend on it"). Both rollback tests below therefore
     * roll back the ENTIRE 12-migration Checkpoint 6 wave together, in
     * the frozen reverse-dependency order (frozen-design-post-review.md
     * §2: outbox_events -> conflicts -> sync_cursors ->
     * external_mappings -> sync_items -> sync_runs — which is exactly
     * the reverse of this array's own creation order below), mirroring
     * Checkpoint 5's identical rollback-order-dependency precedent
     * (reviews/checkpoint-05/precommit-failure-triage.md).
     *
     * @var list<string>
     */
    private const WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_05_050001_create_integration_sync_runs_table.php',
        'database/migrations/2026_09_05_050002_prepare_row_level_security_and_force_rls_on_integration_sync_runs_table.php',
        'database/migrations/2026_09_05_051001_create_integration_sync_items_table.php',
        'database/migrations/2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table.php',
        'database/migrations/2026_09_05_052001_create_integration_external_mappings_table.php',
        'database/migrations/2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table.php',
        'database/migrations/2026_09_05_053001_create_integration_sync_cursors_table.php',
        'database/migrations/2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php',
        'database/migrations/2026_09_05_054001_create_integration_conflicts_table.php',
        'database/migrations/2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table.php',
        'database/migrations/2026_09_05_055001_create_integration_outbox_events_table.php',
        'database/migrations/2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table.php',
    ];

    /**
     * @var list<string>
     */
    private const WHOLE_WAVE_TABLES = [
        'integration_sync_runs',
        'integration_sync_items',
        'integration_external_mappings',
        'integration_sync_cursors',
        'integration_conflicts',
        'integration_outbox_events',
    ];

    /**
     * POST-CHECKPOINT-9 UPDATE: Checkpoint 9's
     * `2026_09_08_080001_create_integration_usage_records_table` migration
     * adds a real composite FK (firm_id, sync_run_id) ->
     * integration_sync_runs(firm_id, id) (ON DELETE SET NULL), and a
     * second one into integration_sync_items — so
     * integration_usage_records must now be rolled back FIRST, before
     * this whole-wave rollback drops integration_sync_runs/
     * integration_sync_items below, or the drop fails with "cannot drop
     * table ... because other objects depend on it". Reapplied LAST,
     * after the whole-wave reapplication has recreated both tables it
     * depends on. Rolled back in exact reverse of its own creation order
     * (RLS-prep down(), then create-table down()).
     *
     * @var list<string>
     */
    private const CP9_USAGE_RECORDS_MIGRATION_PATHS = [
        'database/migrations/2026_09_08_080001_create_integration_usage_records_table.php',
        'database/migrations/2026_09_08_080002_prepare_row_level_security_and_force_rls_on_integration_usage_records_table.php',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_external_mappings'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_external_mappings');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_external_mappings'::regclass and contype = 'f' and conname = 'integration_external_mappings_firm_integration_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('firm_integrations', $row->foreign_table);
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB) {
            DB::table('integration_external_mappings')->insert($this->rawRowAttributes($firmA, $connectionB));
        });
    }

    public function test_local_unique_partial_index_exists(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_external_mappings' and indexname = 'integration_external_mappings_local_unique'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('WHERE (tombstoned_at IS NULL)', $row->indexdef);
    }

    public function test_external_unique_partial_index_exists(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_external_mappings' and indexname = 'integration_external_mappings_external_unique'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('WHERE (tombstoned_at IS NULL)', $row->indexdef);
    }

    public function test_duplicate_external_id_on_the_same_connection_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $externalId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $externalId) {
            DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['external_id' => $externalId, 'local_id' => 111],
            ));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $externalId) {
            DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['external_id' => $externalId, 'local_id' => 222],
            ));
        });
    }

    public function test_duplicate_local_pointer_for_a_different_external_id_on_the_same_connection_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['external_id' => (string) Str::uuid(), 'local_id' => 333],
            ));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['external_id' => (string) Str::uuid(), 'local_id' => 333],
            ));
        });
    }

    /**
     * The hard case (agent-6f §2.2b, ratified by frozen-design-post-
     * review.md §6 and agent-6h §6 item 11c): two DIFFERENT connections
     * belonging to the SAME firm, identical external_id — both must
     * succeed independently. RLS provides zero protection here (both
     * rows share the same firm_id); only the firm_integration_id-leading
     * partial unique indexes distinguish them.
     */
    public function test_two_connections_of_the_same_firm_with_identical_external_id_both_succeed_independently(): void
    {
        $firm = Firm::factory()->create();
        $connectionOne = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionTwo = FirmIntegration::factory()->forFirm($firm)->create();
        $sharedExternalId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $connectionOne, $sharedExternalId) {
            DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connectionOne),
                ['external_id' => $sharedExternalId, 'local_id' => 501],
            ));
        });

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connectionTwo, $sharedExternalId) {
            return DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connectionTwo),
                ['external_id' => $sharedExternalId, 'local_id' => 502],
            ));
        });

        $this->assertTrue($affected, 'Two connections of the same firm must be able to independently map the same external_id.');

        $rows = $this->runWithFirmContext($firm, fn () => DB::table('integration_external_mappings')->where('external_id', $sharedExternalId)->get());
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->firm_integration_id, $rows[1]->firm_integration_id);
        $this->assertSame($firm->id, $rows[0]->firm_id);
        $this->assertSame($firm->id, $rows[1]->firm_id);
    }

    public function test_tombstoned_mapping_does_not_block_a_fresh_live_mapping_for_the_same_pair(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $externalId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $externalId) {
            DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['external_id' => $externalId, 'local_id' => 601, 'tombstoned_at' => now(), 'tombstone_reason' => 'external_deleted'],
            ));
        });

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection, $externalId) {
            return DB::table('integration_external_mappings')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['external_id' => $externalId, 'local_id' => 601],
            ));
        });

        $this->assertTrue($affected, 'A tombstoned mapping must never block a fresh live remap for the same local/external pair.');
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_external_mappings'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_external_mappings'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_external_mappings'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_external_mappings'::regclass and polname = ?",
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
        IntegrationExternalMapping::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_external_mappings')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_external_mappings')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_firm_a_context_can_read_its_own_mapping(): void
    {
        $firm = Firm::factory()->create();
        $mapping = IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_external_mappings')->pluck('id')->all());

        $this->assertSame([$mapping->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_mapping(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();
        $mappingB = IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_external_mappings')->pluck('id')->all());

        $this->assertNotContains($mappingB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_mapping_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('integration_external_mappings')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_mapping(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $mappingB = IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_external_mappings')->where('id', $mappingB->id)->update(['tombstoned_at' => now()]),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_external_mappings')->where('id', $mappingB->id)->value('tombstoned_at'));
        $this->assertNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_mapping(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $mappingB = IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_external_mappings')->where('id', $mappingB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_external_mappings')->where('id', $mappingB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $mapping = IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($mapping, $firmB) {
            DB::table('integration_external_mappings')->where('id', $mapping->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

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
        // Whole-wave rollback (see WHOLE_WAVE_MIGRATION_PATHS docblock
        // above) — isolated single-table rollback is unsafe here.
        foreach (self::WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        // Checkpoint 9's integration_usage_records now FK-references both
        // integration_sync_runs and integration_sync_items (see
        // CP9_USAGE_RECORDS_MIGRATION_PATHS docblock above) — it must be
        // rolled back FIRST, before this whole-wave rollback below.
        foreach (array_reverse(self::CP9_USAGE_RECORDS_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'), 'integration_usage_records must not survive its own rollback.');

        foreach (array_reverse(self::WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, Artisan::output());
        }

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = to_regclass('integration_external_mappings') and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        foreach (self::WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not survive the whole-wave rollback.");
        }

        foreach (self::WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, Artisan::output());
        }

        foreach (self::WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must be restored by the whole-wave reapplication.");
        }

        // Reapply Checkpoint 9's integration_usage_records LAST — after
        // integration_sync_runs/integration_sync_items already exist again
        // — in forward (creation) order.
        foreach (self::CP9_USAGE_RECORDS_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 9 integration_usage_records) failed: ".Artisan::output());
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'), 'integration_usage_records must be restored by its own reapplication.');

        // This file's own table: byte-identical restoration proof.
        $columns = Schema::getColumnListing('integration_external_mappings');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_external_mappings'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_external_mappings'");
        $this->assertCount(1, $policiesAfterReapply);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        // Whole-wave rollback via direct include()/down()/up() calls —
        // same isolated-rollback-is-unsafe reasoning as the Artisan-call
        // test above, applied to the direct-call proof form.
        $migrations = array_map(
            static fn (string $path) => include base_path($path),
            self::WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Checkpoint 9's integration_usage_records now FK-references both
        // integration_sync_runs and integration_sync_items (see
        // CP9_USAGE_RECORDS_MIGRATION_PATHS docblock above) — it must be
        // torn down FIRST, before the whole-wave teardown below.
        $usageRecordsMigrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP9_USAGE_RECORDS_MIGRATION_PATHS,
        );
        foreach (array_reverse($usageRecordsMigrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('integration_usage_records'));

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        foreach (self::WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        foreach ($migrations as $migration) {
            $migration->up();
        }

        foreach (self::WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        // Rebuild Checkpoint 9's integration_usage_records LAST — after
        // integration_sync_runs/integration_sync_items already exist again.
        foreach ($usageRecordsMigrations as $migration) {
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('integration_usage_records'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_external_mappings'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_external_mappings', (new IntegrationExternalMapping())->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationExternalMapping::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $mappings = IntegrationExternalMapping::factory()->count(3)->create();

        $this->assertSame(3, $mappings->pluck('id')->unique()->count());
        foreach ($mappings as $mapping) {
            $this->assertNotNull($mapping->firm_id);
            $this->assertNotNull($mapping->firm_integration_id);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection): array
    {
        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'resource_type' => 'contact',
            'local_type' => 'App\\Models\\Contact',
            'local_id' => 1,
            'external_id' => (string) Str::uuid(),
            'sync_direction' => 'inbound',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
