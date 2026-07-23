<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationSyncRunsForceRlsActivationTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §2/§5;
 * agent-6h-test-plan-and-review.md §6 items 1-5, 14). Mirrors
 * IntegrationOauthStatesForceRlsActivationTest/
 * IntegrationCredentialsForceRlsActivationTest's structural/assertion
 * conventions: direct pg_class/pg_policies/pg_policy catalog queries,
 * runWithFirmContext() for every tenant-scoped read/write, paired
 * Artisan-call / direct-include migration rollback proofs.
 */
class IntegrationSyncRunsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_05_050001_create_integration_sync_runs_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_05_050001_create_integration_sync_runs_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_05_050002_prepare_row_level_security_and_force_rls_on_integration_sync_runs_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_05_050002_prepare_row_level_security_and_force_rls_on_integration_sync_runs_table';

    private const POLICY_NAME = 'integration_sync_runs_tenant_isolation';

    /**
     * POST-CHECKPOINT-7 UPDATE: Checkpoint 7 ("inbound webhook security")
     * added `triggering_webhook_event_id` via its own dedicated
     * `2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table`
     * ALTER TABLE migration (frozen-design-post-security-review.md §11,
     * fulfilling Checkpoint 6's own disclosed obligation — see this
     * table's create migration docblock). Listed here in the same
     * position the migration inserts it in the live schema
     * (`->after('trigger_source')`), even though the assertion below
     * sorts both sides before comparing and is therefore order-
     * independent — this is purely for readability against the physical
     * column order.
     *
     * @var list<string>
     */
    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'firm_integration_id', 'resource_type', 'sync_direction', 'run_type',
        'trigger_source', 'triggering_webhook_event_id', 'status', 'retried_run_id',
        'cancel_requested_at', 'items_total', 'items_succeeded', 'items_failed', 'items_skipped',
        'error_summary', 'started_at', 'finished_at', 'created_at', 'updated_at',
    ];

    /**
     * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) —
     * rollback-methodology bug: integration_sync_runs is a composite-FK
     * PARENT of integration_sync_items
     * (integration_sync_items_sync_run_fk, see
     * database/migrations/2026_09_05_051001_create_integration_sync_items_table.php),
     * so rolling back ONLY this table's own 2 migrations while
     * integration_sync_items still exists fails at the DB constraint
     * layer ("cannot drop table integration_sync_runs because other
     * objects depend on it"). Both rollback tests below therefore roll
     * back the ENTIRE 12-migration Checkpoint 6 wave together, in the
     * frozen reverse-dependency order (frozen-design-post-review.md §2:
     * outbox_events -> conflicts -> sync_cursors -> external_mappings
     * -> sync_items -> sync_runs — which is exactly the reverse of this
     * array's own creation order below), mirroring Checkpoint 5's
     * identical rollback-order-dependency precedent
     * (reviews/checkpoint-05/precommit-failure-triage.md).
     *
     * POST-CHECKPOINT-7 UPDATE: Checkpoint 7's
     * `2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table`
     * migration ALTERs this table directly (adds
     * `triggering_webhook_event_id` plus a composite FK into the
     * Checkpoint 7 `integration_inbound_webhook_events` table). It is
     * appended LAST here, in true creation-chronological order (it is
     * timestamped 2026_09_06, after every other entry in this array) —
     * `array_reverse()` therefore runs its down() FIRST, dropping the
     * column/FK while integration_sync_runs still exists untouched, and
     * runs its up() LAST during reapplication, after this array's own
     * create-table migration has already recreated a bare
     * integration_sync_runs. Without this, whole-wave rollback would drop
     * and recreate integration_sync_runs via its original create
     * migration alone, silently losing this column permanently (the
     * `migrations` table would still show 060005 as "ran" even though the
     * live column would be gone) — exactly the same class of bug this
     * whole-wave mechanism already exists to prevent for
     * integration_sync_items/integration_conflicts. 060005 does not
     * depend on anything else in this array (only on
     * integration_sync_runs, already covered, and on
     * integration_inbound_webhook_events, a separate Checkpoint 7 table
     * that is not part of this wave and is never touched by it).
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
        'database/migrations/2026_09_06_060005_add_triggering_webhook_event_id_to_integration_sync_runs_table.php',
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
     * (RLS-prep down(), then create-table down()), mirroring every other
     * paired create/RLS-prep migration pair's own rollback idiom already
     * used throughout this file.
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
        $this->assertTrue(Schema::hasTable('integration_sync_runs'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_sync_runs');

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
             where conrelid = 'integration_sync_runs'::regclass and contype = 'f' and conname = 'integration_sync_runs_firm_integration_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('firm_integrations', $row->foreign_table);
    }

    public function test_composite_self_referencing_foreign_key_on_retried_run_id_exists(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table, array_length(conkey, 1) as col_count
             from pg_constraint
             where conrelid = 'integration_sync_runs'::regclass and contype = 'f' and conname = 'integration_sync_runs_retried_run_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('integration_sync_runs', $row->foreign_table);
        $this->assertSame(2, (int) $row->col_count, 'retried_run_id FK must be composite (firm_id, retried_run_id).');
    }

    public function test_unique_firm_id_id_index_exists(): void
    {
        $row = DB::selectOne(
            "select 1 from pg_indexes where tablename = 'integration_sync_runs' and indexdef like '%UNIQUE%' and indexdef like '%firm_id%' and indexdef like '%id%'"
        );

        $this->assertNotNull($row);
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB) {
            DB::table('integration_sync_runs')->insert($this->rawRowAttributes($firmA, $connectionB));
        });
    }

    public function test_inserting_a_nonexistent_firm_integration_id_is_rejected_by_the_foreign_key(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('integration_sync_runs')->insert([
                'firm_id' => $firm->id,
                'firm_integration_id' => 999999999,
                'resource_type' => 'contact',
                'sync_direction' => 'inbound',
                'run_type' => 'initial',
                'trigger_source' => 'manual',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_one_active_run_per_scope_partial_unique_index_rejects_a_second_non_terminal_run(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_sync_runs')->insert($this->rawRowAttributes($firm, $connection));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_sync_runs')->insert($this->rawRowAttributes($firm, $connection));
        });
    }

    public function test_one_active_run_per_scope_index_does_not_block_a_second_terminal_run(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_sync_runs')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['status' => 'succeeded'],
            ));
        });

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            return DB::table('integration_sync_runs')->insert($this->rawRowAttributes($firm, $connection));
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_sync_runs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_sync_runs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_sync_runs'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_sync_runs'::regclass and polname = ?",
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
        IntegrationSyncRun::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_sync_runs')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_sync_runs')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_firm_a_context_can_read_its_own_run(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runForFirm($firm);

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')->pluck('id')->all());

        $this->assertSame([$run->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_run(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runForFirm($firmA);
        $runB = $this->runForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_sync_runs')->pluck('id')->all());

        $this->assertNotContains($runB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_run_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('integration_sync_runs')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_run(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $runB = $this->runForFirm($firmB);

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_sync_runs')->where('id', $runB->id)->update(['status' => 'cancelled']),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_sync_runs')->where('id', $runB->id)->value('status'));
        $this->assertSame('pending', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_run(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $runB = $this->runForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_sync_runs')->where('id', $runB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_sync_runs')->where('id', $runB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $run = $this->runForFirm($firmA);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($run, $firmB) {
            DB::table('integration_sync_runs')->where('id', $run->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

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
            "select 1 from pg_policy where polrelid = to_regclass('integration_sync_runs') and polname = ?",
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
        $columns = Schema::getColumnListing('integration_sync_runs');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_runs'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_sync_runs'");
        $this->assertCount(1, $policiesAfterReapply);
        $this->assertSame(self::POLICY_NAME, $policiesAfterReapply[0]->policyname);
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

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_runs'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_sync_runs', (new IntegrationSyncRun())->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationSyncRun::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $runs = IntegrationSyncRun::factory()->count(3)->create();

        $this->assertSame(3, $runs->pluck('id')->unique()->count());
        foreach ($runs as $run) {
            $this->assertNotNull($run->firm_id);
            $this->assertNotNull($run->firm_integration_id);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function runForFirm(Firm $firm): IntegrationSyncRun
    {
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        return IntegrationSyncRun::factory()->forFirmIntegration($connection)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection): array
    {
        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'resource_type' => 'contact',
            'sync_direction' => 'inbound',
            'run_type' => 'initial',
            'trigger_source' => 'manual',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
