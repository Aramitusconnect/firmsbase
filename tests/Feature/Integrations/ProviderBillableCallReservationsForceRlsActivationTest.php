<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
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
 * ProviderBillableCallReservationsForceRlsActivationTest — FirmsVault
 * Live Integrations, Checkpoint 4 ("Plaid financial evidence add-on",
 * cost-control/billing track). `provider_billable_call_reservations` is
 * Direct `BelongsToTenant` + FORCE RLS
 * (database/migrations/2026_09_24_500003_prepare_row_level_security_and_force_rls_on_provider_billable_call_reservations_table.php),
 * mirroring `integration_usage_records`'s own canonical shape (composite
 * FK to `firm_integrations(firm_id, id)`, symmetric FOR ALL policy).
 * Required by `SchemaTenantFirewallTest::test_check_5`
 * (RowLevelSecurityCoverageMappingService::forcedTables() lists this
 * table by name).
 */
class ProviderBillableCallReservationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_NAME = 'provider_billable_call_reservations_tenant_isolation';

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_provider_billable_call_reservations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('provider_billable_call_reservations'));
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'provider_billable_call_reservations'::regclass and contype = 'f'
             order by conname"
        );

        $composite = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2 && $row->foreign_table === 'firm_integrations'));

        $this->assertCount(1, $composite);
    }

    public function test_idempotency_unique_constraint_leads_with_firm_integration_id(): void
    {
        $rows = DB::select(
            "select conname, pg_get_constraintdef(oid) as def
             from pg_constraint
             where conrelid = 'provider_billable_call_reservations'::regclass and contype = 'u'"
        );

        $matching = array_values(array_filter($rows, fn ($row) => str_contains($row->def, 'firm_integration_id') && str_contains($row->def, 'idempotency_key')));
        $this->assertCount(1, $matching);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_provider_billable_call_reservations_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'provider_billable_call_reservations'");
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_provider_billable_call_reservations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'provider_billable_call_reservations'");
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_runtime_database_role_has_no_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls);
    }

    public function test_provider_billable_call_reservations_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'provider_billable_call_reservations'");
        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'provider_billable_call_reservations'::regclass and polname = ?",
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

    public function test_firm_a_context_can_read_its_own_reservation(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $reservation = $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('provider_billable_call_reservations')->pluck('id')->all());

        $this->assertSame([$reservation->id], $visibleIds);
    }

    public function test_firm_a_context_can_insert_its_own_reservation_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('provider_billable_call_reservations')->insert($this->rawRowAttributes($firm, $connection));
        });

        $count = $this->runWithFirmContext($firm, fn () => DB::table('provider_billable_call_reservations')->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_firm_a_context_can_update_its_own_reservation_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $reservation = $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('provider_billable_call_reservations')
            ->where('id', $reservation->id)
            ->update(['status' => ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE]));

        $this->assertSame(1, $affected);
    }

    public function test_firm_a_context_can_delete_its_own_reservation_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $reservation = $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('provider_billable_call_reservations')
            ->where('id', $reservation->id)
            ->delete());

        $this->assertSame(1, $affected);
    }

    // ------------------------------------------------------------
    // 4. Cross-firm SELECT/INSERT/UPDATE/DELETE denied
    // ------------------------------------------------------------

    public function test_firm_a_context_cannot_read_firm_bs_reservation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());
        $reservationB = $this->createWithFirmContext($firmB, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firmB, $connectionB)));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('provider_billable_call_reservations')->pluck('id')->all());

        $this->assertNotContains($reservationB->id, $visibleIds);
        $this->assertSame([], $visibleIds);
    }

    public function test_firm_a_context_cannot_insert_a_reservation_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('provider_billable_call_reservations')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_bs_reservation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());
        $reservationB = $this->createWithFirmContext($firmB, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firmB, $connectionB)));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('provider_billable_call_reservations')
            ->where('id', $reservationB->id)
            ->update(['status' => 'hacked']));

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('provider_billable_call_reservations')
            ->where('id', $reservationB->id)
            ->value('status'));
        $this->assertNotSame('hacked', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_bs_reservation(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->createWithFirmContext($firmB, fn () => FirmIntegration::factory()->forFirm($firmB)->create());
        $reservationB = $this->createWithFirmContext($firmB, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firmB, $connectionB)));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('provider_billable_call_reservations')
            ->where('id', $reservationB->id)
            ->delete());

        $this->assertSame(0, $affected);

        $stillExists = $this->runWithFirmContext($firmB, fn () => DB::table('provider_billable_call_reservations')
            ->where('id', $reservationB->id)
            ->exists());
        $this->assertTrue($stillExists);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = $this->createWithFirmContext($firmA, fn () => FirmIntegration::factory()->forFirm($firmA)->create());
        $reservationA = $this->createWithFirmContext($firmA, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firmA, $connectionA)));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($reservationA, $firmB) {
            DB::table('provider_billable_call_reservations')->where('id', $reservationA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ------------------------------------------------------------
    // 5. No-context denied
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_provider_billable_call_reservations(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('provider_billable_call_reservations')->count());
    }

    public function test_missing_tenant_context_cannot_insert_provider_billable_call_reservations(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('provider_billable_call_reservations')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_missing_tenant_context_cannot_update_provider_billable_call_reservations(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $reservation = $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('provider_billable_call_reservations')->where('id', $reservation->id)->update(['status' => 'no-context']);
        $this->assertSame(0, $affected);
    }

    public function test_missing_tenant_context_cannot_delete_provider_billable_call_reservations(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $reservation = $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('provider_billable_call_reservations')->where('id', $reservation->id)->delete();
        $this->assertSame(0, $affected);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->createWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->create($this->rowAttributes($firm, $connection)));

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

    public function test_model_table_resolves_to_provider_billable_call_reservations(): void
    {
        $model = new ProviderBillableCallReservation;
        $this->assertSame('provider_billable_call_reservations', $model->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(ProviderBillableCallReservation::class);
        $this->assertArrayHasKey(BelongsToTenant::class, $traits);
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new ProviderBillableCallReservation;
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
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'billing_operation' => 'download',
            'environment' => 'production',
            'quantity' => 1,
            'unit' => 'request',
            'status' => ProviderBillableCallReservation::STATUS_RESERVED,
            'idempotency_key' => (string) Str::uuid7(),
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection): array
    {
        return array_merge($this->rowAttributes($firm, $connection), [
            'uuid' => (string) Str::uuid7(),
            'idempotency_key' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
