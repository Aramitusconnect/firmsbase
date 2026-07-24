<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustTransferRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Models\TrustTransferRequest;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TrustTransferRequestsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for trust_transfer_requests (database/
 * migrations/2026_08_30_980010_prepare_row_level_security_and_force_rls_on_trust_transfer_requests_table.php)
 * is permanently active and behaves correctly.
 *
 * Tenth and last of Wave 10's ten-table batch — deliberately forced
 * last since apply() has the highest cross-table blast radius in the
 * domain (reaches into payments/invoices outside this batch). See
 * TrustTransferRequestServiceTest for the dedicated service-level
 * coverage of apply()'s collapsed decoy-wrap.
 */
class TrustTransferRequestsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980010_prepare_row_level_security_and_force_rls_on_trust_transfer_requests_table.php';

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

    public function test_trust_transfer_requests_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_transfer_requests', $coverage->forcedTables());
    }

    /**
     * This is the LAST table of the batch to land — the exact-count
     * proof here is the strongest one in the whole batch: every one of
     * the other 9 tables must ALSO already be forced by the time this
     * migration runs.
     */
    public function test_exact_forced_table_count_has_no_duplicate_collisions_and_includes_the_whole_batch(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()), 'forcedTables() count must equal the number of FORCE-activation migration files on disk exactly.');

        foreach (self::THIS_BATCH as $table) {
            $this->assertContains($table, $coverage->forcedTables(), "{$table} must be present in forcedTables() once the whole Wave 10 batch has landed.");
        }
    }

    public function test_trust_transfer_requests_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_transfer_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_transfer_requests_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_transfer_requests'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_transfer_requests'::regclass and polname = 'trust_transfer_requests_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_missing_tenant_context_cannot_read_trust_transfer_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->createTransferRequestForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustTransferRequest::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_transfer_requests(): void
    {
        $firm = Firm::factory()->create();
        [$ledger, $matter, $invoice, $requestedBy] = $this->createFixturesForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_transfer_requests')->insert($this->rowAttributes($firm, $ledger, $matter, $invoice, $requestedBy));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $request = TrustTransferRequest::factory()->create();

        $this->assertNotNull($request->id);

        $persisted = $this->runWithFirmContext($request->firm_id, fn () => TrustTransferRequest::query()->find($request->id));

        $this->assertNotNull($persisted);
        $this->assertSame($request->firm_id, $persisted->firm_id);
    }

    public function test_firm_a_context_can_read_its_own_trust_transfer_request(): void
    {
        $firmA = Firm::factory()->create();
        $requestA = $this->createTransferRequestForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustTransferRequest::query()->pluck('id')->all());

        $this->assertSame([$requestA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_trust_transfer_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createTransferRequestForFirm($firmA);
        $requestB = $this->createTransferRequestForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustTransferRequest::query()->pluck('id')->all());

        $this->assertNotContains($requestB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_transfer_request(): void
    {
        $firmA = Firm::factory()->create();
        [$ledger, $matter, $invoice, $requestedBy] = $this->createFixturesForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('trust_transfer_requests')->insertGetId($this->rowAttributes($firmA, $ledger, $matter, $invoice, $requestedBy)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_trust_transfer_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createTransferRequestForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($requestB) {
            return DB::table('trust_transfer_requests')->where('id', $requestB->id)->update(['status' => TrustTransferRequestStatus::Approved->value]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustTransferRequest::query()->find($requestB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TrustTransferRequestStatus::Requested, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_transfer_request(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestB = $this->createTransferRequestForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($requestB) {
            DB::table('trust_transfer_requests')->where('id', $requestB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustTransferRequest::query()->find($requestB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_trust_transfer_request_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$ledger, $matter, $invoice, $requestedBy] = $this->createFixturesForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $ledger, $matter, $invoice, $requestedBy) {
            DB::table('trust_transfer_requests')->insert($this->rowAttributes($firmB, $ledger, $matter, $invoice, $requestedBy));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->createTransferRequestForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($requestA, $firmB) {
            DB::table('trust_transfer_requests')->where('id', $requestA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        [$ledger, $matter, $invoice, $requestedBy] = $this->createFixturesForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => TrustTransferRequest::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'invoice_id' => $invoice->id,
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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_transfer_requests'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'trust_transfer_requests'::regclass and polname = 'trust_transfer_requests_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_transfer_requests'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_trust_transfer_requests(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['trust_transfer_requests'])), 0, 5);

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

    private function createLedgerForFirm(Firm $firm, Client $client): TrustLedger
    {
        $account = $this->createAccountForFirm($firm);

        return $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
        ]));
    }

    /**
     * @return array{0: TrustLedger, 1: Matter, 2: Invoice, 3: FirmUser}
     */
    private function createFixturesForFirm(Firm $firm): array
    {
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->createLedgerForFirm($firm, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $invoice = Invoice::factory()->forClient($client)->create();
        $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

        return [$ledger, $matter, $invoice, $requestedBy];
    }

    private function createTransferRequestForFirm(Firm $firm): TrustTransferRequest
    {
        [$ledger, $matter, $invoice, $requestedBy] = $this->createFixturesForFirm($firm);

        return $this->runWithFirmContext($firm, fn () => TrustTransferRequest::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'invoice_id' => $invoice->id,
            'requested_by_firm_user_id' => $requestedBy->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, TrustLedger $ledger, Matter $matter, Invoice $invoice, FirmUser $requestedBy): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => 5000,
            'status' => TrustTransferRequestStatus::Requested->value,
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
