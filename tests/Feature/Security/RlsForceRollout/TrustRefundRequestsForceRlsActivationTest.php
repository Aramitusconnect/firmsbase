<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustRefundRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Models\TrustRefundRequest;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TrustRefundRequestsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for trust_refund_requests (database/
 * migrations/2026_08_30_980009_prepare_row_level_security_and_force_rls_on_trust_refund_requests_table.php)
 * is permanently active and behaves correctly.
 *
 * Ninth of Wave 10's ten-table batch. complete() is the decoy-wrap
 * method (§1b of the approved design) — see
 * TrustRefundRequestServiceTest for its own service-level coverage.
 */
class TrustRefundRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980009_prepare_row_level_security_and_force_rls_on_trust_refund_requests_table.php';

    private const THIS_BATCH = [
        'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
        'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
    ];

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_trust_refund_requests_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_refund_requests', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_trust_refund_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_refund_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_refund_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_refund_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_refund_requests'::regclass and polname = 'trust_refund_requests_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_missing_tenant_context_cannot_read_trust_refund_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->createRefundRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustRefundRequest::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_refund_requests(): void
    {
        $firm = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firm);
        $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_refund_requests')->insert($this->rowAttributes($firm, $ledger, $requestedBy));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $request = TrustRefundRequest::factory()->create();

        $this->assertNotNull($request->id);

        $persisted = $this->runWithFirmContext($request->firm_id, fn () => TrustRefundRequest::query()->find($request->id));

        $this->assertNotNull($persisted);
        $this->assertSame($request->firm_id, $persisted->firm_id);
    }

    public function test_firm_a_context_can_read_its_own_trust_refund_request(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->createRefundRequestForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustRefundRequest::query()->pluck('id')->all());

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_trust_refund_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createRefundRequestForFirm($firmA);
        $requestB = $this->createRefundRequestForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustRefundRequest::query()->pluck('id')->all());

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_refund_request(): void
    {
        $firmA = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firmA);
        $requestedBy = FirmUser::factory()->create(['firm_id' => $firmA->id]);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('trust_refund_requests')->insertGetId($this->rowAttributes($firmA, $ledger, $requestedBy)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_trust_refund_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRefundRequestForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($requestB) {
            return DB::table('trust_refund_requests')->where('id', $requestB->id)->update(['status' => TrustRefundRequestStatus::Approved->value]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustRefundRequest::query()->find($requestB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TrustRefundRequestStatus::Requested, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_refund_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createRefundRequestForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('trust_refund_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustRefundRequest::query()->find($requestB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_trust_refund_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firmB);
        $requestedBy = FirmUser::factory()->create(['firm_id' => $firmB->id]);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $ledger, $requestedBy) {
            DB::table('trust_refund_requests')->insert($this->rowAttributes($firmB, $ledger, $requestedBy));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->createRefundRequestForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($requestA, $firmB) {
            DB::table('trust_refund_requests')->where('id', $requestA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $ledger = $this->createLedgerForFirm($firm);
        $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => TrustRefundRequest::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'requested_by_firm_user_id' => $requestedBy->id,
        ]));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_refund_requests'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'trust_refund_requests'::regclass and polname = 'trust_refund_requests_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_refund_requests'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_trust_refund_requests(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['trust_refund_requests'])), 0, 5);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertEquals($before[$table], $after);
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, self::THIS_BATCH, true)) {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse((bool) $row->relrowsecurity, "{$table} must not gain RLS from this checkpoint.");
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php'));
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php'));
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $this->assertEmpty($this->changedOrUntrackedPaths($relativeDir));
        }
    }

    private function createAccountForFirm(Firm $firm): TrustAccount
    {
        return $this->runWithFirmContext($firm, fn () => TrustAccount::factory()->create(['firm_id' => $firm->id]));
    }

    private function createLedgerForFirm(Firm $firm): TrustLedger
    {
        $account = $this->createAccountForFirm($firm);
        $client = Client::factory()->forFirm($firm)->create();

        return $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
        ]));
    }

    private function createRefundRequestForFirm(Firm $firm): TrustRefundRequest
    {
        $ledger = $this->createLedgerForFirm($firm);
        $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        return $this->runWithFirmContext($firm, fn () => TrustRefundRequest::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'requested_by_firm_user_id' => $requestedBy->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, TrustLedger $ledger, FirmUser $requestedBy): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'amount_cents' => 2500,
            'status' => TrustRefundRequestStatus::Requested->value,
            'requested_by_firm_user_id' => $requestedBy->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
