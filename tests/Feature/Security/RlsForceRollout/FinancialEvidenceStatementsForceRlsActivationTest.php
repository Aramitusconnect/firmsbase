<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\FinancialEvidenceStatement;
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
 * FinancialEvidenceStatementsForceRlsActivationTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on").
 * Modeled on Tests\Feature\Security\RlsForceRollout\FinancialEvidenceBankAccountsForceRlsActivationTest,
 * this table's own create migration documenting a byte-for-byte
 * identical RLS activation shape.
 */
class FinancialEvidenceStatementsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_24_180014_prepare_row_level_security_and_force_rls_on_financial_evidence_statements_table.php';

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_24_180013_create_financial_evidence_statements_table.php';

    private const POLICY_NAME = 'financial_evidence_statements_tenant_isolation';

    private const TABLE = 'financial_evidence_statements';

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table, array_length(conkey, 1) as column_count
             from pg_constraint
             where conrelid = ?::regclass and contype = 'f' and confrelid::regclass::text = 'firm_integrations'",
            [self::TABLE]
        );

        $this->assertNotNull($row, 'Expected a composite foreign key referencing firm_integrations.');
        $this->assertSame(2, (int) $row->column_count);
    }

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
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

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";
        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_missing_tenant_context_cannot_read(): void
    {
        $firm = Firm::factory()->create();
        $this->seedRow($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->makeConnection($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_firm_a_context_can_read_its_own_row(): void
    {
        $firm = Firm::factory()->create();
        $id = $this->seedRow($firm);

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table(self::TABLE)->pluck('id')->all());
        $this->assertSame([$id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $idB = $this->seedRow($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->pluck('id')->all());
        $this->assertNotContains($idB, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->makeConnection($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $idB = $this->seedRow($firmB);

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->where('id', $idB)->update(['storage_disk' => 'reassigned']));
        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table(self::TABLE)->where('id', $idB)->value('storage_disk'));
        $this->assertNotSame('reassigned', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $idB = $this->seedRow($firmB);

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->where('id', $idB)->delete());
        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table(self::TABLE)->where('id', $idB)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $idA = $this->seedRow($firmA);

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

        $this->seedRow($firm);

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

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));
        $this->assertTrue(Schema::hasTable(self::TABLE));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);

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
        $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertFalse((bool) $row->relrowsecurity);

        $rlsMigration->up();
        $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [self::TABLE]);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame(self::TABLE, (new FinancialEvidenceStatement)->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(FinancialEvidenceStatement::class));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function makeConnection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function seedRow(Firm $firm): int
    {
        $connection = $this->makeConnection($firm);

        return $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $connection));

            return (int) DB::table(self::TABLE)->value('id');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'plaid_statement_id' => 'stmt_'.Str::random(16),
            'month' => 6,
            'year' => 2026,
            'available_date' => now()->toDateString(),
            'storage_disk' => null,
            'storage_path' => null,
            'raw_json' => json_encode(['statement_id' => 'stmt_'.Str::random(8)]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
