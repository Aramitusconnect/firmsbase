<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBalanceSnapshot;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ProviderBalanceSnapshotsForceRlsActivationTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on",
 * cost-control/billing track). `provider_balance_snapshots` is Direct
 * `BelongsToTenant` + FORCE RLS
 * (database/migrations/2026_09_24_500009_prepare_row_level_security_and_force_rls_on_provider_balance_snapshots_table.php),
 * resolved with confidence (not flagged as open) in
 * checkpoint4-combined-design.md §1.9 — every row is unambiguously
 * firm-owned, no platform-default/global-row shape is ever
 * contemplated. Required by `SchemaTenantFirewallTest::test_check_5`.
 */
class ProviderBalanceSnapshotsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_NAME = 'provider_balance_snapshots_tenant_isolation';

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_provider_balance_snapshots_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('provider_balance_snapshots'));
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'provider_balance_snapshots'::regclass and contype = 'f'
             order by conname"
        );

        $composite = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2 && $row->foreign_table === 'firm_integrations'));

        $this->assertCount(1, $composite);
    }

    public function test_unique_constraint_on_firm_integration_id_and_account_id_exists(): void
    {
        $rows = DB::select(
            "select pg_get_constraintdef(oid) as def
             from pg_constraint
             where conrelid = 'provider_balance_snapshots'::regclass and contype = 'u'"
        );

        $matching = array_values(array_filter($rows, fn ($row) => str_contains($row->def, 'firm_integration_id') && str_contains($row->def, 'account_id')));
        $this->assertCount(1, $matching);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_provider_balance_snapshots_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'provider_balance_snapshots'");
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_provider_balance_snapshots_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'provider_balance_snapshots'");
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_runtime_database_role_has_no_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls);
    }

    public function test_provider_balance_snapshots_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'provider_balance_snapshots'");
        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'provider_balance_snapshots'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $this->assertNotNull($row);
        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";
        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    // ------------------------------------------------------------
    // 3. Same-firm CRUD (raw SQL)
    // ------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_snapshot(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $snapshot = $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('provider_balance_snapshots')->pluck('id')->all());

        $this->assertSame([$snapshot->id], $visibleIds);
    }

    public function test_firm_a_context_can_insert_its_own_snapshot_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('provider_balance_snapshots')->insert($this->rawRowAttributes($firm, $connection));
        });

        $count = $this->runWithFirmContext($firm, fn () => DB::table('provider_balance_snapshots')->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_firm_a_context_can_update_its_own_snapshot_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $snapshot = $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('provider_balance_snapshots')
            ->where('id', $snapshot->id)
            ->update(['available_cents' => 999]));

        $this->assertSame(1, $affected);
    }

    public function test_firm_a_context_can_delete_its_own_snapshot_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $snapshot = $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('provider_balance_snapshots')
            ->where('id', $snapshot->id)
            ->delete());

        $this->assertSame(1, $affected);
    }

    // ------------------------------------------------------------
    // 4. Cross-firm SELECT/INSERT/UPDATE/DELETE denied
    // ------------------------------------------------------------

    public function test_firm_a_context_cannot_read_firm_bs_snapshot(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());
        $snapshotB = $this->createWithFirmContext($firmB, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firmB, $connectionB)));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('provider_balance_snapshots')->pluck('id')->all());

        $this->assertNotContains($snapshotB->id, $visibleIds);
        $this->assertSame([], $visibleIds);
    }

    public function test_firm_a_context_cannot_insert_a_snapshot_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('provider_balance_snapshots')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_bs_snapshot(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());
        $snapshotB = $this->createWithFirmContext($firmB, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firmB, $connectionB)));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('provider_balance_snapshots')
            ->where('id', $snapshotB->id)
            ->update(['available_cents' => 1]));

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('provider_balance_snapshots')
            ->where('id', $snapshotB->id)
            ->value('available_cents'));
        $this->assertNotSame(1, $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_bs_snapshot(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());
        $snapshotB = $this->createWithFirmContext($firmB, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firmB, $connectionB)));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('provider_balance_snapshots')
            ->where('id', $snapshotB->id)
            ->delete());

        $this->assertSame(0, $affected);

        $stillExists = $this->runWithFirmContext($firmB, fn () => DB::table('provider_balance_snapshots')
            ->where('id', $snapshotB->id)
            ->exists());
        $this->assertTrue($stillExists);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = $this->createWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create());
        $snapshotA = $this->createWithFirmContext($firmA, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firmA, $connectionA)));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($snapshotA, $firmB) {
            DB::table('provider_balance_snapshots')->where('id', $snapshotA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ------------------------------------------------------------
    // 5. No-context denied
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_provider_balance_snapshots(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('provider_balance_snapshots')->count());
    }

    public function test_missing_tenant_context_cannot_insert_provider_balance_snapshots(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('provider_balance_snapshots')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_missing_tenant_context_cannot_update_provider_balance_snapshots(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $snapshot = $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('provider_balance_snapshots')->where('id', $snapshot->id)->update(['available_cents' => 1]);
        $this->assertSame(0, $affected);
    }

    public function test_missing_tenant_context_cannot_delete_provider_balance_snapshots(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $snapshot = $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('provider_balance_snapshots')->where('id', $snapshot->id)->delete();
        $this->assertSame(0, $affected);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->createWithFirmContext($firm, fn () => ProviderBalanceSnapshot::query()->create($this->rowAttributes($firm, $connection)));

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
    // 6. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_provider_balance_snapshots(): void
    {
        $model = new ProviderBalanceSnapshot;
        $this->assertSame('provider_balance_snapshots', $model->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(ProviderBalanceSnapshot::class);
        $this->assertArrayHasKey(BelongsToTenant::class, $traits);
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new ProviderBalanceSnapshot;
        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, FirmIntegration $connection): array
    {
        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'account_id' => 'account-'.Str::random(8),
            'available_cents' => 15000,
            'current_cents' => 15500,
            'iso_currency_code' => 'usd',
            'retrieved_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection): array
    {
        return array_merge($this->rowAttributes($firm, $connection), [
            'uuid' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
