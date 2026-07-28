<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderFirmOperationPolicy;
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
 * ProviderFirmOperationPoliciesForceRlsActivationTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on",
 * cost-control/billing track). `provider_firm_operation_policies` is
 * Direct `BelongsToTenant` + FORCE RLS
 * (database/migrations/2026_09_24_500007_prepare_row_level_security_and_force_rls_on_provider_firm_operation_policies_table.php)
 * — the firm-EDITABLE half of checkpoint4-combined-design.md §1.8's
 * coordinator-resolved two-table split (its sibling,
 * `provider_operation_default_policies`, is Global/no-RLS and needs no
 * such test — confirmed against
 * `RowLevelSecurityCoverageMappingService::EXEMPT_TABLES`). Required by
 * `SchemaTenantFirewallTest::test_check_5`.
 */
class ProviderFirmOperationPoliciesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_NAME = 'provider_firm_operation_policies_tenant_isolation';

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_provider_firm_operation_policies_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('provider_firm_operation_policies'));
    }

    public function test_unique_scope_constraint_covers_firm_provider_product_environment(): void
    {
        $row = DB::selectOne(
            "select pg_get_constraintdef(oid) as def
             from pg_constraint
             where conrelid = 'provider_firm_operation_policies'::regclass
               and contype = 'u'
               and conname = 'provider_firm_operation_policies_unique_scope'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('firm_id', $row->def);
        $this->assertStringContainsString('provider_key', $row->def);
        $this->assertStringContainsString('product', $row->def);
        $this->assertStringContainsString('environment', $row->def);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_provider_firm_operation_policies_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'provider_firm_operation_policies'");
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_provider_firm_operation_policies_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'provider_firm_operation_policies'");
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_runtime_database_role_has_no_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls);
    }

    public function test_provider_firm_operation_policies_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'provider_firm_operation_policies'");
        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'provider_firm_operation_policies'::regclass and polname = ?",
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

    public function test_firm_a_context_can_read_its_own_policy_row(): void
    {
        $firm = Firm::factory()->create();
        $policy = $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('provider_firm_operation_policies')->pluck('id')->all());

        $this->assertSame([$policy->id], $visibleIds);
    }

    public function test_firm_a_context_can_insert_its_own_policy_row_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('provider_firm_operation_policies')->insert($this->rawRowAttributes($firm));
        });

        $count = $this->runWithFirmContext($firm, fn () => DB::table('provider_firm_operation_policies')->where('firm_id', $firm->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_firm_a_context_can_update_its_own_policy_row_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $policy = $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('provider_firm_operation_policies')
            ->where('id', $policy->id)
            ->update(['optional_operation_suspended' => true]));

        $this->assertSame(1, $affected);
    }

    public function test_firm_a_context_can_delete_its_own_policy_row_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $policy = $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('provider_firm_operation_policies')
            ->where('id', $policy->id)
            ->delete());

        $this->assertSame(1, $affected);
    }

    // ------------------------------------------------------------
    // 4. Cross-firm SELECT/INSERT/UPDATE/DELETE denied
    // ------------------------------------------------------------

    public function test_firm_a_context_cannot_read_firm_bs_policy_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $policyB = $this->createWithFirmContext($firmB, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firmB)));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('provider_firm_operation_policies')->pluck('id')->all());

        $this->assertNotContains($policyB->id, $visibleIds);
        $this->assertSame([], $visibleIds);
    }

    public function test_firm_a_context_cannot_insert_a_policy_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('provider_firm_operation_policies')->insert($this->rawRowAttributes($firmB));
        });
    }

    public function test_firm_a_cannot_update_firm_bs_policy_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $policyB = $this->createWithFirmContext($firmB, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firmB)));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('provider_firm_operation_policies')
            ->where('id', $policyB->id)
            ->update(['optional_operation_suspended' => true]));

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('provider_firm_operation_policies')
            ->where('id', $policyB->id)
            ->value('optional_operation_suspended'));
        $this->assertFalse((bool) $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_bs_policy_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $policyB = $this->createWithFirmContext($firmB, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firmB)));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('provider_firm_operation_policies')
            ->where('id', $policyB->id)
            ->delete());

        $this->assertSame(0, $affected);

        $stillExists = $this->runWithFirmContext($firmB, fn () => DB::table('provider_firm_operation_policies')
            ->where('id', $policyB->id)
            ->exists());
        $this->assertTrue($stillExists);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $policyA = $this->createWithFirmContext($firmA, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firmA)));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($policyA, $firmB) {
            DB::table('provider_firm_operation_policies')->where('id', $policyA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ------------------------------------------------------------
    // 5. No-context denied
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_provider_firm_operation_policies(): void
    {
        $firm = Firm::factory()->create();
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('provider_firm_operation_policies')->count());
    }

    public function test_missing_tenant_context_cannot_insert_provider_firm_operation_policies(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('provider_firm_operation_policies')->insert($this->rawRowAttributes($firm));
    }

    public function test_missing_tenant_context_cannot_update_provider_firm_operation_policies(): void
    {
        $firm = Firm::factory()->create();
        $policy = $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('provider_firm_operation_policies')->where('id', $policy->id)->update(['optional_operation_suspended' => true]);
        $this->assertSame(0, $affected);
    }

    public function test_missing_tenant_context_cannot_delete_provider_firm_operation_policies(): void
    {
        $firm = Firm::factory()->create();
        $policy = $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

        (new TenantContextService)->clearDatabaseTenantContext();

        $affected = DB::table('provider_firm_operation_policies')->where('id', $policy->id)->delete();
        $this->assertSame(0, $affected);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create($this->rowAttributes($firm)));

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

    public function test_model_table_resolves_to_provider_firm_operation_policies(): void
    {
        $model = new ProviderFirmOperationPolicy;
        $this->assertSame('provider_firm_operation_policies', $model->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(ProviderFirmOperationPolicy::class);
        $this->assertArrayHasKey(BelongsToTenant::class, $traits);
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new ProviderFirmOperationPolicy;
        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm): array
    {
        return [
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'optional_operation_suspended' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm): array
    {
        return array_merge($this->rowAttributes($firm), [
            'uuid' => (string) Str::uuid7(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
