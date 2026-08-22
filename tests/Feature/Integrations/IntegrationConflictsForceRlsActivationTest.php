<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * IntegrationConflictsForceRlsActivationTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §4/§5/§8;
 * agent-6h-test-plan-and-review.md §6 items 1-5, 14). The 5 migration-
 * level CHECK constraints and IntegrationConflictService::transitionStatus()
 * itself are covered separately, in full, by IntegrationConflictServiceTest.php
 * — this file focuses on schema/FK/RLS/saving-listener structure, mirroring
 * IntegrationOauthStatesForceRlsActivationTest's §7 (compensating
 * same-firm actor invariant) pattern for this table's two bare-FK actor
 * columns.
 */
class IntegrationConflictsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_05_054001_create_integration_conflicts_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_05_054001_create_integration_conflicts_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table';

    private const POLICY_NAME = 'integration_conflicts_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'firm_integration_id', 'sync_item_id', 'external_mapping_id', 'resource_type',
        'local_type', 'local_id', 'conflict_type', 'local_value', 'external_value', 'local_version_token',
        'external_version_token', 'status', 'requires_manual_review', 'resolved_by_firm_user_id',
        'resolution_approved_by_firm_user_id', 'resolution_note', 'resolved_at', 'detected_at',
        'expires_at', 'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_conflicts'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_conflicts');

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
             where conrelid = 'integration_conflicts'::regclass and contype = 'f' and conname = 'integration_conflicts_firm_integration_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('firm_integrations', $row->foreign_table);
    }

    public function test_composite_foreign_key_on_sync_item_id_exists_and_is_nullable(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_conflicts'::regclass and contype = 'f' and conname = 'integration_conflicts_sync_item_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('integration_sync_items', $row->foreign_table);
        $this->assertTrue(Schema::hasColumn('integration_conflicts', 'sync_item_id'));
    }

    public function test_composite_foreign_key_on_external_mapping_id_exists_and_is_nullable(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_conflicts'::regclass and contype = 'f' and conname = 'integration_conflicts_external_mapping_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('integration_external_mappings', $row->foreign_table);
    }

    public function test_deleting_a_sync_item_nulls_out_the_reference_instead_of_cascading(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->create();
        $item = IntegrationSyncItem::factory()->forSyncRun($run)->create();

        $conflict = $this->runWithFirmContext($firm, function () use ($connection, $item) {
            return IntegrationConflict::factory()
                ->forFirmIntegration($connection)
                ->create(['sync_item_id' => $item->id]);
        });

        $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->where('id', $item->id)->delete());

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_conflicts')->where('id', $conflict->id)->first());
        $this->assertNotNull($fresh, 'The conflict row must survive its sync_item parent being pruned.');
        $this->assertNull($fresh->sync_item_id);
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB) {
            DB::table('integration_conflicts')->insert($this->rawRowAttributes($firmA, $connectionB));
        });
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_conflicts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_conflicts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_conflicts'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_conflicts'::regclass and polname = ?",
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
        IntegrationConflict::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_conflicts')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_conflicts')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_firm_a_context_can_read_its_own_conflict(): void
    {
        $firm = Firm::factory()->create();
        $conflict = IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_conflicts')->pluck('id')->all());

        $this->assertSame([$conflict->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_conflict(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();
        $conflictB = IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_conflicts')->pluck('id')->all());

        $this->assertNotContains($conflictB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_conflict_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('integration_conflicts')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_conflict(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $conflictB = IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_conflicts')->where('id', $conflictB->id)->update(['status' => 'ignored']),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_conflicts')->where('id', $conflictB->id)->value('status'));
        $this->assertSame('detected', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_conflict(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $conflictB = IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_conflicts')->where('id', $conflictB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_conflicts')->where('id', $conflictB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $conflict = IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($conflict, $firmB) {
            DB::table('integration_conflicts')->where('id', $conflict->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

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
    // 4. Compensating same-firm actor invariant (saving listener)
    // ------------------------------------------------------------

    public function test_saving_listener_rejects_a_resolved_by_actor_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $firmUserB = $this->firmUserFor($firmB);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must reference a firm_users row belonging to the same firm_id/');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionA, $firmUserB) {
            IntegrationConflict::create([
                'firm_id' => $firmA->id,
                'firm_integration_id' => $connectionA->id,
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 1,
                'conflict_type' => 'field_value_mismatch',
                'status' => 'detected',
                'requires_manual_review' => false,
                'detected_at' => now(),
                'resolved_by_firm_user_id' => $firmUserB->id,
            ]);
        });
    }

    public function test_saving_listener_rejects_a_resolution_approved_by_actor_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $firmUserB = $this->firmUserFor($firmB);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must reference a firm_users row belonging to the same firm_id/');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionA, $firmUserB) {
            IntegrationConflict::create([
                'firm_id' => $firmA->id,
                'firm_integration_id' => $connectionA->id,
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 1,
                'conflict_type' => 'field_value_mismatch',
                'status' => 'detected',
                'requires_manual_review' => false,
                'detected_at' => now(),
                'resolution_approved_by_firm_user_id' => $firmUserB->id,
            ]);
        });
    }

    // ------------------------------------------------------------
    // 5. Migration rollback and reapplication
    // ------------------------------------------------------------

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));
        $this->assertTrue(Schema::hasTable('integration_conflicts'));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_conflicts'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_conflicts'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        $tableRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableRollbackExit, Artisan::output());
        $this->assertFalse(Schema::hasTable('integration_conflicts'));

        $tableMigrateExit = Artisan::call('migrate', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableMigrateExit, Artisan::output());
        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        $this->assertTrue(Schema::hasTable('integration_conflicts'));

        $columns = Schema::getColumnListing('integration_conflicts');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_conflicts'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_conflicts'");
        $this->assertCount(1, $policiesAfterReapply);

        // The 5 CHECK constraints (created by the TABLE migration, not
        // the RLS migration) must also survive rollback/reapplication.
        $checks = DB::select(
            "select conname from pg_constraint where conrelid = 'integration_conflicts'::regclass and contype = 'c'"
        );
        $this->assertCount(5, $checks);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_conflicts'));

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('integration_conflicts'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_conflicts'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 6. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_conflicts', (new IntegrationConflict)->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationConflict::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $conflicts = IntegrationConflict::factory()->count(3)->create();

        $this->assertSame(3, $conflicts->pluck('id')->unique()->count());
        foreach ($conflicts as $conflict) {
            $this->assertNotNull($conflict->firm_id);
            $this->assertNotNull($conflict->firm_integration_id);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function firmUserFor(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());
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
            'local_type' => 'App\\Models\\Contact',
            'local_id' => 1,
            'conflict_type' => 'field_value_mismatch',
            'status' => 'detected',
            'requires_manual_review' => false,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
