<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\WebhookDeliveryAttemptOutcome;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WebhookDeliveryAttemptsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for webhook_delivery_attempts (database/
 * migrations/2026_08_31_990005_prepare_row_level_security_and_force_rls_on_webhook_delivery_attempts_table.php)
 * is permanently active and behaves correctly.
 *
 * Fifth and LAST of Wave 11's five-table batch — and the final table of
 * the ENTIRE 60-table FORCE RLS rollout. webhook_delivery_attempts has
 * hybrid ownership (direct firm_id plus one-hop webhook_delivery_id
 * parent), does NOT use BelongsToTenant, and carries the strictest
 * immutability guarantees in the domain (booted() throws on both
 * update and delete, no exceptions, $timestamps = false). Was blocked
 * on Finding 2's fix (WebhookDispatchJob's explicit-firmId context)
 * landing first, plus the required companion
 * TenantSafeWebhookPolicyService::assertWebhookDeliveryAttemptBelongsToFirm()
 * addition (see TenantIsolation/WebhookTenantIsolationTest.php's
 * companion test). Since this is the final table of the final wave,
 * this file also carries the "whole batch landed" exact-count proof.
 */
class WebhookDeliveryAttemptsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_31_990005_prepare_row_level_security_and_force_rls_on_webhook_delivery_attempts_table.php';

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

    public function test_webhook_delivery_attempts_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('webhook_delivery_attempts', $coverage->forcedTables());
    }

    /**
     * This is the LAST table of the LAST wave to land — the exact-count
     * proof here is the strongest one in the entire 60-table rollout:
     * every one of this batch's other 4 tables, AND every table forced
     * in every prior wave, must ALSO already be forced by the time this
     * migration runs, and the count must equal the total number of
     * FORCE-activation migration files on disk exactly (no duplicates,
     * no gaps, no stragglers).
     */
    public function test_exact_forced_table_count_has_no_duplicate_collisions_and_includes_the_whole_batch(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()), 'forcedTables() count must equal the number of FORCE-activation migration files on disk exactly.');

        foreach (self::THIS_BATCH as $table) {
            $this->assertContains($table, $coverage->forcedTables(), "{$table} must be present in forcedTables() once the whole Wave 11 batch has landed.");
        }
    }

    public function test_webhook_delivery_attempts_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'webhook_delivery_attempts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_webhook_delivery_attempts_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_delivery_attempts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'webhook_delivery_attempts'::regclass and polname = 'webhook_delivery_attempts_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_webhook_delivery_attempt_model_does_not_use_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(WebhookDeliveryAttempt::class);

        $this->assertArrayNotHasKey(\App\Models\Concerns\BelongsToTenant::class, $traits);
    }

    // ---------------------------------------------------------------
    // Append-only guard — independent of and complementary to RLS
    // ---------------------------------------------------------------

    public function test_append_only_guard_still_blocks_update_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->createAttemptForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $attempt->update(['outcome' => WebhookDeliveryAttemptOutcome::Failure->value]));
    }

    public function test_append_only_guard_still_blocks_delete_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->createAttemptForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $attempt->delete());
    }

    // ---------------------------------------------------------------
    // Missing-context / cross-firm proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_webhook_delivery_attempts(): void
    {
        $firm = Firm::factory()->create();
        $this->createAttemptForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();
        \App\Services\TenantContextResolver::clear();

        $this->assertSame(0, WebhookDeliveryAttempt::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_webhook_delivery_attempts(): void
    {
        $firm = Firm::factory()->create();
        $delivery = $this->makeDeliveryForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('webhook_delivery_attempts')->insert($this->rowAttributes($firm, $delivery));
    }

    /**
     * Unlike most other FORCE-RLS'd tables in this rollout,
     * WebhookDeliveryAttemptFactory has NO context-hold create()
     * override (confirmed by direct inspection). A bare, no-context
     * factory create therefore correctly FAILS closed. Disclosed,
     * accepted gap (test-authoring convenience only — the one
     * production writer, WebhookDeliveryAttemptService::recordAttempt(),
     * always runs under an already-active caller-supplied context, per
     * WebhookDispatchJob's own explicit runInFirmContext() wrap).
     */
    public function test_bare_factory_create_without_context_fails_closed_no_context_hold_override_exists(): void
    {
        $this->expectExceptionMessageMatches('/row-level security policy/');

        WebhookDeliveryAttempt::factory()->create();
    }

    public function test_firm_a_context_can_read_its_own_webhook_delivery_attempt(): void
    {
        $firmA = Firm::factory()->create();
        $attemptA = $this->createAttemptForFirm($firmA);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_delivery_attempts')->pluck('id')->all());

        $this->assertSame([$attemptA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_webhook_delivery_attempt(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->createAttemptForFirm($firmA);
        $attemptB = $this->createAttemptForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_delivery_attempts')->pluck('id')->all());

        $this->assertNotContains($attemptB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_webhook_delivery_attempt(): void
    {
        $firmA = Firm::factory()->create();
        $delivery = $this->makeDeliveryForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('webhook_delivery_attempts')->insertGetId($this->rowAttributes($firmA, $delivery)));

        $this->assertIsInt($insertedId);
    }

    /**
     * A raw update() bypasses the model's own booted() guard entirely
     * (that guard hooks Eloquent's updating event, not the DB layer) —
     * this test proves RLS's WITH CHECK also independently blocks a
     * cross-firm raw update, a second, structurally different
     * protection than the append-only guard above.
     */
    public function test_firm_a_cannot_update_firm_b_webhook_delivery_attempt_via_raw_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $attemptB = $this->createAttemptForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($attemptB) {
            return DB::table('webhook_delivery_attempts')->where('id', $attemptB->id)->update(['outcome' => WebhookDeliveryAttemptOutcome::Failure->value]);
        });

        $this->assertSame(0, $affected);
    }

    public function test_firm_a_cannot_delete_firm_b_webhook_delivery_attempt(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $attemptB = $this->createAttemptForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($attemptB) {
            DB::table('webhook_delivery_attempts')->where('id', $attemptB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('webhook_delivery_attempts')->where('id', $attemptB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_webhook_delivery_attempt_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $deliveryB = $this->makeDeliveryForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $deliveryB) {
            DB::table('webhook_delivery_attempts')->insert($this->rowAttributes($firmB, $deliveryB));
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->createAttemptForFirm($firm);

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
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'webhook_delivery_attempts'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'webhook_delivery_attempts'::regclass and polname = 'webhook_delivery_attempts_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'webhook_delivery_attempts'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_webhook_delivery_attempts(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['webhook_delivery_attempts'])), 0, 5);

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

        // Final table of the final wave — every entry in
        // missingPreparedTables() must now be one of this batch's own
        // 5 tables (there is no future wave to defer anything to).
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
            'rls_prepared_not_enforced must remain a tracked compliance gap — closing the gap entirely (even though every table batch has now landed) is a separate, explicitly authorized follow-up out of scope for this activation checkpoint.'
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

    private function makeDeliveryForFirm(Firm $firm): WebhookDelivery
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
            $subscription = WebhookSubscription::factory()->forFirm($firm)->create([
                'created_by_firm_user_id' => $owner->id,
            ]);
            $event = WebhookEvent::factory()->forFirm($firm)->create();

            return WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();
        });
    }

    private function createAttemptForFirm(Firm $firm): WebhookDeliveryAttempt
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
            $subscription = WebhookSubscription::factory()->forFirm($firm)->create([
                'created_by_firm_user_id' => $owner->id,
            ]);
            $event = WebhookEvent::factory()->forFirm($firm)->create();
            $delivery = WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();

            return WebhookDeliveryAttempt::factory()->forDelivery($delivery)->create();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, WebhookDelivery $delivery): array
    {
        return [
            'firm_id' => $firm->id,
            'webhook_delivery_id' => $delivery->id,
            'webhook_secret_id' => null,
            'attempt_number' => 1,
            'outcome' => WebhookDeliveryAttemptOutcome::Success->value,
            'http_status_code' => 200,
            'response_snippet' => 'ok',
            'attempted_at' => now(),
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
