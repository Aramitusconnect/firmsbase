<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Models\Concerns\BelongsToTenant;
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
 * IntegrationProviderWebhookSubscriptionsForceRlsActivationTest —
 * FirmsVault Live Integrations, Checkpoint 2 ("Add Microsoft 365
 * integration provider"). Modeled closely on
 * Tests\Feature\Integrations\IntegrationSyncCursorsForceRlsActivationTest
 * — this checkpoint's own chosen template pair (same composite-FK/index
 * shape as integration_sync_cursors' own create migration, per the
 * create migration's own docblock). Registered here (RlsForceRollout)
 * rather than under tests/Feature/Integrations to satisfy
 * SchemaTenantFirewallTest::test_check_5_every_forced_table_has_a_matching_activation_test_file,
 * which searches the entire tests/ tree by filename, so either location
 * is equally valid — this table is a first-class member of the ongoing
 * FORCE RLS rollout tracked by this directory.
 */
class IntegrationProviderWebhookSubscriptionsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_22_160001_create_integration_provider_webhook_subscriptions_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_22_160001_create_integration_provider_webhook_subscriptions_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_22_160002_prepare_row_level_security_and_force_rls_on_integration_provider_webhook_subscriptions_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_22_160002_prepare_row_level_security_and_force_rls_on_integration_provider_webhook_subscriptions_table';

    private const POLICY_NAME = 'integration_provider_webhook_subscriptions_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'firm_id', 'firm_integration_id', 'provider_key', 'resource_type', 'provider_resource',
        'provider_change_type', 'provider_subscription_id', 'expires_at', 'status', 'last_renewed_at',
        'last_renewal_error', 'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_provider_webhook_subscriptions'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_provider_webhook_subscriptions');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists(): void
    {
        $row = DB::selectOne(
            "select confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_provider_webhook_subscriptions'::regclass and contype = 'f' and conname = 'integration_provider_webhook_subscriptions_firm_integration_fk'"
        );

        $this->assertNotNull($row);
        $this->assertSame('firm_integrations', $row->foreign_table);
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB) {
            DB::table('integration_provider_webhook_subscriptions')->insert($this->rawRowAttributes($firmA, $connectionB));
        });
    }

    public function test_partial_unique_index_exists_on_firm_integration_id_provider_resource_provider_change_type_where_active(): void
    {
        $rows = DB::select("select indexdef from pg_indexes where tablename = 'integration_provider_webhook_subscriptions'");

        $found = false;
        foreach ($rows as $row) {
            if (str_contains($row->indexdef, 'UNIQUE') && str_contains($row->indexdef, 'firm_integration_id')
                && str_contains($row->indexdef, 'provider_resource') && str_contains($row->indexdef, 'provider_change_type')
                && str_contains($row->indexdef, "WHERE ((status)::text = 'active'::text)")) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Expected a partial UNIQUE index covering (firm_integration_id, provider_resource, provider_change_type) WHERE status = \'active\'.');
    }

    public function test_active_scope_uniqueness_rejects_a_duplicate_active_subscription_for_the_same_scope(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_provider_webhook_subscriptions')->insert($this->rawRowAttributes($firm, $connection));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_provider_webhook_subscriptions')->insert($this->rawRowAttributes($firm, $connection));
        });
    }

    public function test_a_non_active_row_for_the_same_scope_does_not_conflict_with_the_partial_index(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $affected = $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_provider_webhook_subscriptions')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection),
                ['status' => ProviderWebhookSubscriptionStatus::RenewalFailed->value]
            ));

            return DB::table('integration_provider_webhook_subscriptions')->insert($this->rawRowAttributes($firm, $connection));
        });

        $this->assertTrue($affected);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_provider_webhook_subscriptions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_provider_webhook_subscriptions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_provider_webhook_subscriptions'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_provider_webhook_subscriptions'::regclass and polname = ?",
            [self::POLICY_NAME]
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
        IntegrationProviderWebhookSubscription::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_provider_webhook_subscriptions')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_provider_webhook_subscriptions')->insert($this->rawRowAttributes($firm, $connection));
    }

    public function test_firm_a_context_can_read_its_own_subscription(): void
    {
        $firm = Firm::factory()->create();
        $subscription = IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create();

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')->pluck('id')->all());

        $this->assertSame([$subscription->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();
        $subscriptionB = IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_provider_webhook_subscriptions')->pluck('id')->all());

        $this->assertNotContains($subscriptionB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_subscription_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = FirmIntegration::factory()->forFirm($firmB)->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB) {
            DB::table('integration_provider_webhook_subscriptions')->insert($this->rawRowAttributes($firmB, $connectionB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $subscriptionB = IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('id', $subscriptionB->id)->update(['last_renewal_error' => 'provider_rejected']),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_provider_webhook_subscriptions')->where('id', $subscriptionB->id)->value('last_renewal_error'));
        $this->assertNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $subscriptionB = IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_provider_webhook_subscriptions')->where('id', $subscriptionB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_provider_webhook_subscriptions')->where('id', $subscriptionB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $subscription = IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmA)->create())->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($subscription, $firmB) {
            DB::table('integration_provider_webhook_subscriptions')->where('id', $subscription->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

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
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));
        $this->assertTrue(Schema::hasTable('integration_provider_webhook_subscriptions'));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_provider_webhook_subscriptions'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_provider_webhook_subscriptions'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        $tableRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableRollbackExit, Artisan::output());
        $this->assertFalse(Schema::hasTable('integration_provider_webhook_subscriptions'));

        $tableMigrateExit = Artisan::call('migrate', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableMigrateExit, Artisan::output());
        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        $this->assertTrue(Schema::hasTable('integration_provider_webhook_subscriptions'));

        $columns = Schema::getColumnListing('integration_provider_webhook_subscriptions');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_provider_webhook_subscriptions'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_provider_webhook_subscriptions'");
        $this->assertCount(1, $policiesAfterReapply);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_provider_webhook_subscriptions'));

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('integration_provider_webhook_subscriptions'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_provider_webhook_subscriptions'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('integration_provider_webhook_subscriptions', (new IntegrationProviderWebhookSubscription)->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(IntegrationProviderWebhookSubscription::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $firm = Firm::factory()->create();
        $connectionOne = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionTwo = FirmIntegration::factory()->forFirm($firm)->create();
        $connectionThree = FirmIntegration::factory()->forFirm($firm)->create();

        $subscriptions = collect([$connectionOne, $connectionTwo, $connectionThree])
            ->map(fn (FirmIntegration $c) => IntegrationProviderWebhookSubscription::factory()->forFirmIntegration($c)->create());

        $this->assertSame(3, $subscriptions->pluck('id')->unique()->count());
        foreach ($subscriptions as $subscription) {
            $this->assertNotNull($subscription->firm_id);
            $this->assertNotNull($subscription->firm_integration_id);
        }
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
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'provider_key' => 'microsoft365',
            'resource_type' => 'message',
            'provider_resource' => "me/mailFolders('Inbox')/messages",
            'provider_change_type' => 'created,updated,deleted',
            'provider_subscription_id' => (string) Str::uuid(),
            'expires_at' => now()->addHours(70),
            'status' => ProviderWebhookSubscriptionStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
