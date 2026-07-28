<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\FinancialAccountReclassificationRequest;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\Firm;
use App\Models\FirmUser;
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
 * FinancialAccountReclassificationRequestsForceRlsActivationTest —
 * FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial
 * evidence add-on"). Modeled directly on
 * Tests\Feature\Integrations\IntegrationSyncCursorsForceRlsActivationTest.
 * This is the highest-scrutiny table in the checkpoint (backs the
 * two-person account-reclassification approval flow) — the actual
 * approval-chain correctness proofs live in
 * Tests\Feature\FinancialEvidence\FinancialAccountReclassificationServiceTest;
 * this file proves only tenant isolation.
 */
class FinancialAccountReclassificationRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_25_190021_prepare_row_level_security_and_force_rls_on_financial_account_reclassification_requests_table.php';

    private const POLICY_NAME = 'financial_account_reclassification_requests_tenant_isolation';

    private const TABLE = 'financial_account_reclassification_requests';

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
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
        [$account, $firmUser] = $this->makeDependencies($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $account, $firmUser));
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
        [$accountB, $firmUserB] = $this->makeDependencies($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $accountB, $firmUserB) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firmB, $accountB, $firmUserB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $idB = $this->seedRow($firmB);

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table(self::TABLE)->where('id', $idB)->update(['status' => 'approved']));
        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table(self::TABLE)->where('id', $idB)->value('status'));
        $this->assertSame('pending', $reReadAsFirmB);
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
        $this->assertSame(self::TABLE, (new FinancialAccountReclassificationRequest)->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(FinancialAccountReclassificationRequest::class));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: FinancialEvidenceBankAccount, 1: FirmUser}
     */
    private function makeDependencies(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            $firmUser = FirmUser::factory()->create(['firm_id' => $firm->id]);

            return [$account, $firmUser];
        });
    }

    private function seedRow(Firm $firm): int
    {
        [$account, $firmUser] = $this->makeDependencies($firm);

        return $this->runWithFirmContext($firm, function () use ($firm, $account, $firmUser) {
            DB::table(self::TABLE)->insert($this->rawRowAttributes($firm, $account, $firmUser));

            return (int) DB::table(self::TABLE)->value('id');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FinancialEvidenceBankAccount $account, FirmUser $firmUser): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'bank_account_id' => $account->id,
            'requested_classification' => 'trust_iolta',
            'previous_classification' => 'operating',
            'requested_by_firm_user_id' => $firmUser->id,
            'requested_at' => now(),
            'reason' => 'Account is now used exclusively for client trust funds.',
            'status' => 'pending',
            'correlation_uuid' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
