<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Concerns\BelongsToTenant;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
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
 * FinancialEvidenceMatterRequestsForceRlsActivationTest — FirmsVault
 * Live Integrations, Checkpoint 4 ("Plaid financial evidence add-on").
 * Modeled directly on
 * Tests\Feature\Integrations\IntegrationSyncCursorsForceRlsActivationTest,
 * this checkpoint's own established template for a Direct
 * `BelongsToTenant` + FORCE RLS activation proof. Registered under
 * Security\RlsForceRollout (not Feature\FinancialEvidence) to satisfy
 * SchemaTenantFirewallTest::test_check_5_every_forced_table_has_a_matching_activation_test_file,
 * which scans the whole tests/ tree for
 * Str::studly($table).'ForceRlsActivationTest.php' by filename alone.
 */
class FinancialEvidenceMatterRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_25_190001_create_financial_evidence_matter_requests_table.php';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_25_190002_prepare_row_level_security_and_force_rls_on_financial_evidence_matter_requests_table.php';

    private const POLICY_NAME = 'financial_evidence_matter_requests_tenant_isolation';

    private const TABLE = 'financial_evidence_matter_requests';

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }

    public function test_belongs_to_tenant_columns_present(): void
    {
        $columns = Schema::getColumnListing(self::TABLE);

        foreach (['id', 'firm_id', 'matter_id', 'requested_by_firm_user_id', 'purpose', 'requested_products_json', 'status', 'requested_at'] as $expected) {
            $this->assertContains($expected, $columns);
        }
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [self::TABLE]);

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select('select policyname from pg_policies where tablename = ?', [self::TABLE]);

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            'select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = ?::regclass and polname = ?',
            [self::TABLE, self::POLICY_NAME]
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
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $this->runWithFirmContext($firm, fn () => DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $matter, $firmUser)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $matter, $firmUser));
    }

    public function test_firm_a_context_can_read_its_own_row(): void
    {
        $firm = Firm::factory()->create();
        [$matter, $firmUser] = $this->runWithFirmContext($firm, fn () => [
            Matter::factory()->forFirm($firm)->create(),
            FirmUser::factory()->create(['firm_id' => $firm->id]),
        ]);

        $id = $this->runWithFirmContext($firm, function () use ($firm, $matter, $firmUser) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $matter, $firmUser));

            return DB::table(self::TABLE)->value('id');
        });

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table(self::TABLE)->pluck('id')->all());

        $this->assertSame([$id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$matterB, $firmUserB] = $this->runWithFirmContext($firmB, fn () => [
            Matter::factory()->forFirm($firmB)->create(),
            FirmUser::factory()->create(['firm_id' => $firmB->id]),
        ]);

        $idB = $this->runWithFirmContext($firmB, function () use ($firmB, $matterB, $firmUserB) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmB, $matterB, $firmUserB));

            return DB::table(self::TABLE)->value('id');
        });

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->pluck('id')->all());

        $this->assertNotContains($idB, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$matterB, $firmUserB] = $this->runWithFirmContext($firmB, fn () => [
            Matter::factory()->forFirm($firmB)->create(),
            FirmUser::factory()->create(['firm_id' => $firmB->id]),
        ]);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $matterB, $firmUserB) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmB, $matterB, $firmUserB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$matterB, $firmUserB] = $this->runWithFirmContext($firmB, fn () => [
            Matter::factory()->forFirm($firmB)->create(),
            FirmUser::factory()->create(['firm_id' => $firmB->id]),
        ]);

        $idB = $this->runWithFirmContext($firmB, function () use ($firmB, $matterB, $firmUserB) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmB, $matterB, $firmUserB));

            return DB::table(self::TABLE)->value('id');
        });

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->where('id', $idB)->update(['status' => 'reviewed']));

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table(self::TABLE)->where('id', $idB)->value('status'));
        $this->assertSame('pending', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$matterB, $firmUserB] = $this->runWithFirmContext($firmB, fn () => [
            Matter::factory()->forFirm($firmB)->create(),
            FirmUser::factory()->create(['firm_id' => $firmB->id]),
        ]);

        $idB = $this->runWithFirmContext($firmB, function () use ($firmB, $matterB, $firmUserB) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmB, $matterB, $firmUserB));

            return DB::table(self::TABLE)->value('id');
        });

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->where('id', $idB)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table(self::TABLE)->where('id', $idB)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$matterA, $firmUserA] = $this->runWithFirmContext($firmA, fn () => [
            Matter::factory()->forFirm($firmA)->create(),
            FirmUser::factory()->create(['firm_id' => $firmA->id]),
        ]);

        $idA = $this->runWithFirmContext($firmA, function () use ($firmA, $matterA, $firmUserA) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmA, $matterA, $firmUserA));

            return DB::table(self::TABLE)->value('id');
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($idA, $firmB) {
            DB::table(self::TABLE)->where('id', $idA)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, function () use ($firm) {
            [$matter, $firmUser] = [
                Matter::factory()->forFirm($firm)->create(),
                FirmUser::factory()->create(['firm_id' => $firm->id]),
            ];
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $matter, $firmUser));
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
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));
        $this->assertTrue(Schema::hasTable(self::TABLE));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne('select 1 from pg_policy where polrelid = ?::regclass and polname = ?', [self::TABLE, self::POLICY_NAME]);
        $this->assertNull($policyAfterRollback);

        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        $rowAfterReapply = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select('select policyname from pg_policies where tablename = ?', [self::TABLE]);
        $this->assertCount(1, $policiesAfterReapply);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);

        $rlsMigration->down();

        $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertFalse((bool) $row->relrowsecurity);

        $rlsMigration->up();

        $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame(self::TABLE, (new FinancialEvidenceMatterRequest)->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(FinancialEvidenceMatterRequest::class));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, Matter $matter, FirmUser $firmUser): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'requested_by_firm_user_id' => $firmUser->id,
            'purpose' => 'Verify income for support calculation.',
            'requested_products_json' => json_encode(['bank_account', 'transaction']),
            'status' => 'pending',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
