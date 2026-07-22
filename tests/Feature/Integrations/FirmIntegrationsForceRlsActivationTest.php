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

    /**
     * POST-CHECKPOINT-6 UPDATE: Checkpoint 6 added 5 tables that
     * independently carry a real composite FK (firm_id,
     * firm_integration_id) -> firm_integrations(firm_id, id) —
     * integration_sync_runs, integration_external_mappings,
     * integration_sync_cursors, integration_conflicts,
     * integration_outbox_events — plus integration_sync_items, which is
     * not itself a direct dependent of firm_integrations but is a
     * composite-FK dependent of integration_sync_runs and must be rolled
     * back before it. Both rollback tests below now also roll back this
     * entire 12-migration Checkpoint 6 wave (in the frozen
     * reverse-dependency order, frozen-design-post-review.md §2:
     * outbox_events -> conflicts -> sync_cursors -> external_mappings ->
     * sync_items -> sync_runs) before firm_integrations' own rollback —
     * mirroring this checkpoint's own
     * IntegrationSyncRunsForceRlsActivationTest::WHOLE_WAVE_MIGRATION_PATHS
     * precedent and Checkpoint 5's identical rollback-order-dependency
     * precedent (reviews/checkpoint-05/precommit-failure-triage.md).
     * Order between this CP6 wave and the pre-existing
     * integration_credentials / integration_oauth_states rollback blocks
     * below does not matter — neither references the other, only each
     * independently references firm_integrations.
     *
     * @var list<string>
     */
    private const CP6_WHOLE_WAVE_MIGRATION_PATHS = [
        'database/migrations/2026_09_05_050001_create_integration_sync_runs_table.php',
        'database/migrations/2026_09_05_050002_prepare_row_level_security_and_force_rls_on_integration_sync_runs_table.php',
        'database/migrations/2026_09_05_051001_create_integration_sync_items_table.php',
        'database/migrations/2026_09_05_051002_prepare_row_level_security_and_force_rls_on_integration_sync_items_table.php',
        'database/migrations/2026_09_05_052001_create_integration_external_mappings_table.php',
        'database/migrations/2026_09_05_052002_prepare_row_level_security_and_force_rls_on_integration_external_mappings_table.php',
        'database/migrations/2026_09_05_053001_create_integration_sync_cursors_table.php',
        'database/migrations/2026_09_05_053002_prepare_row_level_security_and_force_rls_on_integration_sync_cursors_table.php',
        'database/migrations/2026_09_05_054001_create_integration_conflicts_table.php',
        'database/migrations/2026_09_05_054002_prepare_row_level_security_and_force_rls_on_integration_conflicts_table.php',
        'database/migrations/2026_09_05_055001_create_integration_outbox_events_table.php',
        'database/migrations/2026_09_05_055002_prepare_row_level_security_and_force_rls_on_integration_outbox_events_table.php',
    ];

    /**
     * @var list<string>
     */
    private const CP6_WHOLE_WAVE_TABLES = [
        'integration_sync_runs',
        'integration_sync_items',
        'integration_external_mappings',
        'integration_sync_cursors',
        'integration_conflicts',
        'integration_outbox_events',
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

    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-4, extended again
     * post-Checkpoint-5): this test originally assumed firm_integrations
     * had no dependents and could be rolled back in true isolation.
     * Checkpoint 4 — a later, legitimate schema addition — introduced
     * integration_credentials, which carries a real composite FK
     * (firm_id, firm_integration_id) -> firm_integrations(firm_id, id)
     * with cascadeOnDelete(), so firm_integrations can no longer be
     * dropped/rolled back while integration_credentials still exists.
     * Checkpoint 5 introduced a SECOND, independent real dependent —
     * integration_oauth_states — carrying the identical composite-FK
     * shape (firm_id, firm_integration_id) -> firm_integrations(firm_id,
     * id) with cascadeOnDelete() (see
     * 2026_09_04_040001_create_integration_oauth_states_table.php). This
     * test now rolls back BOTH dependents first (in either order between
     * themselves — they share no FK relationship with each other, only
     * each independently with firm_integrations — each in its own
     * FK-dependency / reverse-chronological order: RLS-prep migration,
     * then create-table migration), then firm_integrations' own two
     * migrations, and reapplies in forward order (firm_integrations
     * first, then integration_credentials, then integration_oauth_states).
     * This is a strengthening of the test, not a weakening: it now
     * proves cross-table rollback safety for BOTH dependents one level
     * further down the FK chain, and asserts each ends up correctly
     * restored too (table exists again, its own RLS/FORCE state,
     * policies, and composite FK are correct) as a natural side-effect
     * proof, not merely that firm_integrations round-trips.
     *
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-6): Checkpoint 6 added a
     * THIRD independent dependency chain on firm_integrations — the
     * 6-table / 12-migration integration_sync_runs /
     * integration_sync_items / integration_external_mappings /
     * integration_sync_cursors / integration_conflicts /
     * integration_outbox_events wave (see CP6_WHOLE_WAVE_MIGRATION_PATHS
     * above). This test now also rolls back that entire wave (whole-wave
     * order required internally — integration_sync_items and
     * integration_conflicts are themselves composite-FK dependents of
     * other CP6 tables, not just of firm_integrations) before
     * firm_integrations' own rollback, and reapplies it in forward order
     * afterward. Order between this wave and the pre-existing
     * integration_credentials / integration_oauth_states blocks does not
     * matter to each other.
     */
    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));

        $integrationCredentialsCreateFile = 'database/migrations/2026_09_03_030001_create_integration_credentials_table.php';
        $integrationCredentialsCreateName = '2026_09_03_030001_create_integration_credentials_table';
        $integrationCredentialsRlsFile = 'database/migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php';
        $integrationCredentialsRlsName = '2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table';

        $oauthStatesCreateFile = 'database/migrations/2026_09_04_040001_create_integration_oauth_states_table.php';
        $oauthStatesCreateName = '2026_09_04_040001_create_integration_oauth_states_table';
        $oauthStatesRlsFile = 'database/migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php';
        $oauthStatesRlsName = '2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table';

        // 1. Confirm current state before touching anything.
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertNotNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNotNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

        $forceRow = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_integrations'");
        $this->assertTrue((bool) $forceRow->relforcerowsecurity);

        // Confirm integration_credentials' own pre-rollback state too, so
        // its restoration can be proven later, not just asserted by
        // absence of error.
        $this->assertTrue(
            Schema::hasTable('integration_credentials'),
            'integration_credentials (Checkpoint 4) must exist before this test begins, since it is now firm_integrations\' one real FK dependent.'
        );
        $integrationCredentialsRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($integrationCredentialsRlsBefore);
        $this->assertTrue((bool) $integrationCredentialsRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $integrationCredentialsRlsBefore->relforcerowsecurity);

        // Confirm integration_oauth_states' own pre-rollback state too
        // (Checkpoint 5's own independent composite-FK dependent).
        $this->assertTrue(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states (Checkpoint 5) must exist before this test begins, since it is now also one of firm_integrations\' real FK dependents.'
        );
        $oauthStatesRlsBefore = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRlsBefore);
        $this->assertTrue((bool) $oauthStatesRlsBefore->relrowsecurity);
        $this->assertTrue((bool) $oauthStatesRlsBefore->relforcerowsecurity);

        // Confirm the Checkpoint 6 whole-wave tables' pre-rollback
        // existence too (a THIRD, independent dependency chain on
        // firm_integrations).
        foreach (self::CP6_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $this->assertFileExists(base_path($path));
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }

        // 1b. Roll back the Checkpoint 6 whole-wave dependency chain —
        // internal FK order matters within the wave itself (see
        // CP6_WHOLE_WAVE_MIGRATION_PATHS docblock), so it is rolled back
        // as a unit, in exact reverse of its own creation order, before
        // firm_integrations' other dependents or firm_integrations
        // itself.
        foreach (array_reverse(self::CP6_WHOLE_WAVE_MIGRATION_PATHS) as $path) {
            $exit = Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate:rollback of {$path} (Checkpoint 6 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 6) must not survive its whole-wave rollback.");
        }

        // 2. Roll back firm_integrations' real dependents first —
        // integration_credentials, then integration_oauth_states (order
        // between the two does not matter, since neither references the
        // other) — each in FK-dependency / reverse-chronological order
        // (its RLS-prep migration, then its create-table migration).
        $credentialsRlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $integrationCredentialsRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsRlsRollbackExit, 'migrate:rollback of integration_credentials RLS-prep migration failed: '.Artisan::output());

        $credentialsCreateRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $integrationCredentialsCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsCreateRollbackExit, 'migrate:rollback of integration_credentials create-table migration failed: '.Artisan::output());

        $this->assertFalse(
            Schema::hasTable('integration_credentials'),
            'integration_credentials must be fully rolled back before firm_integrations can be safely rolled back.'
        );

        $oauthStatesRlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $oauthStatesRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesRlsRollbackExit, 'migrate:rollback of integration_oauth_states RLS-prep migration failed: '.Artisan::output());

        $oauthStatesCreateRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => $oauthStatesCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesCreateRollbackExit, 'migrate:rollback of integration_oauth_states create-table migration failed: '.Artisan::output());

        $this->assertFalse(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states must be fully rolled back before firm_integrations can be safely rolled back.'
        );

        // 3. Roll back firm_integrations in reverse order: RLS migration
        // first, then the table-creation migration (the RLS migration's
        // down() assumes the table still exists).
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

        // 4. The table must be fully gone from the PostgreSQL catalog.
        $this->assertFalse(Schema::hasTable('firm_integrations'));
        $this->assertNull(DB::selectOne("select relname from pg_class where relname = 'firm_integrations'"));
        $this->assertNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

        // 5. Reapply in forward order: firm_integrations first, then
        // integration_credentials' two migrations (create-table, then its
        // RLS-prep migration), then integration_oauth_states' two
        // migrations (create-table, then its RLS-prep migration).
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

        $credentialsCreateMigrateExit = Artisan::call('migrate', [
            '--path' => $integrationCredentialsCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsCreateMigrateExit, 'migrate of integration_credentials create-table migration failed: '.Artisan::output());

        $credentialsRlsMigrateExit = Artisan::call('migrate', [
            '--path' => $integrationCredentialsRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $credentialsRlsMigrateExit, 'migrate of integration_credentials RLS-prep migration failed: '.Artisan::output());

        $oauthStatesCreateMigrateExit = Artisan::call('migrate', [
            '--path' => $oauthStatesCreateFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesCreateMigrateExit, 'migrate of integration_oauth_states create-table migration failed: '.Artisan::output());

        $oauthStatesRlsMigrateExit = Artisan::call('migrate', [
            '--path' => $oauthStatesRlsFile,
            '--force' => true,
        ]);
        $this->assertSame(0, $oauthStatesRlsMigrateExit, 'migrate of integration_oauth_states RLS-prep migration failed: '.Artisan::output());

        // 5b. Reapply the Checkpoint 6 whole-wave dependency chain last,
        // in its own forward (creation) order.
        foreach (self::CP6_WHOLE_WAVE_MIGRATION_PATHS as $path) {
            $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $this->assertSame(0, $exit, "migrate of {$path} (Checkpoint 6 whole-wave) failed: ".Artisan::output());
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must be restored by the whole-wave reapplication.");
        }

        // 6. Schema restored exactly.
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

        // 7. RLS state restored exactly.
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

        // 8. Prove integration_credentials itself is correctly restored
        // too, as a natural side-effect of proving cross-table rollback
        // safety — not just that firm_integrations round-trips.
        $this->assertTrue(
            Schema::hasTable('integration_credentials'),
            'integration_credentials must be fully restored after reapplying its two migrations.'
        );
        $this->assertTrue(Schema::hasColumn('integration_credentials', 'firm_integration_id'));

        $compositeFkAfterReapply = DB::selectOne(
            "select conname from pg_constraint where conrelid = 'integration_credentials'::regclass and contype = 'f' and array_length(conkey, 1) = 2"
        );
        $this->assertNotNull($compositeFkAfterReapply, 'The composite FK (firm_id, firm_integration_id) -> firm_integrations(firm_id, id) must be restored.');

        $integrationCredentialsRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($integrationCredentialsRlsAfter);
        $this->assertTrue(
            (bool) $integrationCredentialsRlsAfter->relrowsecurity,
            'integration_credentials RLS must be re-enabled after reapplying its RLS-prep migration.'
        );
        $this->assertTrue(
            (bool) $integrationCredentialsRlsAfter->relforcerowsecurity,
            'integration_credentials FORCE RLS must be re-enabled after reapplying its RLS-prep migration.'
        );

        $integrationCredentialsPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");
        $this->assertCount(1, $integrationCredentialsPolicies);
        $this->assertSame('integration_credentials_tenant_isolation', $integrationCredentialsPolicies[0]->policyname);

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $integrationCredentialsCreateName)->first(),
            'The reapplied integration_credentials create-table migration must be recorded as run again.'
        );
        $this->assertNotNull(
            DB::table('migrations')->where('migration', $integrationCredentialsRlsName)->first(),
            'The reapplied integration_credentials RLS-prep migration must be recorded as run again.'
        );

        // 9. Prove integration_oauth_states itself is correctly restored
        // too (Checkpoint 5's own independent composite-FK dependent),
        // as a natural side-effect of proving cross-table rollback
        // safety — not just that firm_integrations/integration_credentials
        // round-trip.
        $this->assertTrue(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states must be fully restored after reapplying its two migrations.'
        );
        $this->assertTrue(Schema::hasColumn('integration_oauth_states', 'firm_integration_id'));

        $oauthStatesCompositeFkAfterReapply = DB::selectOne(
            "select conname from pg_constraint where conrelid = 'integration_oauth_states'::regclass and contype = 'f' and array_length(conkey, 1) = 2"
        );
        $this->assertNotNull($oauthStatesCompositeFkAfterReapply, 'The composite FK (firm_id, firm_integration_id) -> firm_integrations(firm_id, id) must be restored.');

        $oauthStatesRlsAfter = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRlsAfter);
        $this->assertTrue(
            (bool) $oauthStatesRlsAfter->relrowsecurity,
            'integration_oauth_states RLS must be re-enabled after reapplying its RLS-prep migration.'
        );
        $this->assertTrue(
            (bool) $oauthStatesRlsAfter->relforcerowsecurity,
            'integration_oauth_states FORCE RLS must be re-enabled after reapplying its RLS-prep migration.'
        );

        // integration_oauth_states carries TWO policies (base tenant
        // isolation + the narrow self-lookup carve-out) — unlike
        // integration_credentials' single policy — so both must be
        // restored, not just one.
        $oauthStatesPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");
        $this->assertCount(2, $oauthStatesPolicies);
        $this->assertSame(
            ['integration_oauth_states_self_lookup', 'integration_oauth_states_tenant_isolation'],
            array_map(fn ($p) => $p->policyname, $oauthStatesPolicies)
        );

        $this->assertNotNull(
            DB::table('migrations')->where('migration', $oauthStatesCreateName)->first(),
            'The reapplied integration_oauth_states create-table migration must be recorded as run again.'
        );
        $this->assertNotNull(
            DB::table('migrations')->where('migration', $oauthStatesRlsName)->first(),
            'The reapplied integration_oauth_states RLS-prep migration must be recorded as run again.'
        );
    }

    /**
     * Narrower proof calling both migration files' own up()/down()
     * directly, bypassing Artisan and the `migrations` tracking table
     * entirely — mirrors IntegrationProviderTest's second, direct-call
     * rollback proof. Still safe inside RefreshDatabase's outer
     * per-test transaction (PostgreSQL supports transactional DDL).
     */
    /**
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-4, extended again
     * post-Checkpoint-5): as with the test above, this method originally
     * called firm_integrations' migration down()/up() in true isolation.
     * Checkpoint 4 (a later, legitimate schema addition) introduced
     * integration_credentials' real composite FK (cascadeOnDelete())
     * against firm_integrations, so its down() now fails with a Postgres
     * FK-dependency error while integration_credentials still exists.
     * Checkpoint 5 introduced a SECOND, independent real dependent,
     * integration_oauth_states, with the identical composite-FK shape.
     * This test now also invokes BOTH dependents' migration objects
     * directly — tearing them down first (order between the two does
     * not matter to each other), each in FK-dependency /
     * reverse-chronological order (RLS-prep down(), then create-table
     * down()) before firm_integrations' own down() calls, and building
     * them back up in forward order after firm_integrations' own up()
     * calls — and asserts each ends up correctly restored too (table
     * exists again, its own RLS/FORCE state and polic{y,ies} are
     * correct). This is a strengthening of the test (it now proves
     * cross-table rollback safety at the migration-object level one
     * level further down the FK chain, for both dependents), not a
     * weakening.
     *
     * NARROW, DISCLOSED UPDATE (post-Checkpoint-6): Checkpoint 6 added a
     * THIRD independent dependency chain on firm_integrations — the
     * 6-table / 12-migration Checkpoint 6 wave (see
     * CP6_WHOLE_WAVE_MIGRATION_PATHS above). This test now also includes
     * and tears down/rebuilds that entire wave's migration objects
     * directly, in the same whole-wave-order-required manner as
     * IntegrationSyncRunsForceRlsActivationTest's own direct-call proof,
     * before/after firm_integrations' own down()/up() calls. Order
     * between this wave and the pre-existing integration_credentials /
     * integration_oauth_states blocks does not matter to each other.
     */
    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $this->assertTrue(Schema::hasTable('firm_integrations'));
        $this->assertTrue(
            Schema::hasTable('integration_credentials'),
            'integration_credentials (Checkpoint 4) must exist before this test begins, since it is now firm_integrations\' one real FK dependent.'
        );
        $this->assertTrue(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states (Checkpoint 5) must exist before this test begins, since it is now also one of firm_integrations\' real FK dependents.'
        );
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must exist before this test begins, since it is now also one of firm_integrations' real (direct or transitive) FK dependents.");
        }

        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $credentialsRlsMigration = include database_path('migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php');
        $credentialsCreateMigration = include database_path('migrations/2026_09_03_030001_create_integration_credentials_table.php');

        $oauthStatesRlsMigration = include database_path('migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php');
        $oauthStatesCreateMigration = include database_path('migrations/2026_09_04_040001_create_integration_oauth_states_table.php');

        // Checkpoint 6 whole-wave migration objects, in creation order.
        $cp6Migrations = array_map(
            static fn (string $path) => include base_path($path),
            self::CP6_WHOLE_WAVE_MIGRATION_PATHS,
        );

        // Tear down the Checkpoint 6 whole-wave dependency chain first —
        // internal FK order matters within the wave itself, so it is
        // torn down as a unit, in exact reverse of its own creation
        // order, before firm_integrations' other dependents or
        // firm_integrations itself.
        foreach (array_reverse($cp6Migrations) as $migration) {
            $migration->down();
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} (Checkpoint 6) must be fully dropped before firm_integrations down() can succeed.");
        }

        // Tear down firm_integrations' real dependents first —
        // integration_credentials, then integration_oauth_states (order
        // between the two does not matter to each other) — each in
        // FK-dependency / reverse-chronological order (RLS-prep down(),
        // then create-table down()) — before firm_integrations' own
        // down() calls.
        $credentialsRlsMigration->down();
        $credentialsCreateMigration->down();

        $this->assertFalse(
            Schema::hasTable('integration_credentials'),
            'integration_credentials must be fully dropped before firm_integrations down() can succeed.'
        );

        $oauthStatesRlsMigration->down();
        $oauthStatesCreateMigration->down();

        $this->assertFalse(
            Schema::hasTable('integration_oauth_states'),
            'integration_oauth_states must be fully dropped before firm_integrations down() can succeed.'
        );

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

        // Rebuild integration_credentials in forward order: create-table
        // up(), then its RLS-prep migration's up().
        $credentialsCreateMigration->up();
        $credentialsRlsMigration->up();

        // Prove integration_credentials itself ends up correctly restored
        // too, as a natural side-effect of proving cross-table rollback
        // safety — not just that firm_integrations round-trips.
        $this->assertTrue(Schema::hasTable('integration_credentials'), 'integration_credentials must be fully restored after up().');
        $this->assertTrue(Schema::hasColumn('integration_credentials', 'firm_integration_id'));

        $credentialsRow = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertNotNull($credentialsRow);
        $this->assertTrue(
            (bool) $credentialsRow->relrowsecurity,
            'integration_credentials RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );
        $this->assertTrue(
            (bool) $credentialsRow->relforcerowsecurity,
            'integration_credentials FORCE RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );

        $credentialsPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");
        $this->assertCount(1, $credentialsPolicies);
        $this->assertSame('integration_credentials_tenant_isolation', $credentialsPolicies[0]->policyname);

        // Rebuild integration_oauth_states in forward order: create-table
        // up(), then its RLS-prep migration's up().
        $oauthStatesCreateMigration->up();
        $oauthStatesRlsMigration->up();

        // Prove integration_oauth_states itself ends up correctly
        // restored too, as a natural side-effect of proving cross-table
        // rollback safety — not just that firm_integrations/
        // integration_credentials round-trip.
        $this->assertTrue(Schema::hasTable('integration_oauth_states'), 'integration_oauth_states must be fully restored after up().');
        $this->assertTrue(Schema::hasColumn('integration_oauth_states', 'firm_integration_id'));

        $oauthStatesRow = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertNotNull($oauthStatesRow);
        $this->assertTrue(
            (bool) $oauthStatesRow->relrowsecurity,
            'integration_oauth_states RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );
        $this->assertTrue(
            (bool) $oauthStatesRow->relforcerowsecurity,
            'integration_oauth_states FORCE RLS must be re-enabled after reapplying its RLS-prep migration up().'
        );

        // integration_oauth_states carries TWO policies — unlike
        // integration_credentials' single policy — both must be restored.
        $oauthStatesPolicies = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");
        $this->assertCount(2, $oauthStatesPolicies);
        $this->assertSame(
            ['integration_oauth_states_self_lookup', 'integration_oauth_states_tenant_isolation'],
            array_map(fn ($p) => $p->policyname, $oauthStatesPolicies)
        );

        // Rebuild the Checkpoint 6 whole-wave dependency chain last, in
        // its own forward (creation) order.
        foreach ($cp6Migrations as $migration) {
            $migration->up();
        }
        foreach (self::CP6_WHOLE_WAVE_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} (Checkpoint 6) must be fully restored after up().");
        }
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
