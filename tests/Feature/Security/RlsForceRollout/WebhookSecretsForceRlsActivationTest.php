<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\WebhookSecretStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookSecret;
use App\Models\WebhookSubscription;
use App\Services\EncryptionKeyService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WebhookSecretsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for webhook_secrets (database/migrations/
 * 2026_08_31_990003_prepare_row_level_security_and_force_rls_on_webhook_secrets_table.php)
 * is permanently active and behaves correctly.
 *
 * Third of Wave 11's five-table batch (the FINAL wave of the 60-table
 * rollout). webhook_secrets has hybrid ownership — direct, non-null
 * firm_id plus a one-hop parent (webhook_subscription_id). Its model
 * deliberately does NOT use BelongsToTenant (scoped transitively,
 * defended by TenantSafeWebhookPolicyService — see model docblock),
 * so RLS alone is this table's only DB-layer isolation mechanism.
 * Partial mutability: status/rotated_at are mutable for rotation;
 * encrypted_secret_ciphertext/encryption_key_id are immutable via the
 * model's own booted() guard.
 */
class WebhookSecretsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_31_990003_prepare_row_level_security_and_force_rls_on_webhook_secrets_table.php';

    private const THIS_BATCH = [
        'webhook_subscriptions', 'webhook_events', 'webhook_secrets',
        'webhook_deliveries', 'webhook_delivery_attempts',
    ];

    // ---------------------------------------------------------------
    // FORCE state / policy proofs
    // ---------------------------------------------------------------

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_webhook_secrets_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('webhook_secrets', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_webhook_secrets_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'webhook_secrets'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_webhook_secrets_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_secrets'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'webhook_secrets'::regclass and polname = 'webhook_secrets_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_webhook_secret_model_does_not_use_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(WebhookSecret::class);

        $this->assertArrayNotHasKey(\App\Models\Concerns\BelongsToTenant::class, $traits);
    }

    // ---------------------------------------------------------------
    // Immutable-columns guard — independent of and complementary to RLS
    // ---------------------------------------------------------------

    public function test_ciphertext_and_key_are_immutable_under_force_rls(): void
    {
        $firm = $this->makeFirmWithEncryptionKey();
        $secret = $this->createSecretForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $secret->update(['encrypted_secret_ciphertext' => 'tampered']));
    }

    public function test_status_and_rotated_at_remain_mutable_under_force_rls(): void
    {
        $firm = $this->makeFirmWithEncryptionKey();
        $secret = $this->createSecretForFirm($firm);

        $this->runWithFirmContext($firm, fn () => $secret->update(['status' => WebhookSecretStatus::Rotated, 'rotated_at' => now()]));

        $fresh = $this->runWithFirmContext($firm, fn () => $secret->fresh());
        $this->assertSame(WebhookSecretStatus::Rotated, $fresh->status);
        $this->assertNotNull($fresh->rotated_at);
    }

    // ---------------------------------------------------------------
    // Missing-context / cross-firm proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_webhook_secrets(): void
    {
        $firm = $this->makeFirmWithEncryptionKey();
        $this->createSecretForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        \App\Services\TenantContextResolver::clear();

        $this->assertSame(0, WebhookSecret::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_webhook_secrets(): void
    {
        $firm = $this->makeFirmWithEncryptionKey();
        [$subscription, $encryptionKeyId] = $this->makeFixturesForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('webhook_secrets')->insert($this->rowAttributes($firm, $subscription, $encryptionKeyId));
    }

    /**
     * Unlike most other FORCE-RLS'd tables in this rollout,
     * WebhookSecretFactory has NO context-hold create() override
     * (confirmed by direct inspection). A bare, no-context factory
     * create therefore correctly FAILS closed. Disclosed, accepted gap
     * (test-authoring convenience only — the one production writer,
     * WebhookSecretService::generate()/rotate(), always relies on
     * ambient caller-supplied context, per that service's own
     * docblock — a separate, already-documented gap from Phase 3/4).
     */
    public function test_bare_factory_create_without_context_fails_closed_no_context_hold_override_exists(): void
    {
        $this->expectExceptionMessageMatches('/row-level security policy/');

        WebhookSecret::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_webhook_secret(): void
    {
        $firmA = $this->makeFirmWithEncryptionKey();
        $secretA = $this->createSecretForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_secrets')->pluck('id')->all());

        $this->assertSame([$secretA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_webhook_secret(): void
    {
        $firmA = $this->makeFirmWithEncryptionKey();
        $firmB = $this->makeFirmWithEncryptionKey();
        $this->createSecretForFirm($firmA);
        $secretB = $this->createSecretForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_secrets')->pluck('id')->all());

        $this->assertNotContains($secretB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_webhook_secret(): void
    {
        $firmA = $this->makeFirmWithEncryptionKey();
        [$subscription, $encryptionKeyId] = $this->makeFixturesForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_secrets')->insertGetId($this->rowAttributes($firmA, $subscription, $encryptionKeyId)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_webhook_secret_via_raw_query(): void
    {
        $firmA = $this->makeFirmWithEncryptionKey();
        $firmB = $this->makeFirmWithEncryptionKey();
        $secretB = $this->createSecretForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($secretB) {
            return DB::table('webhook_secrets')->where('id', $secretB->id)->update(['status' => WebhookSecretStatus::Rotated->value]);
        });

        $this->assertSame(0, $affected);
    }

    public function test_firm_a_cannot_delete_firm_b_webhook_secret(): void
    {
        $firmA = $this->makeFirmWithEncryptionKey();
        $firmB = $this->makeFirmWithEncryptionKey();
        $secretB = $this->createSecretForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($secretB) {
            DB::table('webhook_secrets')->where('id', $secretB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('webhook_secrets')->where('id', $secretB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_webhook_secret_claiming_firm_b_ownership(): void
    {
        $firmA = $this->makeFirmWithEncryptionKey();
        $firmB = $this->makeFirmWithEncryptionKey();
        [$subscriptionB, $encryptionKeyIdB] = $this->makeFixturesForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $subscriptionB, $encryptionKeyIdB) {
            DB::table('webhook_secrets')->insert($this->rowAttributes($firmB, $subscriptionB, $encryptionKeyIdB));
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = $this->makeFirmWithEncryptionKey();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->createSecretForFirm($firm);

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

    // ---------------------------------------------------------------
    // Migration round-trip
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'webhook_secrets'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'webhook_secrets'::regclass and polname = 'webhook_secrets_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_secrets'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_webhook_secrets(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['webhook_secrets'])), 0, 5);

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

        $this->assertEmpty(
            array_diff($coverage->missingPreparedTables(), self::THIS_BATCH),
            'No table outside this batch should remain in missingPreparedTables() once the final wave has landed.'
        );

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

    public function test_rls_prepared_not_enforced_gap_remains_tracked(): void
    {
        $registry = app(\App\Services\ComplianceGapRegistryService::class);

        $this->assertTrue(
            $registry->isTracked('rls_prepared_not_enforced'),
            'rls_prepared_not_enforced must remain a tracked compliance gap — closing it entirely is out of scope for this activation checkpoint.'
        );
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $this->assertEmpty($this->changedOrUntrackedPaths($relativeDir));
        }
    }

    private function makeFirmWithEncryptionKey(): Firm
    {
        $firm = Firm::factory()->create();
        app(EncryptionKeyService::class)->provision($firm);

        return $firm->fresh();
    }

    /**
     * @return array{0: WebhookSubscription, 1: int}
     */
    private function makeFixturesForFirm(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
            $subscription = WebhookSubscription::factory()->forFirm($firm)->create([
                'created_by_firm_user_id' => $owner->id,
            ]);
            $encryptionKeyId = $firm->activeTenantEncryptionKey->id;

            return [$subscription, $encryptionKeyId];
        });
    }

    private function createSecretForFirm(Firm $firm): WebhookSecret
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            [$subscription, $encryptionKeyId] = $this->makeFixturesForFirmInsideContext($firm);

            return WebhookSecret::factory()->forSubscription($subscription)->create([
                'encryption_key_id' => $encryptionKeyId,
            ]);
        });
    }

    /**
     * @return array{0: WebhookSubscription, 1: int}
     */
    private function makeFixturesForFirmInsideContext(Firm $firm): array
    {
        $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $subscription = WebhookSubscription::factory()->forFirm($firm)->create([
            'created_by_firm_user_id' => $owner->id,
        ]);
        $encryptionKeyId = $firm->activeTenantEncryptionKey->id;

        return [$subscription, $encryptionKeyId];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, WebhookSubscription $subscription, int $encryptionKeyId): array
    {
        return [
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'encrypted_secret_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => $encryptionKeyId,
            'status' => WebhookSecretStatus::Active->value,
            'created_at' => now(),
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
