<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Policies\FirmIntegrationPolicy;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmIntegrationTest — Checkpoint 3 (per-firm connection instance to a
 * registered provider), checkpoint-00-final-specification.md §5 table
 * #2; domain-model-and-rls-classification.md §2.
 *
 * Unlike Checkpoint 2's integration_providers (Global, no RLS),
 * firm_integrations is direct firm-owned and carries permanent FORCE
 * ROW LEVEL SECURITY from the moment its two migrations land (mirrors
 * this repository's Wave 11 webhook_subscriptions precedent) — so this
 * class proves RLS IS on, proves cross-firm isolation, and proves the
 * connected_by_firm_user_id compensating control documented in
 * app/Integrations/Models/FirmIntegration.php and
 * checkpoint-03-security-review.md's ADDENDUM.
 */
class FirmIntegrationsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_02_020001_create_firm_integrations_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_02_020001_create_firm_integrations_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table';

    private const POLICY_NAME = 'firm_integrations_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id',
        'uuid',
        'firm_id',
        'integration_provider_id',
        'external_account_id',
        'display_label',
        'status',
        'scopes_granted_json',
        'connected_by_firm_user_id',
        'connected_at',
        'disconnected_at',
        'last_health_check_at',
        'last_health_status',
        'error_reason',
        'webhook_routing_token',
        'created_at',
        'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_firm_integrations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('firm_integrations'));
    }

    public function test_firm_integrations_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('firm_integrations');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame(
            $expected,
            $columns,
            'firm_integrations must have exactly the documented column set — no more, no fewer.'
        );
    }

    public function test_firm_id_foreign_key_references_firms_id(): void
    {
        $row = $this->foreignKeyTarget('firm_id');

        $this->assertNotNull($row, 'firm_integrations.firm_id must have a FOREIGN KEY constraint.');
        $this->assertSame('firms', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_integration_provider_id_foreign_key_references_integration_providers_id(): void
    {
        $row = $this->foreignKeyTarget('integration_provider_id');

        $this->assertNotNull($row, 'firm_integrations.integration_provider_id must have a FOREIGN KEY constraint.');
        $this->assertSame('integration_providers', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_connected_by_firm_user_id_foreign_key_references_firm_users_id(): void
    {
        $row = $this->foreignKeyTarget('connected_by_firm_user_id');

        $this->assertNotNull($row, 'firm_integrations.connected_by_firm_user_id must have a FOREIGN KEY constraint.');
        $this->assertSame('firm_users', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_inserting_a_nonexistent_integration_provider_id_is_rejected_by_the_foreign_key(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('firm_integrations')->insert([
                'uuid' => (string) Str::uuid7(),
                'firm_id' => $firm->id,
                'integration_provider_id' => 999999999,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_unique_firm_id_and_id_composite_index_exists(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'firm_integrations' and indexname = 'firm_integrations_firm_id_id_unique'"
        );

        $this->assertNotNull($row, 'UNIQUE(firm_id, id) must exist on firm_integrations for future Checkpoint 4+ composite FK parents.');
        $this->assertStringContainsString('UNIQUE INDEX', strtoupper($row->indexdef));
        $this->assertStringContainsString('firm_id', $row->indexdef);
    }

    public function test_webhook_routing_token_partial_unique_index_exists_and_is_partial(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'firm_integrations' and indexname = 'firm_integrations_webhook_routing_token_unique'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('UNIQUE INDEX', strtoupper($row->indexdef));
        $this->assertStringContainsString('WHERE (webhook_routing_token IS NOT NULL)', $row->indexdef);
    }

    public function test_firm_provider_external_account_partial_unique_index_exists_and_is_partial(): void
    {
        $row = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'firm_integrations' and indexname = 'firm_integrations_firm_provider_external_account_unique'"
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('UNIQUE INDEX', strtoupper($row->indexdef));
        $this->assertStringContainsString('firm_id', $row->indexdef);
        $this->assertStringContainsString('integration_provider_id', $row->indexdef);
        $this->assertStringContainsString('external_account_id', $row->indexdef);
        $this->assertStringContainsString('WHERE (external_account_id IS NOT NULL)', $row->indexdef);
    }

    public function test_duplicate_external_account_id_for_same_firm_and_provider_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $provider = $this->testProvider();

        FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
            'external_account_id' => 'duplicate-external-account',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
            'external_account_id' => 'duplicate-external-account',
        ]);
    }

    public function test_multiple_null_external_account_id_rows_for_same_firm_and_provider_are_allowed(): void
    {
        $firm = Firm::factory()->create();
        $provider = $this->testProvider();

        $first = FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
            'external_account_id' => null,
        ]);

        $second = FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'integration_provider_id' => $provider->id,
            'external_account_id' => null,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($first->external_account_id);
        $this->assertNull($second->external_account_id);
    }

    public function test_duplicate_webhook_routing_token_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        FirmIntegration::factory()->create([
            'firm_id' => $firmA->id,
            'webhook_routing_token' => 'shared-routing-token',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'webhook_routing_token' => 'shared-routing-token',
        ]);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_firm_integrations_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_integrations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity, 'firm_integrations is tenant-owned — RLS must be enabled.');
    }

    public function test_firm_integrations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_integrations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_integrations must have permanent FORCE ROW LEVEL SECURITY active, including against the table owner.');
    }

    public function test_firm_integrations_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'firm_integrations'");

        $this->assertCount(1, $rows, 'firm_integrations must have exactly one policy — no additional carve-out is justified at this checkpoint.');
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'firm_integrations'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $this->assertNotNull($row, 'The firm_integrations_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ------------------------------------------------------------
    // 3. Cross-firm tenant isolation
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_firm_integrations(): void
    {
        $firm = Firm::factory()->create();
        $this->createIntegrationForFirm($firm);

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, FirmIntegration::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_integrations(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_integrations')->insert($this->rawRowAttributes($firm));
    }

    public function test_firm_a_context_can_read_its_own_firm_integration(): void
    {
        $firmA = Firm::factory()->create();
        $integrationA = $this->createIntegrationForFirm($firmA);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmIntegration::query()->pluck('id')->all(),
        );

        $this->assertSame([$integrationA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_integration(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createIntegrationForFirm($firmA);
        $integrationB = $this->createIntegrationForFirm($firmB);

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmIntegration::query()->pluck('id')->all(),
        );

        $this->assertNotContains($integrationB->id, $visibleIds);
    }

    public function test_firm_a_cannot_update_firm_b_firm_integration(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $integrationB = $this->createIntegrationForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($integrationB) {
            return DB::table('firm_integrations')->where('id', $integrationB->id)->update(['display_label' => 'hacked-by-firm-a']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s firm_integrations row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmIntegration::query()->find($integrationB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNotSame('hacked-by-firm-a', $reReadAsFirmB->display_label);
    }

    public function test_firm_a_cannot_delete_firm_b_firm_integration(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $integrationB = $this->createIntegrationForFirm($firmB);

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('firm_integrations')->where('id', $integrationB->id)->delete(),
        );

        $this->assertSame(0, $affected, 'No rows should be visible/deletable — Firm A must not be able to delete Firm B\'s firm_integrations row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmIntegration::query()->find($integrationB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B firm_integrations.');
    }

    public function test_firm_a_cannot_insert_a_firm_integration_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('firm_integrations')->insert($this->rawRowAttributes($firmB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $integrationA = $this->createIntegrationForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($integrationA, $firmB) {
            DB::table('firm_integrations')->where('id', $integrationA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    /**
     * NOTE: this deliberately wraps the factory create() call in an
     * explicit runWithFirmContext() (rather than using the
     * createIntegrationForFirm() helper bare) because
     * FirmIntegrationFactory::create()'s own context-hold override
     * intentionally LEAVES the PostgreSQL session setting in place
     * afterward (mirrors FirmUserFactory's identical, documented
     * behavior) — it is runWithFirmContext()'s own restore-previous-
     * context-in-a-finally-block behavior that this test is actually
     * proving, not the factory's.
     */
    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

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
    // 4. connected_by_firm_user_id compensating control
    // ------------------------------------------------------------

    public function test_connected_by_firm_user_id_in_the_same_firm_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = FirmUser::factory()->forFirm($firm)->create();

        $integration = FirmIntegration::factory()->create([
            'firm_id' => $firm->id,
            'connected_by_firm_user_id' => $firmUser->id,
        ]);

        $this->assertNotNull($integration->id);
        $this->assertSame($firmUser->id, $integration->connected_by_firm_user_id);
    }

    public function test_connected_by_firm_user_id_belonging_to_a_different_firm_throws_on_create(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $firmAUser = FirmUser::factory()->forFirm($firmA)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/connected_by_firm_user_id must reference a firm_users row belonging to the same firm_id/');

        FirmIntegration::factory()->create([
            'firm_id' => $firmB->id,
            'connected_by_firm_user_id' => $firmAUser->id,
        ]);
    }

    public function test_connected_by_firm_user_id_belonging_to_a_different_firm_throws_on_update(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $firmAUser = FirmUser::factory()->forFirm($firmA)->create();
        $firmBUser = FirmUser::factory()->forFirm($firmB)->create();

        $integration = FirmIntegration::factory()->create([
            'firm_id' => $firmA->id,
            'connected_by_firm_user_id' => $firmAUser->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/connected_by_firm_user_id must reference a firm_users row belonging to the same firm_id/');

        $this->runWithFirmContext($firmA, function () use ($integration, $firmBUser) {
            $integration->connected_by_firm_user_id = $firmBUser->id;
            $integration->save();
        });
    }

    // ------------------------------------------------------------
    // 5. Policy authorization (FirmIntegrationPolicy, direct — no route exists yet)
    // ------------------------------------------------------------

    public function test_firm_owner_role_can_view_connect_and_configure(): void
    {
        $firm = Firm::factory()->create();
        $integration = $this->createIntegrationForFirm($firm);
        $user = $this->makeFirmUser($firm, FirmUserRole::FirmOwner);
        $policy = $this->firmIntegrationPolicy();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $integration));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->connect($user));
        $this->assertTrue($policy->update($user, $integration));
        $this->assertTrue($policy->configure($user, $integration));
        $this->assertTrue($policy->delete($user, $integration));
        $this->assertTrue($policy->disconnect($user, $integration));
    }

    public function test_attorney_role_can_view_connect_and_configure(): void
    {
        $firm = Firm::factory()->create();
        $integration = $this->createIntegrationForFirm($firm);
        $user = $this->makeFirmUser($firm, FirmUserRole::Attorney);
        $policy = $this->firmIntegrationPolicy();

        $this->assertTrue($policy->view($user, $integration));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, $integration));
    }

    public function test_receptionist_role_is_denied_every_action(): void
    {
        $firm = Firm::factory()->create();
        $integration = $this->createIntegrationForFirm($firm);
        $user = $this->makeFirmUser($firm, FirmUserRole::Receptionist);
        $policy = $this->firmIntegrationPolicy();

        $this->assertFalse($policy->viewAny($user), 'Receptionist must never appear in any integration allowlist — role ceilings may only be narrowed, never widened.');
        $this->assertFalse($policy->view($user, $integration));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->connect($user));
        $this->assertFalse($policy->update($user, $integration));
        $this->assertFalse($policy->configure($user, $integration));
        $this->assertFalse($policy->delete($user, $integration));
        $this->assertFalse($policy->disconnect($user, $integration));
    }

    public function test_firm_user_from_a_different_firm_is_denied_regardless_of_role(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $integrationA = $this->createIntegrationForFirm($firmA);

        // FirmOwner is the highest-ceiling role in this allowlist — even
        // this role must be denied when the actor's firm doesn't match
        // the target row's firm.
        $user = $this->makeFirmUser($firmB, FirmUserRole::FirmOwner);
        $policy = $this->firmIntegrationPolicy();

        $this->assertFalse($policy->view($user, $integrationA));
        $this->assertFalse($policy->update($user, $integrationA));
        $this->assertFalse($policy->configure($user, $integrationA));
        $this->assertFalse($policy->delete($user, $integrationA));
        $this->assertFalse($policy->disconnect($user, $integrationA));
    }

    // ------------------------------------------------------------
    // 6. Migration rollback and reapplication (two migrations, reverse order)
    // ------------------------------------------------------------

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));

        // 1. Confirm current state before touching anything.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertNotNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNotNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

        $forceRow = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertTrue((bool) $forceRow->relforcerowsecurity);

        // 2. Roll back in reverse order: RLS migration first, then the
        // table-creation migration (the RLS migration's down() assumes
        // the table still exists).
        $rlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => self::RLS_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $rlsRollbackExit, 'migrate:rollback (RLS migration) failed: '.Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity, 'Rolling back the RLS migration must fully disable RLS, not merely clear FORCE.');
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'firm_integrations'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback, 'Rolling back the RLS migration must drop the policy it created.');

        $tableRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => self::TABLE_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $tableRollbackExit, 'migrate:rollback (table migration) failed: '.Artisan::output());

        // 3. The table must be fully gone from the PostgreSQL catalog.
        $this->assertFalse(Schema::hasTable('firm_integrations'));
        $this->assertNull(DB::selectOne("select relname from pg_class where relname = 'firm_integrations'"));
        $this->assertNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

        // 4. Reapply in forward order.
        $tableMigrateExit = Artisan::call('migrate', [
            '--path' => self::TABLE_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $tableMigrateExit, 'migrate (table migration) failed: '.Artisan::output());

        $rlsMigrateExit = Artisan::call('migrate', [
            '--path' => self::RLS_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $rlsMigrateExit, 'migrate (RLS migration) failed: '.Artisan::output());

        // 5. Schema restored exactly.
        $this->assertTrue(Schema::hasTable('firm_integrations'));

        $columns = Schema::getColumnListing('firm_integrations');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $webhookIndex = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'firm_integrations' and indexname = 'firm_integrations_webhook_routing_token_unique'"
        );
        $this->assertNotNull($webhookIndex, 'The partial unique index on webhook_routing_token must be restored.');

        $compositeIndex = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'firm_integrations' and indexname = 'firm_integrations_firm_provider_external_account_unique'"
        );
        $this->assertNotNull($compositeIndex, 'The partial unique index on (firm_id, integration_provider_id, external_account_id) must be restored.');

        // 6. RLS state restored exactly.
        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'firm_integrations'");
        $this->assertCount(1, $policiesAfterReapply);
        $this->assertSame(self::POLICY_NAME, $policiesAfterReapply[0]->policyname);

        $exprAfterReapply = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'firm_integrations'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";
        $this->assertSame($expected, $exprAfterReapply->using_expr);
        $this->assertSame($expected, $exprAfterReapply->with_check_expr);

        $this->assertNotNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNotNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());
    }

    /**
     * Narrower proof calling both migration files' own up()/down()
     * directly, bypassing Artisan and the `migrations` tracking table
     * entirely — mirrors IntegrationProviderTest's second, direct-call
     * rollback proof. Still safe inside RefreshDatabase's outer
     * per-test transaction (PostgreSQL supports transactional DDL).
     */
    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $this->assertTrue(Schema::hasTable('firm_integrations'));

        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('firm_integrations'), 'Table must be fully dropped after both down() calls.');

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('firm_integrations'), 'Table must be fully restored after both up() calls.');

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);

        $policies = DB::select("select policyname from pg_policies where tablename = 'firm_integrations'");
        $this->assertCount(1, $policies);
    }

    // ------------------------------------------------------------
    // 7. Model behavior
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_firm_integrations(): void
    {
        $model = new FirmIntegration();

        $this->assertSame('firm_integrations', $model->getTable());
    }

    public function test_model_fillable_contains_exactly_the_expected_fields(): void
    {
        $model = new FirmIntegration();

        $expected = [
            'firm_id',
            'integration_provider_id',
            'external_account_id',
            'display_label',
            'status',
            'scopes_granted_json',
            'connected_by_firm_user_id',
            'connected_at',
            'disconnected_at',
            'last_health_check_at',
            'last_health_status',
            'error_reason',
            'webhook_routing_token',
        ];

        $fillable = $model->getFillable();

        sort($fillable);
        sort($expected);

        $this->assertSame($expected, $fillable);
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('uuid', $fillable);
        $this->assertNotContains('created_at', $fillable);
        $this->assertNotContains('updated_at', $fillable);
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(FirmIntegration::class);

        $this->assertArrayHasKey(
            BelongsToTenant::class,
            $traits,
            'FirmIntegration is direct firm-owned data — it must use BelongsToTenant (defense-in-depth alongside FORCE RLS).'
        );
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new FirmIntegration();

        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
    }

    public function test_status_and_json_and_datetime_casts_are_correct(): void
    {
        $integration = $this->createIntegrationForFirm(Firm::factory()->create());

        $fresh = $this->runWithFirmContext(
            $integration->firm_id,
            fn () => FirmIntegration::query()->findOrFail($integration->id),
        );

        $this->assertInstanceOf(ConnectionStatus::class, $fresh->status);
        $this->assertIsArray($fresh->scopes_granted_json);
        $this->assertInstanceOf(Carbon::class, $fresh->connected_at);
    }

    public function test_factory_produces_valid_non_colliding_rows(): void
    {
        $integrations = FirmIntegration::factory()->count(5)->create();

        $this->assertSame(5, $integrations->pluck('id')->unique()->count());
        $this->assertSame(5, $integrations->pluck('uuid')->unique()->count());

        foreach ($integrations as $integration) {
            $this->assertNotNull($integration->firm_id);
            $this->assertNotNull($integration->integration_provider_id);
        }
    }

    public function test_uuid_is_generated_non_empty_and_v7_shaped(): void
    {
        $integration = $this->createIntegrationForFirm(Firm::factory()->create());

        $this->assertNotEmpty($integration->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $integration->uuid,
            'uuid must be shaped like a UUIDv7 value (HasPublicUuid convention).'
        );
    }

    public function test_uuid_is_unique_across_rows(): void
    {
        $integrations = FirmIntegration::factory()->count(5)->create();

        $this->assertSame(5, $integrations->pluck('uuid')->unique()->count());
    }

    public function test_uuid_is_immutable(): void
    {
        $integration = $this->createIntegrationForFirm(Firm::factory()->create());
        $original = $integration->uuid;

        $this->runWithFirmContext($integration->firm_id, function () use ($integration) {
            $integration->update(['uuid' => (string) Str::uuid7()]);
        });

        $this->assertSame($original, $integration->refresh()->uuid);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function createIntegrationForFirm(Firm $firm): FirmIntegration
    {
        return FirmIntegration::factory()->forFirm($firm)->create();
    }

    private function testProvider(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', 'test')->first()
            ?? IntegrationProvider::factory()->create();
    }

    private function makeFirmUser(Firm $firm, FirmUserRole $role): User
    {
        $user = User::factory()->create();

        FirmUser::factory()
            ->forUser($user)
            ->forFirm($firm)
            ->role($role)
            ->create();

        return $user;
    }

    private function firmIntegrationPolicy(): FirmIntegrationPolicy
    {
        return new FirmIntegrationPolicy(new IntegrationAccessPolicyService());
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'integration_provider_id' => $this->testProvider()->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function foreignKeyTarget(string $column): ?object
    {
        return DB::selectOne(
            "select ccu.table_name as foreign_table, ccu.column_name as foreign_column
             from information_schema.table_constraints tc
             join information_schema.key_column_usage kcu
               on tc.constraint_name = kcu.constraint_name and tc.table_schema = kcu.table_schema
             join information_schema.constraint_column_usage ccu
               on tc.constraint_name = ccu.constraint_name and tc.table_schema = ccu.table_schema
             where tc.constraint_type = 'FOREIGN KEY'
               and tc.table_name = 'firm_integrations'
               and kcu.column_name = ?",
            [$column]
        );
    }
}
