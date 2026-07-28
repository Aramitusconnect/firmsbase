<?php

declare(strict_types=1);

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\Matter;
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
 * ClientPortalMatterGrantsForceRlsActivationTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on"),
 * Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2.6.3). Proves the
 * standard FORCE ROW LEVEL SECURITY activation for
 * client_portal_matter_grants — the EXPLICIT client-to-matter
 * portal-visibility grant table, a genuine direct-tenant table (own
 * NOT NULL firm_id column, no bootstrap problem unlike
 * client_portal_users/clients) — mirroring this mission's own
 * established *ForceRlsActivationTest.php pattern
 * (IntegrationSyncCursorsForceRlsActivationTest is the structural
 * template).
 *
 * Required by SchemaTenantFirewallTest::test_check_5_every_forced_table_has_a_matching_activation_test_file
 * — RowLevelSecurityCoverageMappingService::forcedTables() discovers
 * this table by scanning for a `*_force_rls_on_*_table.php` migration
 * declaring `private const TABLE = 'client_portal_matter_grants'`
 * (database/migrations/2026_09_24_180005_prepare_row_level_security_and_force_rls_on_client_portal_matter_grants_table.php),
 * so check_5 requires a matching ClientPortalMatterGrantsForceRlsActivationTest.php
 * to exist somewhere under tests/ — this file.
 *
 * RESOLVED (was a disclosed check_4/check_5 mismatch, now moot): the
 * sibling client_portal_users FORCE RLS migration used to ALSO match
 * the same `*_force_rls_on_*_table.php` glob and ALSO declare its own
 * `private const TABLE = 'client_portal_users'`, which meant
 * forcedTables()'s purely-mechanical migration scan discovered
 * client_portal_users too, requiring a
 * ClientPortalUsersForceRlsActivationTest.php that never existed —
 * despite RowLevelSecurityCoverageMappingService.php's own governing
 * documentation classifying client_portal_users as not needing one.
 * That mismatch stemmed from client_portal_users originally carrying
 * real FORCE ROW LEVEL SECURITY, which is itself a confirmed defect
 * (see ClientPortalAuthenticationTest's own docblock): FORCING RLS on
 * the credential/identity table Auth::attempt() must look up BY EMAIL
 * with no context at all made client login structurally impossible.
 * client_portal_users has since been reclassified System (identical
 * treatment to `users`), its FORCE RLS migration deleted entirely —
 * forcedTables() no longer discovers it at all, so check_5 no longer
 * requires any activation test for it. This file's own job
 * (client_portal_matter_grants, a genuine direct-tenant table, own
 * NOT NULL firm_id column, no bootstrap problem) is unaffected.
 */
class ClientPortalMatterGrantsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_24_180004_create_client_portal_matter_grants_table.php';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_24_180005_prepare_row_level_security_and_force_rls_on_client_portal_matter_grants_table.php';

    private const POLICY_NAME = 'client_portal_matter_grants_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id', 'uuid', 'firm_id', 'client_id', 'matter_id', 'granted_by',
        'granted_at', 'revoked_at', 'created_at', 'updated_at',
    ];

    // ------------------------------------------------------------
    // 1. Schema correctness
    // ------------------------------------------------------------

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('client_portal_matter_grants'));
    }

    public function test_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('client_portal_matter_grants');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns);
    }

    public function test_foreign_keys_exist_on_firm_client_matter_and_granted_by(): void
    {
        $rows = DB::select(
            "select conname, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'client_portal_matter_grants'::regclass and contype = 'f'"
        );

        $byTable = collect($rows)->pluck('foreign_table')->all();

        $this->assertContains('firms', $byTable);
        $this->assertContains('clients', $byTable);
        $this->assertContains('matters', $byTable);
        $this->assertContains('users', $byTable);
    }

    public function test_partial_unique_index_exists_on_client_id_matter_id_where_not_revoked(): void
    {
        $rows = DB::select("select indexdef from pg_indexes where tablename = 'client_portal_matter_grants'");

        $found = false;
        foreach ($rows as $row) {
            if (str_contains($row->indexdef, 'UNIQUE') && str_contains($row->indexdef, 'client_id')
                && str_contains($row->indexdef, 'matter_id') && str_contains($row->indexdef, 'revoked_at IS NULL')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Expected a partial UNIQUE index on (client_id, matter_id) WHERE revoked_at IS NULL.');
    }

    public function test_partial_unique_index_rejects_a_second_simultaneously_active_grant_for_the_same_pair(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());
    }

    public function test_partial_unique_index_allows_a_new_active_grant_after_the_prior_one_is_revoked(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $first = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());
        $this->runWithFirmContext($firm, fn () => $first->update(['revoked_at' => now()]));

        $second = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forClientAndMatter($client, $matter)->create());

        $this->assertNotNull($second->id);
        $this->assertNull($second->revoked_at);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'client_portal_matter_grants'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'client_portal_matter_grants'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'client_portal_matter_grants'");

        $this->assertCount(1, $rows);
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'client_portal_matter_grants'::regclass and polname = ?",
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
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('client_portal_matter_grants')->count());
    }

    public function test_missing_tenant_context_cannot_insert(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('client_portal_matter_grants')->insert($this->rawRowAttributes($firm, $client, $matter));
    }

    public function test_firm_a_context_can_read_its_own_grant(): void
    {
        $firm = Firm::factory()->create();
        $grant = $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forFirm($firm)->create());

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('client_portal_matter_grants')->pluck('id')->all());

        $this->assertSame([$grant->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_grant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => ClientPortalMatterGrant::factory()->forFirm($firmA)->create());
        $grantB = $this->runWithFirmContext($firmB, fn () => ClientPortalMatterGrant::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('client_portal_matter_grants')->pluck('id')->all());

        $this->assertNotContains($grantB->id, $visibleIds);
    }

    public function test_firm_a_cannot_insert_a_grant_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientB, $matterB) {
            DB::table('client_portal_matter_grants')->insert($this->rawRowAttributes($firmB, $clientB, $matterB));
        });
    }

    public function test_firm_a_cannot_update_firm_b_grant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $grantB = $this->runWithFirmContext($firmB, fn () => ClientPortalMatterGrant::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('client_portal_matter_grants')->where('id', $grantB->id)->update(['revoked_at' => now()]),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('client_portal_matter_grants')->where('id', $grantB->id)->value('revoked_at'));
        $this->assertNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_delete_firm_b_grant(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $grantB = $this->runWithFirmContext($firmB, fn () => ClientPortalMatterGrant::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('client_portal_matter_grants')->where('id', $grantB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('client_portal_matter_grants')->where('id', $grantB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $grant = $this->runWithFirmContext($firmA, fn () => ClientPortalMatterGrant::factory()->forFirm($firmA)->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($grant, $firmB) {
            DB::table('client_portal_matter_grants')->where('id', $grant->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => ClientPortalMatterGrant::factory()->forFirm($firm)->create());

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
        $this->assertTrue(Schema::hasTable('client_portal_matter_grants'));

        $rlsRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsRollbackExit, Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'client_portal_matter_grants'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity);
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'client_portal_matter_grants'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback);

        $tableRollbackExit = Artisan::call('migrate:rollback', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableRollbackExit, Artisan::output());
        $this->assertFalse(Schema::hasTable('client_portal_matter_grants'));

        $tableMigrateExit = Artisan::call('migrate', ['--path' => self::TABLE_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $tableMigrateExit, Artisan::output());
        $rlsMigrateExit = Artisan::call('migrate', ['--path' => self::RLS_MIGRATION_PATH, '--force' => true]);
        $this->assertSame(0, $rlsMigrateExit, Artisan::output());

        $this->assertTrue(Schema::hasTable('client_portal_matter_grants'));

        $columns = Schema::getColumnListing('client_portal_matter_grants');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'client_portal_matter_grants'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'client_portal_matter_grants'");
        $this->assertCount(1, $policiesAfterReapply);
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('client_portal_matter_grants'));

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('client_portal_matter_grants'));

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'client_portal_matter_grants'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_correctly(): void
    {
        $this->assertSame('client_portal_matter_grants', (new ClientPortalMatterGrant)->getTable());
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $this->assertArrayHasKey(BelongsToTenant::class, class_uses_recursive(ClientPortalMatterGrant::class));
    }

    public function test_factory_produces_valid_rows(): void
    {
        $firm = Firm::factory()->create();

        $grants = $this->runWithFirmContext($firm, fn () => collect(range(1, 3))
            ->map(fn () => ClientPortalMatterGrant::factory()->forFirm($firm)->create()));

        $this->assertSame(3, $grants->pluck('id')->unique()->count());
        foreach ($grants as $grant) {
            $this->assertSame($firm->id, $grant->firm_id);
            $this->assertNotNull($grant->client_id);
            $this->assertNotNull($grant->matter_id);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, Client $client, Matter $matter): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'matter_id' => $matter->id,
            'granted_by' => null,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
