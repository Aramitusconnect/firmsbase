<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConnectionHealth;
use App\Integrations\Services\HealthStateService;
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
 * IntegrationConnectionHealthForceRlsActivationTest — Checkpoint 8
 * (agent-8f-health-state-design.md §1/§6;
 * agent-8h-architecture-security-review.md §1 item 6/§4.2). Same-firm
 * CRUD; cross-firm SELECT/INSERT/UPDATE/DELETE denied; no-context
 * denied; FORCE RLS confirmed live via pg_policies/pg_class; exact
 * policy text matches integration_inbound_webhook_events' canonical
 * shape — mirrors
 * IntegrationInboundWebhookEventsForceRlsActivationTest's exact
 * structural conventions.
 */
class IntegrationConnectionHealthForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY_NAME = 'integration_connection_health_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'uuid', 'firm_id', 'firm_integration_id', 'summary_state',
        'last_success_at', 'last_failure_at', 'consecutive_failures',
        'last_failure_category', 'rate_limited_reset_at', 'next_retry_at',
        'sanitized_diagnostic_summary', 'last_checked_at', 'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_integration_connection_health_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_connection_health'));
    }

    public function test_integration_connection_health_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_connection_health');
        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_connection_health'::regclass and contype = 'f'
             order by conname"
        );

        $composite = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2));

        $this->assertCount(1, $composite);
        $this->assertSame('firm_integrations', $composite[0]->foreign_table);
    }

    public function test_firm_integration_id_is_unique_enforcing_strict_one_to_one(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_connection_health')->insert($this->rawRowAttributes($firm, $connection));
        });
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_integration_connection_health_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_connection_health'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_integration_connection_health_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_connection_health'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_runtime_database_role_has_no_bypassrls(): void
    {
        $row = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls);
    }

    public function test_integration_connection_health_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_connection_health'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_connection_health'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_the_policy_text_matches_integration_inbound_webhook_events_canonical_shape_exactly(): void
    {
        $health = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_connection_health'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $webhookEvents = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_inbound_webhook_events'::regclass and polname = 'integration_inbound_webhook_events_tenant_isolation'"
        );

        $this->assertNotNull($webhookEvents, 'The canonical reference table\'s policy must exist for this comparison to mean anything.');
        $this->assertSame($webhookEvents->using_expr, $health->using_expr);
        $this->assertSame($webhookEvents->with_check_expr, $health->with_check_expr);
    }

    public function test_no_security_definer_functions_and_no_policy_references_credential_type(): void
    {
        $securityDefinerRows = DB::select(
            "select proname from pg_proc p
             join pg_namespace n on n.oid = p.pronamespace
             where p.prosecdef = true and n.nspname not in ('pg_catalog', 'information_schema')"
        );
        $this->assertCount(0, $securityDefinerRows);

        $credentialTypeRows = DB::select(
            "select polname from pg_policy
             where pg_get_expr(polqual, polrelid) ilike '%credential_type%'
                or pg_get_expr(polwithcheck, polrelid) ilike '%credential_type%'"
        );
        $this->assertCount(0, $credentialTypeRows);
    }

    // ------------------------------------------------------------
    // 3. Same-firm CRUD
    // ------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_health_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->pluck('firm_integration_id')->all());

        $this->assertSame([$connection->id], $visibleIds);
    }

    public function test_firm_a_context_can_insert_its_own_health_row_via_raw_sql(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_connection_health')->insert($this->rawRowAttributes($firm, $connection));
        });

        $count = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_firm_a_context_can_update_its_own_health_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connection->id)
            ->update(['sanitized_diagnostic_summary' => 'own-update-ok']));

        $this->assertSame(1, $affected);
    }

    public function test_firm_a_context_can_delete_its_own_health_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        $affected = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connection->id)
            ->delete());

        $this->assertSame(1, $affected);
    }

    // ------------------------------------------------------------
    // 4. Cross-firm SELECT/INSERT/UPDATE/DELETE denied
    // ------------------------------------------------------------

    public function test_firm_a_context_cannot_read_firm_bs_health_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $this->runWithFirmContext($firmB, fn () => (new HealthStateService())->recordSuccess($connectionB->id, $firmB->id));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_connection_health')->pluck('firm_integration_id')->all());

        $this->assertNotContains($connectionB->id, $visibleIds);
        $this->assertSame([], $visibleIds);
    }

    public function test_firm_a_context_cannot_insert_a_health_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('integration_connection_health')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_bs_health_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $this->runWithFirmContext($firmB, fn () => (new HealthStateService())->recordSuccess($connectionB->id, $firmB->id));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connectionB->id)
            ->update(['sanitized_diagnostic_summary' => 'hacked']));

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connectionB->id)
            ->value('sanitized_diagnostic_summary'));
        $this->assertNotSame('hacked', $reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_bs_health_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();
        $this->runWithFirmContext($firmB, fn () => (new HealthStateService())->recordSuccess($connectionB->id, $firmB->id));

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connectionB->id)
            ->delete());

        $this->assertSame(0, $affected);

        $stillExists = $this->runWithFirmContext($firmB, fn () => DB::table('integration_connection_health')
            ->where('firm_integration_id', $connectionB->id)
            ->exists());
        $this->assertTrue($stillExists);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $this->runWithFirmContext($firmA, fn () => (new HealthStateService())->recordSuccess($connectionA->id, $firmA->id));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($connectionA, $firmB) {
            DB::table('integration_connection_health')->where('firm_integration_id', $connectionA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ------------------------------------------------------------
    // 5. No-context denied
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_integration_connection_health(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_connection_health')->count());
    }

    public function test_missing_tenant_context_cannot_insert_integration_connection_health(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_connection_health')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_missing_tenant_context_cannot_update_integration_connection_health(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        (new TenantContextService())->clearDatabaseTenantContext();

        $affected = DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->update(['sanitized_diagnostic_summary' => 'no-context']);
        $this->assertSame(0, $affected);
    }

    public function test_missing_tenant_context_cannot_delete_integration_connection_health(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

        (new TenantContextService())->clearDatabaseTenantContext();

        $affected = DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->delete();
        $this->assertSame(0, $affected);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => (new HealthStateService())->recordSuccess($connection->id, $firm->id));

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

    public function test_model_table_resolves_to_integration_connection_health(): void
    {
        $model = new IntegrationConnectionHealth();

        $this->assertSame('integration_connection_health', $model->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(IntegrationConnectionHealth::class);

        $this->assertArrayHasKey(BelongsToTenant::class, $traits);
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new IntegrationConnectionHealth();

        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
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
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'summary_state' => 'healthy',
            'consecutive_failures' => 0,
            'next_retry_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
