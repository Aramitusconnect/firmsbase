<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
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
 * IntegrationSyncCursorsForceRlsActivationTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §5/§8;
 * agent-6h-test-plan-and-review.md §6 items 1-5, 14). This is the ONE
 * table among the six that is mutated in place, not append-only.
 */
class IntegrationSyncCursorsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_05_053001_create_integration_sync_cursors_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_05_053001_create_integration_sync_cursors_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table';

    private const POLICY_NAME = 'integration_sync_cursors_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'firm_integration_id', 'resource_type', 'sync_direction', 'cursor_value',
        'cursor_version', 'status', 'locked_by_sync_run_id', 'locked_at', 'consecutive_failure_count',
        'cursor_issued_at', 'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_sync_cursors'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_sync_cursors');

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
             where conrelid = 'integration_sync_cursors'::regclass and contype = 'f' and conname = 'integration_sync_cursors_firm_integration_fk'"
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
            DB::table('integration_sync_cursors')->insert($this->rawRowAttributes($firmA, $connectionB));
        });
    }

    public function test_natural_key_unique_index_exists_on_firm_integration_id_resource_type_sync_direction(): void
    {
        $rows = DB::select("select indexdef from pg_indexes where tablename = 'integration_sync_cursors'");

        $found = false;
        foreach ($rows as $row) {
            if (str_contains($row->indexdef, 'UNIQUE') && str_contains($row->indexdef, 'firm_integration_id')
                && str_contains($row->indexdef, 'resource_type') && str_contains($row->indexdef, 'sync_direction')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Expected a UNIQUE index covering (firm_integration_id, resource_type, sync_direction).');
    }

    public function test_natural_key_uniqueness_rejects_a_duplicate_scope(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_sync_cursors')->insert($this->rawRowAttributes($firm, $connection));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_sync_cursors')->insert($this->rawRowAttributes($firm, $connection));
        });
    }

    public function test_an_inbound_and_outbound_cursor_for_the_same_resource_type_are_genuinely_independent(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_sync_cursors')->insert(array_merge($this->rawRowAttributes($firm, $connection), ['sync_direction' => 'inbound']));

            return DB::table('integration_sync_cursors')->insert(array_merge($this->rawRowAttributes($firm, $connection), ['sync_direction' => 'outbound']));
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_sync_cursors'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_sync_cursors'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_sync_cursors'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_sync_cursors'::regclass and polname = ?",
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
        IntegrationSyncCursor::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_sync_cursors')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_sync_cursors')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_firm_a_context_can_read_its_own_cursor(): void
    {
        $firm = Firm::factory()->create();
        $cursor = IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_cursors')->pluck('id')->all());

        $this->assertSame([$cursor->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_cursor(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();
        $cursorB = IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_sync_cursors')->pluck('id')->all());

        $this->assertNotContains($cursorB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_cursor_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('integration_sync_cursors')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_cursor(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $cursorB = IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_sync_cursors')->where('id', $cursorB->id)->update(['cursor_version' => 99]),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_sync_cursors')->where('id', $cursorB->id)->value('cursor_version'));
        $this->assertSame(0, $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_cursor(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $cursorB = IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_sync_cursors')->where('id', $cursorB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_sync_cursors')->where('id', $cursorB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $cursor = IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($cursor, $firmB) {
            DB::table('integration_sync_cursors')->where('id', $cursor->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

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
        $this->assertTrue(Schema::hasTable('integration_sync_cursors'));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_cursors'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_sync_cursors'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        $tableRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableRollbackExit, Artisan::output());
        $this->assertFalse(Schema::hasTable('integration_sync_cursors'));

        $tableMigrateExit = Artisan::call('migrate', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableMigrateExit, Artisan::output());
        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        $this->assertTrue(Schema::hasTable('integration_sync_cursors'));

        $columns = Schema::getColumnListing('integration_sync_cursors');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_cursors'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_sync_cursors'");
        $this->assertCount(1, $policiesAfterReapply);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_sync_cursors'));

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('integration_sync_cursors'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_sync_cursors'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_sync_cursors', (new IntegrationSyncCursor())->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationSyncCursor::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $firm = Firm::factory()->create();
        $connectionOne = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionTwo = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionThree = FirmIntegration::factory()->forFirm($firm)->create();

        $cursors = collect([$connectionOne, $connectionTwo, $connectionThree])
            ->map(fn (FirmIntegration $c) => IntegrationSyncCursor::factory()->forFirmIntegration($c)->create());

        $this->assertSame(3, $cursors->pluck('id')->unique()->count());
        foreach ($cursors as $cursor) {
            $this->assertNotNull($cursor->firm_id);
            $this->assertNotNull($cursor->firm_integration_id);
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
            'sync_direction' => 'inbound',
            'cursor_version' => 0,
            'status' => 'idle',
            'consecutive_failure_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
