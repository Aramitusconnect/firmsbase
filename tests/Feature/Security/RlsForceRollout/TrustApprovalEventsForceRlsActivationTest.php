<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TrustApprovalEventType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TrustApprovalEvent;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TrustApprovalEventsForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for trust_approval_events (database/
 * migrations/2026_08_30_980006_prepare_row_level_security_and_force_rls_on_trust_approval_events_table.php)
 * is permanently active and behaves correctly.
 *
 * Sixth of Wave 10's ten-table batch. This is the exact table
 * TrustEligibilityService::hasApprovedTrustSetup() reads — see the
 * dedicated eligibility-under-force-rls proof in
 * TrustEligibilityServiceTest::test_fully_configured_firm_is_eligible.
 * Its model does NOT use BelongsToTenant AND is append-only via a
 * booted() guard — this file proves both.
 */
class TrustApprovalEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980006_prepare_row_level_security_and_force_rls_on_trust_approval_events_table.php';

    private const THIS_BATCH = [
        'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
        'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
    ];

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_trust_approval_events_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('trust_approval_events', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_trust_approval_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'trust_approval_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_trust_approval_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_approval_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'trust_approval_events'::regclass and polname = 'trust_approval_events_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_trust_approval_event_model_does_not_use_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(TrustApprovalEvent::class);

        $this->assertArrayNotHasKey(\App\Models\Concerns\BelongsToTenant::class, $traits);
    }

    public function test_append_only_guard_still_blocks_update_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $event->update(['amount_cents' => 1]));
    }

    public function test_append_only_guard_still_blocks_delete_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->createEventForFirm($firm);

        $this->expectException(\LogicException::class);

        $this->runWithFirmContext($firm, fn () => $event->delete());
    }

    public function test_missing_tenant_context_cannot_read_trust_approval_events(): void
    {
        $firm = Firm::factory()->create();
        $this->createEventForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, TrustApprovalEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_trust_approval_events(): void
    {
        $firm = Firm::factory()->create();
        $actor = $this->createActorForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('trust_approval_events')->insert($this->rowAttributes($firm, $actor));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $event = TrustApprovalEvent::factory()->create();

        $this->assertNotNull($event->id);

        $persisted = $this->runWithFirmContext($event->firm_id, fn () => TrustApprovalEvent::query()->find($event->id));

        $this->assertNotNull($persisted);
        $this->assertSame($event->firm_id, $persisted->firm_id);
    }

    public function test_rls_alone_isolates_cross_firm_reads_despite_the_missing_belongs_to_tenant_scope(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventA = $this->createEventForFirm($firmA);
        $eventB = $this->createEventForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => TrustApprovalEvent::query()->pluck('id')->all());

        $this->assertSame([$eventA->id], $visibleIds);
        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_trust_approval_event(): void
    {
        $firmA = Firm::factory()->create();
        $actor = $this->createActorForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('trust_approval_events')->insertGetId($this->rowAttributes($firmA, $actor)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_trust_approval_event_via_raw_query(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($eventB) {
            return DB::table('trust_approval_events')->where('id', $eventB->id)->update(['amount_cents' => 1]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustApprovalEvent::query()->find($eventB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_trust_approval_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->createEventForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('trust_approval_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TrustApprovalEvent::query()->find($eventB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_trust_approval_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $actor = $this->createActorForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $actor) {
            DB::table('trust_approval_events')->insert($this->rowAttributes($firmB, $actor));
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $actor = $this->createActorForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => TrustApprovalEvent::factory()->create([
            'firm_id' => $firm->id,
            'actor_firm_user_id' => $actor->id,
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

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'trust_approval_events'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'trust_approval_events'::regclass and polname = 'trust_approval_events_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'trust_approval_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_trust_approval_events(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['trust_approval_events'])), 0, 5);

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

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $this->assertEmpty($this->changedOrUntrackedPaths($relativeDir));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    private function createActorForFirm(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id]);
    }

    private function createEventForFirm(Firm $firm): TrustApprovalEvent
    {
        $actor = $this->createActorForFirm($firm);

        return $this->runWithFirmContext($firm, fn () => TrustApprovalEvent::factory()->create([
            'firm_id' => $firm->id,
            'actor_firm_user_id' => $actor->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, FirmUser $actor): array
    {
        return [
            'firm_id' => $firm->id,
            'event_type' => TrustApprovalEventType::DepositRequested->value,
            'actor_firm_user_id' => $actor->id,
            'amount_cents' => 10000,
            'correlation_uuid' => (string) Str::uuid7(),
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
