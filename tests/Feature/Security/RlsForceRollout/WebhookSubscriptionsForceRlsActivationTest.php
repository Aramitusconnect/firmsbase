<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\WebhookSubscriptionStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookSubscription;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WebhookSubscriptionsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for webhook_subscriptions (database/
 * migrations/2026_08_31_990001_prepare_row_level_security_and_force_rls_on_webhook_subscriptions_table.php)
 * is permanently active and behaves correctly.
 *
 * First of Wave 11's five-table, one-batch webhooks domain activation —
 * the FINAL wave of the 60-table rollout. See that migration's own
 * docblock for the full batch list, ordering rationale, co-landed
 * service changes (WebhookEventRecorderService's widened wrap,
 * WebhookDispatchJob's new explicit-firmId context), and accepted-gap
 * catalogue.
 *
 * webhook_subscriptions is direct, non-null firm_id, BelongsToTenant —
 * the root of this domain, fully mutable via WebhookSubscriptionService.
 */
class WebhookSubscriptionsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_31_990001_prepare_row_level_security_and_force_rls_on_webhook_subscriptions_table.php';

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

    public function test_webhook_subscriptions_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('webhook_subscriptions', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_webhook_subscriptions_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'webhook_subscriptions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_webhook_subscriptions_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_subscriptions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'webhook_subscriptions'::regclass and polname = 'webhook_subscriptions_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_webhook_subscription_model_uses_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(WebhookSubscription::class);

        $this->assertArrayHasKey(\App\Models\Concerns\BelongsToTenant::class, $traits);
    }

    // ---------------------------------------------------------------
    // Missing-context / cross-firm proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_webhook_subscriptions(): void
    {
        $firm = Firm::factory()->create();
        $this->createSubscriptionForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        \App\Services\TenantContextResolver::clear();

        $this->assertSame(0, WebhookSubscription::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_webhook_subscriptions(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('webhook_subscriptions')->insert($this->rowAttributes($firm, $owner));
    }

    /**
     * Unlike most other FORCE-RLS'd tables in this rollout,
     * WebhookSubscriptionFactory has NO context-hold create()
     * override (confirmed by direct inspection — database/factories/
     * WebhookSubscriptionFactory.php has no create() override at all,
     * unlike e.g. TrustAccountFactory/FirmUserFactory/
     * TenantEncryptionKeyFactory). A bare, no-context factory create
     * therefore correctly FAILS closed rather than silently
     * succeeding — this test proves the actual behavior rather than
     * assuming the "context-hold override" pattern established
     * elsewhere in this rollout applies here too. This is a disclosed,
     * accepted gap (test-authoring convenience only — every real
     * production writer, WebhookSubscriptionService, always supplies
     * explicit context), not a security defect.
     */
    public function test_bare_factory_create_without_context_fails_closed_no_context_hold_override_exists(): void
    {
        $this->expectExceptionMessageMatches('/row-level security policy/');

        WebhookSubscription::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_webhook_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $subscriptionA = $this->createSubscriptionForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => WebhookSubscription::query()->pluck('id')->all());

        $this->assertSame([$subscriptionA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_webhook_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createSubscriptionForFirm($firmA);
        $subscriptionB = $this->createSubscriptionForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => WebhookSubscription::query()->pluck('id')->all());

        $this->assertNotContains($subscriptionB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_webhook_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->create(['firm_id' => $firmA->id]));

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_subscriptions')->insertGetId($this->rowAttributes($firmA, $owner)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_webhook_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $subscriptionB = $this->createSubscriptionForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($subscriptionB) {
            return DB::table('webhook_subscriptions')->where('id', $subscriptionB->id)->update(['status' => WebhookSubscriptionStatus::Disabled->value]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => WebhookSubscription::query()->find($subscriptionB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(WebhookSubscriptionStatus::Active, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_webhook_subscription(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $subscriptionB = $this->createSubscriptionForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($subscriptionB) {
            DB::table('webhook_subscriptions')->where('id', $subscriptionB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => WebhookSubscription::query()->find($subscriptionB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_webhook_subscription_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ownerB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->create(['firm_id' => $firmB->id]));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $ownerB) {
            DB::table('webhook_subscriptions')->insert($this->rowAttributes($firmB, $ownerB));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $subscriptionA = $this->createSubscriptionForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($subscriptionA, $firmB) {
            DB::table('webhook_subscriptions')->where('id', $subscriptionA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => WebhookSubscription::factory()->forFirm($firm)->create([
            'created_by_firm_user_id' => $owner->id,
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

    // ---------------------------------------------------------------
    // Migration round-trip
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'webhook_subscriptions'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'webhook_subscriptions'::regclass and polname = 'webhook_subscriptions_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_subscriptions'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_webhook_subscriptions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['webhook_subscriptions'])), 0, 5);

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

        // This is the final wave of the 60-table rollout: every entry
        // currently in missingPreparedTables() is one of this batch's
        // own 5 webhook tables (confirmed by direct inspection of
        // RowLevelSecurityCoverageMappingService::MISSING_PREPARED_TABLES,
        // which this test deliberately does not modify). Asserting that
        // explicitly (rather than only via the loop below, which would
        // otherwise execute zero assertions and be flagged risky) is
        // itself a meaningful proof that no other table was left
        // orphaned as "uncovered" by this checkpoint.
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

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    private function createSubscriptionForFirm(Firm $firm): WebhookSubscription
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);

            return WebhookSubscription::factory()->forFirm($firm)->create([
                'created_by_firm_user_id' => $owner->id,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, FirmUser $owner): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'event_types' => json_encode(['matter.created']),
            'destination_url' => 'https://example.com/webhooks/firmsbase',
            'status' => WebhookSubscriptionStatus::Active->value,
            'retry_policy_json' => json_encode(['max_attempts' => 5, 'base_delay_seconds' => 30, 'multiplier' => 2]),
            'created_by_firm_user_id' => $owner->id,
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
