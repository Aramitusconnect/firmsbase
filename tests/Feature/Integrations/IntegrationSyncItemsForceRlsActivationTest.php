<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
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
 * IntegrationSyncItemsForceRlsActivationTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §3/§5;
 * agent-6h-test-plan-and-review.md §6 items 1-5, 14). Mirrors
 * IntegrationSyncRunsForceRlsActivationTest's structural conventions,
 * adapted for this table's composite FK parent being
 * integration_sync_runs (not firm_integrations directly).
 */
class IntegrationSyncItemsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_05_051001_create_integration_sync_items_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_05_051001_create_integration_sync_items_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table';

    private const POLICY_NAME = 'integration_sync_items_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'sync_run_id', 'resource_type', 'local_type', 'local_id', 'external_id',
        'status', 'attempt_count', 'next_attempt_at', 'payload_hash', 'last_error', 'terminal_at',
        'created_at', 'updated_at',
    ];

    /**
     * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) —
     * rollback-methodology bug: integration_sync_items is a
     * composite-FK PARENT of integration_conflicts
     * (integration_conflicts_sync_item_fk, see
     * database/migrations/2026_09_05_054001_create_integration_conflicts_table.php),
     * so rolling back ONLY this table's own 2 migrations while
     * integration_conflicts still exists fails at the DB constraint
     * layer ("cannot drop table integration_sync_items because other
     * objects depend on it"). Both rollback tests below therefore roll
     * back the ENTIRE 12-migration Checkpoint 6 wave together, in the
     * frozen reverse-dependency order (frozen-design-post-review.md §2:
     * outbox_events -> conflicts -> sync_cursors -> external_mappings
     * -> sync_items -> sync_runs — which is exactly the reverse of this
     * array's own creation order below), mirroring Checkpoint 5's
     * identical rollback-order-dependency precedent
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

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_sync_items'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_sync_items');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_composite_foreign_key_on_firm_id_and_sync_run_id_exists(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_sync_items'::regclass and contype = 'f' and conname = 'integration_sync_items_sync_run_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('integration_sync_runs', $row->foreign_table);
    }

    public function test_unique_sync_run_id_and_external_id_index_exists(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_sync_items' and indexname = 'integration_sync_items_sync_run_id_external_id_unique'"
        );

        $this->assertNotNull($row);
    }

    public function test_composite_foreign_key_rejects_a_sync_run_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $runB = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $runB) {
            DB::table('integration_sync_items')->insert($this->rawRowAttributes($firmA, $runB));
        });
    }

    public function test_inserting_a_nonexistent_sync_run_id_is_rejected_by_the_foreign_key(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('integration_sync_items')->insert([
                'firm_id' => $firm->id,
                'sync_run_id' => 999999999,
                'resource_type' => 'contact',
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_duplicate_external_id_within_the_same_sync_run_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $run = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();
        $externalId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $run, $externalId) {
            DB::table('integration_sync_items')->insert(array_merge($this->rawRowAttributes($firm, $run), ['external_id' => $externalId]));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $run, $externalId) {
            DB::table('integration_sync_items')->insert(array_merge($this->rawRowAttributes($firm, $run), ['external_id' => $externalId]));
        });
    }

    public function test_two_items_with_null_external_id_within_the_same_run_do_not_conflict(): void
    {
        $firm = Firm::factory()->create();
        $run = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        $this->runWithFirmContext($firm, function () use ($firm, $run) {
            DB::table('integration_sync_items')->insert(array_merge($this->rawRowAttributes($firm, $run), ['external_id' => null]));
        });

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $run) {
            return DB::table('integration_sync_items')->insert(array_merge($this->rawRowAttributes($firm, $run), ['external_id' => null]));
        });

        $this->assertTrue($affected, 'Postgres treats NULL external_id values as mutually non-conflicting.');
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_sync_items'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_sync_items'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_sync_items'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_sync_items'::regclass and polname = ?",
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
        IntegrationSyncItem::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_sync_items')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $run = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_sync_items')->insert($this->rawRowAttributes($firm, $run));
    }

    public function test_firm_a_context_can_read_its_own_item(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->itemForFirm($firm);

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->pluck('id')->all());

        $this->assertSame([$item->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_item(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->itemForFirm($firmA);
        $itemB = $this->itemForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_sync_items')->pluck('id')->all());

        $this->assertNotContains($itemB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_an_item_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $runB = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $runB) {
            DB::table('integration_sync_items')->insert($this->rawRowAttributes($firmB, $runB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_item(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $itemB = $this->itemForFirm($firmB);

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_sync_items')->where('id', $itemB->id)->update(['status' => 'succeeded']),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_sync_items')->where('id', $itemB->id)->value('status'));
        $this->assertSame('pending', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_item(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $itemB = $this->itemForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_sync_items')->where('id', $itemB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_sync_items')->where('id', $itemB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $item = $this->itemForFirm($firmA);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($item, $firmB) {
            DB::table('integration_sync_items')->where('id', $item->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, function () use ($firm) {
            $run = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->create();
        });

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

        foreach (array_reverse(self::WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, Artisan::output());
        }

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = to_regclass('integration_sync_items') and polname = ?",
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

        // This file's own table: byte-identical restoration proof.
        $columns = Schema::getColumnListing('integration_sync_items');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_items'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_sync_items'");
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

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_items'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_sync_items', (new IntegrationSyncItem())->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationSyncItem::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $items = IntegrationSyncItem::factory()->count(3)->create();

        $this->assertSame(3, $items->pluck('id')->unique()->count());
        foreach ($items as $item) {
            $this->assertNotNull($item->firm_id);
            $this->assertNotNull($item->sync_run_id);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function itemForFirm(Firm $firm): IntegrationSyncItem
    {
        $run = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        return IntegrationSyncItem::factory()->forSyncRun($run)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, IntegrationSyncRun $run): array
    {
        return [
            'firm_id' => $firm->id,
            'sync_run_id' => $run->id,
            'resource_type' => 'contact',
            'external_id' => (string) Str::uuid(),
            'status' => 'pending',
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
