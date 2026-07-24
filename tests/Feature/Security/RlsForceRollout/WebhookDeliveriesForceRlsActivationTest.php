<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WebhookDeliveriesForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for webhook_deliveries (database/migrations/
 * 2026_08_31_990004_prepare_row_level_security_and_force_rls_on_webhook_deliveries_table.php)
 * is permanently active and behaves correctly.
 *
 * Fourth of Wave 11's five-table batch (the FINAL wave of the 60-table
 * rollout). webhook_deliveries has hybrid ownership — direct, non-null
 * firm_id plus two one-hop parents (webhook_subscription_id,
 * webhook_event_id). Its model deliberately does NOT use
 * BelongsToTenant. This is the ONE table in the domain genuinely
 * mutable post-creation, but only on status/attempt_count/
 * next_attempt_at/last_attempted_at (the model's own strict
 * field-allowlist booted() guard). Was blocked on BOTH Finding 1 and
 * Finding 2's fixes landing first — this file proves the fix's
 * companion migration behaves correctly now that both have.
 */
class WebhookDeliveriesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_31_990004_prepare_row_level_security_and_force_rls_on_webhook_deliveries_table.php';

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

    public function test_webhook_deliveries_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('webhook_deliveries', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_webhook_deliveries_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'webhook_deliveries'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_webhook_deliveries_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_deliveries'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'webhook_deliveries'::regclass and polname = 'webhook_deliveries_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_webhook_delivery_model_does_not_use_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(WebhookDelivery::class);

        $this->assertArrayNotHasKey(\App\Models\Concerns\BelongsToTenant::class, $traits);
    }

    // ---------------------------------------------------------------
    // Field-allowlist guard — independent of and complementary to RLS
    // ---------------------------------------------------------------

    public function test_field_allowlist_guard_still_blocks_disallowed_updates_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $delivery = $this->createDeliveryForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $delivery->update(['webhook_subscription_id' => 999999]));
    }

    public function test_allowed_status_fields_remain_mutable_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $delivery = $this->createDeliveryForFirm($firm);

        $this->runWithFirmContext($firm, fn () => $delivery->update(['status' => WebhookDeliveryStatus::Delivered, 'attempt_count' => 1]));

        $fresh = $this->runWithFirmContext($firm, fn () => $delivery->fresh());
        $this->assertSame(WebhookDeliveryStatus::Delivered, $fresh->status);
        $this->assertSame(1, $fresh->attempt_count);
    }

    // ---------------------------------------------------------------
    // Missing-context / cross-firm proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_webhook_deliveries(): void
    {
        $firm = Firm::factory()->create();
        $this->createDeliveryForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        \App\Services\TenantContextResolver::clear();

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_webhook_deliveries(): void
    {
        $firm = Firm::factory()->create();
        [$subscription, $event] = $this->makeFixturesForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('webhook_deliveries')->insert($this->rowAttributes($firm, $subscription, $event));
    }

    /**
     * Unlike most other FORCE-RLS'd tables in this rollout,
     * WebhookDeliveryFactory has NO context-hold create() override
     * (confirmed by direct inspection). A bare, no-context factory
     * create therefore correctly FAILS closed. Disclosed, accepted gap
     * (test-authoring convenience only — the two production writers,
     * WebhookDeliveryService::enqueue() and WebhookReplayService::
     * replay(), always run under an already-active caller-supplied
     * context).
     */
    public function test_bare_factory_create_without_context_fails_closed_no_context_hold_override_exists(): void
    {
        $this->expectExceptionMessageMatches('/row-level security policy/');

        WebhookDelivery::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_webhook_delivery(): void
    {
        $firmA = Firm::factory()->create();
        $deliveryA = $this->createDeliveryForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_deliveries')->pluck('id')->all());

        $this->assertSame([$deliveryA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_webhook_delivery(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createDeliveryForFirm($firmA);
        $deliveryB = $this->createDeliveryForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_deliveries')->pluck('id')->all());

        $this->assertNotContains($deliveryB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_webhook_delivery(): void
    {
        $firmA = Firm::factory()->create();
        [$subscription, $event] = $this->makeFixturesForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_deliveries')->insertGetId($this->rowAttributes($firmA, $subscription, $event)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_webhook_delivery_via_raw_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deliveryB = $this->createDeliveryForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($deliveryB) {
            return DB::table('webhook_deliveries')->where('id', $deliveryB->id)->update(['attempt_count' => 99]);
        });

        $this->assertSame(0, $affected);
    }

    public function test_firm_a_cannot_delete_firm_b_webhook_delivery(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deliveryB = $this->createDeliveryForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($deliveryB) {
            DB::table('webhook_deliveries')->where('id', $deliveryB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('webhook_deliveries')->where('id', $deliveryB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_webhook_delivery_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$subscriptionB, $eventB] = $this->makeFixturesForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $subscriptionB, $eventB) {
            DB::table('webhook_deliveries')->insert($this->rowAttributes($firmB, $subscriptionB, $eventB));
        });
    }

    /**
     * Residual, disclosed database-constraint gap (not a false
     * guarantee): no CHECK constraint or trigger ties
     * webhook_subscription_id's/webhook_event_id's OWN firm_id back to
     * this row's firm_id. RLS checks only THIS row's own firm_id
     * column — it cannot and does not prevent a raw insert from
     * pairing a firm_id that matches the acting session with a
     * webhook_subscription_id/webhook_event_id genuinely owned by a
     * DIFFERENT firm. This test proves that gap exists exactly as
     * documented (not that RLS closes it) — the real compensating
     * control is WebhookDeliveryService::enqueue()/WebhookReplayService::
     * replay() always deriving firm_id from an already-validated parent,
     * never from raw caller input.
     */
    public function test_rls_does_not_catch_a_transitive_cross_firm_subscription_event_mismatch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$subscriptionB, $eventB] = $this->makeFixturesForFirm($firmB);

        // firm_id matches the acting session (firmA) but
        // webhook_subscription_id/webhook_event_id both genuinely
        // belong to firmB — RLS's USING/WITH CHECK only inspect this
        // row's own firm_id column, so this insert is NOT blocked.
        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_deliveries')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firmA->id,
            'webhook_subscription_id' => $subscriptionB->id,
            'webhook_event_id' => $eventB->id,
            'status' => WebhookDeliveryStatus::Pending->value,
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertIsInt($insertedId, 'A transitive cross-firm subscription/event mismatch is NOT blocked by RLS alone — this is a disclosed, accepted gap, not a security guarantee.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->createDeliveryForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'webhook_deliveries'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'webhook_deliveries'::regclass and polname = 'webhook_deliveries_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_deliveries'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_webhook_deliveries(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['webhook_deliveries'])), 0, 5);

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

    /**
     * @return array{0: WebhookSubscription, 1: WebhookEvent}
     */
    private function makeFixturesForFirm(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, fn () => $this->makeFixturesForFirmInsideContext($firm));
    }

    /**
     * @return array{0: WebhookSubscription, 1: WebhookEvent}
     */
    private function makeFixturesForFirmInsideContext(Firm $firm): array
    {
        $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $subscription = WebhookSubscription::factory()->forFirm($firm)->create([
            'created_by_firm_user_id' => $owner->id,
        ]);
        $event = WebhookEvent::factory()->forFirm($firm)->create();

        return [$subscription, $event];
    }

    private function createDeliveryForFirm(Firm $firm): WebhookDelivery
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            [$subscription, $event] = $this->makeFixturesForFirmInsideContext($firm);

            return WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, WebhookSubscription $subscription, WebhookEvent $event): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'webhook_event_id' => $event->id,
            'status' => WebhookDeliveryStatus::Pending->value,
            'attempt_count' => 0,
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
