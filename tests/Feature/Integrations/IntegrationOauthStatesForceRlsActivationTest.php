<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOAuthState;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
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
 * IntegrationOauthStatesForceRlsActivationTest — Checkpoint 5
 * (checkpoint-00-final-specification.md §5 table #4;
 * frozen-design-post-review.md; agent-h-security-architecture-review.md).
 * Mirrors IntegrationCredentialsForceRlsActivationTest's structural/
 * assertion conventions exactly (direct pg_class/pg_policies/pg_policy
 * catalog queries, runWithFirmContext() for every tenant-scoped
 * read/write, paired Artisan-call / direct-include migration rollback
 * proofs), extended for this table's TWO RLS policies (base tenant
 * isolation + the narrow FOR SELECT-only self-lookup carve-out) rather
 * than IntegrationCredentials' single policy.
 */
class IntegrationOauthStatesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_04_040001_create_integration_oauth_states_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_04_040001_create_integration_oauth_states_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_04_040002_prepare_row_level_security_and_force_rls_on_integration_oauth_states_table';

    private const TENANT_POLICY_NAME = 'integration_oauth_states_tenant_isolation';

    private const SELF_LOOKUP_POLICY_NAME = 'integration_oauth_states_self_lookup';

    private const EXPECTED_COLUMNS = [
        'id',
        'firm_id',
        'firm_integration_id',
        'initiating_user_id',
        'initiating_firm_user_id',
        'opaque_token_hash',
        'redirect_uri',
        'verifier_ciphertext',
        'encryption_key_id',
        'expires_at',
        'consumed_at',
        'created_at',
        'updated_at',
    ];

    /** @var array<int, int> firm_id => TenantEncryptionKey id */
    private array $encryptionKeyIds = [];

    // ------------------------------------------------------------
    // 1. Schema correctness, ownership, and constraints
    // ------------------------------------------------------------

    public function test_integration_oauth_states_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_oauth_states'));
    }

    public function test_integration_oauth_states_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_oauth_states');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS;
        sort($expected);

        $this->assertSame($expected, $columns, 'integration_oauth_states must have exactly the documented column set — no more, no fewer.');
    }

    public function test_integration_oauth_states_has_no_uuid_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('integration_oauth_states', 'uuid'),
            'This is internal, single-use, security-sensitive routing state — it must never gain a queryable uuid column (Agent H review item 7).'
        );
    }

    public function test_firm_id_foreign_key_references_firms_id(): void
    {
        $row = $this->singleColumnForeignKeyTarget('firm_id');

        $this->assertNotNull($row);
        $this->assertSame('firms', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_initiating_user_id_foreign_key_references_users_id(): void
    {
        $row = $this->singleColumnForeignKeyTarget('initiating_user_id');

        $this->assertNotNull($row);
        $this->assertSame('users', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_initiating_firm_user_id_foreign_key_references_firm_users_id(): void
    {
        $row = $this->singleColumnForeignKeyTarget('initiating_firm_user_id');

        $this->assertNotNull($row);
        $this->assertSame('firm_users', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_encryption_key_id_foreign_key_references_tenant_encryption_keys_id(): void
    {
        $row = $this->singleColumnForeignKeyTarget('encryption_key_id');

        $this->assertNotNull($row);
        $this->assertSame('tenant_encryption_keys', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    /**
     * There are 4 single-column FKs (firm_id->firms,
     * initiating_user_id->users, initiating_firm_user_id->firm_users,
     * encryption_key_id->tenant_encryption_keys) PLUS 1 composite FK
     * (firm_id, firm_integration_id) -> firm_integrations, for 5 total.
     */
    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists_in_the_catalog(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_oauth_states'::regclass and contype = 'f'
             order by conname"
        );

        $this->assertCount(
            5,
            $constraints,
            'integration_oauth_states must have exactly 5 FK constraints: firm_id->firms, initiating_user_id->users, '.
            'initiating_firm_user_id->firm_users, encryption_key_id->tenant_encryption_keys, and the composite '.
            '(firm_id, firm_integration_id)->firm_integrations.'
        );

        $compositeRows = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2));
        $this->assertCount(1, $compositeRows, 'Exactly one FK constraint must be composite (span 2 columns).');
        $this->assertSame('firm_integrations', $compositeRows[0]->foreign_table);
    }

    public function test_exactly_five_foreign_key_constraints_exist_including_one_composite(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_oauth_states'::regclass and contype = 'f'
             order by conname"
        );

        $this->assertCount(5, $constraints);

        $compositeRows = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2));

        $this->assertCount(1, $compositeRows, 'Exactly one FK constraint must be composite (span 2 columns).');
        $this->assertSame('firm_integrations', $compositeRows[0]->foreign_table);
    }

    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->connectionForFirm($firmB);
        $firmUserA = $this->firmUserFor($firmA);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB, $firmUserA) {
            DB::table('integration_oauth_states')->insert(
                $this->rawRowAttributes($firmA, $connectionB, $firmUserA, $this->encryptionKeyIdFor($firmA))
            );
        });
    }

    public function test_inserting_a_nonexistent_firm_integration_id_is_rejected_by_the_foreign_key(): void
    {
        $firm = $this->firmWithActiveKey();
        $firmUser = $this->firmUserFor($firm);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firm, function () use ($firm, $firmUser) {
            DB::table('integration_oauth_states')->insert([
                'firm_id' => $firm->id,
                'firm_integration_id' => 999999999,
                'initiating_user_id' => $firmUser->user_id,
                'initiating_firm_user_id' => $firmUser->id,
                'opaque_token_hash' => hash('sha256', Str::random(43)),
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_opaque_token_hash_is_unique(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $firmUser = $this->firmUserFor($firm);
        $hash = hash('sha256', Str::random(43));

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $firmUser, $hash) {
            DB::table('integration_oauth_states')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection, $firmUser, $this->encryptionKeyIdFor($firm)),
                ['opaque_token_hash' => $hash],
            ));
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $firmUser, $hash) {
            DB::table('integration_oauth_states')->insert(array_merge(
                $this->rawRowAttributes($firm, $connection, $firmUser, $this->encryptionKeyIdFor($firm)),
                ['opaque_token_hash' => $hash],
            ));
        });
    }

    public function test_expires_at_is_never_null_on_insert(): void
    {
        $state = IntegrationOAuthState::factory()->create();

        $this->assertNotNull($state->expires_at);
    }

    public function test_consumed_at_is_null_on_creation(): void
    {
        $state = IntegrationOAuthState::factory()->create();

        $this->assertNull($state->consumed_at);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_integration_oauth_states_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_oauth_states'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_integration_oauth_states_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'This table holds a PKCE verifier and, until consumed, an implicit path to fresh credentials — FORCE RLS is non-negotiable.'
        );
    }

    public function test_the_runtime_database_role_has_no_bypassrls(): void
    {
        $row = DB::selectOne(
            'select rolbypassrls from pg_roles where rolname = current_user'
        );

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->rolbypassrls, 'The runtime DB role must never have BYPASSRLS — FORCE RLS is meaningless otherwise.');
    }

    public function test_integration_oauth_states_has_exactly_two_row_level_security_policies(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");

        $this->assertCount(2, $rows, 'integration_oauth_states must have exactly two policies — the base tenant policy plus the narrow self-lookup carve-out.');
        $this->assertSame(
            [self::SELF_LOOKUP_POLICY_NAME, self::TENANT_POLICY_NAME],
            array_map(fn ($r) => $r->policyname, $rows)
        );
    }

    public function test_the_tenant_isolation_policy_has_both_using_and_with_check_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_oauth_states'::regclass and polname = ?",
            [self::TENANT_POLICY_NAME]
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_the_self_lookup_policy_is_for_select_only_scoped_by_initiating_user_id(): void
    {
        $row = DB::selectOne(
            "select polcmd as cmd, pg_get_expr(polqual, polrelid) as using_expr, polwithcheck
             from pg_policy where polrelid = 'integration_oauth_states'::regclass and polname = ?",
            [self::SELF_LOOKUP_POLICY_NAME]
        );

        $this->assertNotNull($row);
        $this->assertSame('r', $row->cmd, 'The self-lookup policy must be FOR SELECT only (cmd = "r"), never consulted for INSERT/UPDATE/DELETE.');

        $expected = "(initiating_user_id = (NULLIF(current_setting('app.current_user_id'::text, true), ''::text))::bigint)";
        $this->assertSame($expected, $row->using_expr);
        $this->assertNull($row->polwithcheck, 'A FOR SELECT-only policy has no WITH CHECK clause at all.');
    }

    // ------------------------------------------------------------
    // 3. Cross-firm tenant isolation
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_integration_oauth_states(): void
    {
        IntegrationOAuthState::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, DB::table('integration_oauth_states')->count());
    }

    public function test_missing_tenant_context_cannot_insert_integration_oauth_states(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $firmUser = $this->firmUserFor($firm);

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_oauth_states')->insert(
            $this->rawRowAttributes($firm, $connection, $firmUser, $this->encryptionKeyIdFor($firm))
        );
    }

    public function test_firm_a_context_can_read_its_own_oauth_state(): void
    {
        $firm = $this->firmWithActiveKey();
        $state = $this->stateForFirm($firm);

        $visibleIds = $this->runWithFirmContext($firm, fn () => DB::table('integration_oauth_states')->pluck('id')->all());

        $this->assertSame([$state->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_oauth_state(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $this->stateForFirm($firmA);
        $stateB = $this->stateForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_oauth_states')->pluck('id')->all());

        $this->assertNotContains($stateB->id, $visibleIds);
    }

    public function test_firm_a_cannot_update_firm_b_oauth_state(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $stateB = $this->stateForFirm($firmB);

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_oauth_states')->where('id', $stateB->id)->update(['consumed_at' => now()]),
        );

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_oauth_states')->where('id', $stateB->id)->value('consumed_at'));
        $this->assertNull($reReadAsFirmB, 'Firm A must not be able to consume Firm B\'s OAuth state.');
    }

    public function test_firm_a_cannot_delete_firm_b_oauth_state(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $stateB = $this->stateForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, fn () => DB::table('integration_oauth_states')->where('id', $stateB->id)->delete());

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => DB::table('integration_oauth_states')->where('id', $stateB->id)->first());
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_an_oauth_state_claiming_firm_b_ownership(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->connectionForFirm($firmB);
        $firmUserB = $this->firmUserFor($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB, $firmUserB) {
            DB::table('integration_oauth_states')->insert(
                $this->rawRowAttributes($firmB, $connectionB, $firmUserB, $this->encryptionKeyIdFor($firmB))
            );
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $state = $this->stateForFirm($firmA);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($state, $firmB) {
            DB::table('integration_oauth_states')->where('id', $state->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = $this->firmWithActiveKey();
        (new TenantContextService())->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::factory()->forFirmIntegration($this->connectionForFirm($firm))->create());

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
    // 4. Self-lookup carve-out
    //
    // Every test below that exercises withUserContext() ALONE first
    // calls clearDatabaseTenantContext() explicitly. This is required,
    // not defensive boilerplate: IntegrationOAuthStateFactory::create()
    // and FirmIntegrationFactory::create() (used transitively by
    // stateForFirm()/connectionForFirm()/firmWithActiveKey() below)
    // call TenantContextService::setDatabaseTenantContextForFirmId()
    // directly to satisfy the base tenant-isolation policy's WITH CHECK
    // during insert — but, unlike runWithFirmContext(), that call never
    // restores/clears app.current_firm_id afterward. Under
    // RefreshDatabase's single wrapping test-transaction, that setting
    // is pushed via SET LOCAL and therefore remains live for the REST
    // of the test method, not just the insert. Every other test in this
    // suite re-establishes its own firm context via runWithFirmContext()
    // before reading, so the leftover value is silently overwritten and
    // never observed. These self-lookup tests are the first to require
    // a session with genuinely NO firm context at all (only
    // app.current_user_id — the real precondition for the OAuth
    // callback path, which runs before any firm is known) within the
    // SAME test transaction as fixture setup, so the leak must be
    // undone explicitly here or the base tenant_isolation policy (OR'd
    // with the self-lookup policy for SELECT, and solely governing
    // INSERT/UPDATE) spuriously authorizes access via the stale firm_id
    // rather than genuinely proving the self-lookup policy in isolation.
    // ------------------------------------------------------------

    public function test_self_lookup_policy_allows_the_initiating_user_to_read_their_own_pending_state_via_user_context_alone(): void
    {
        $firm = $this->firmWithActiveKey();
        $firmUser = $this->firmUserFor($firm);
        $state = $this->stateForFirm($firm, $firmUser);

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();
        $visibleIds = $tenantContext->withUserContext($firmUser->user_id, fn () => DB::table('integration_oauth_states')->pluck('id')->all());

        $this->assertContains($state->id, $visibleIds);
    }

    public function test_self_lookup_policy_does_not_allow_a_different_user_to_read_another_users_pending_state(): void
    {
        $firm = $this->firmWithActiveKey();
        $ownerFirmUser = $this->firmUserFor($firm);
        $otherFirmUser = $this->firmUserFor($firm);
        $ownerState = $this->stateForFirm($firm, $ownerFirmUser);

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();
        $visibleIds = $tenantContext->withUserContext($otherFirmUser->user_id, fn () => DB::table('integration_oauth_states')->pluck('id')->all());

        $this->assertNotContains($ownerState->id, $visibleIds, 'A different user\'s session context must not reveal another user\'s pending OAuth state, even in the same firm.');
    }

    public function test_self_lookup_policy_is_select_only_and_user_context_alone_cannot_insert_a_state_row(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $firmUser = $this->firmUserFor($firm);

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $tenantContext->withUserContext($firmUser->user_id, function () use ($firm, $connection, $firmUser) {
            DB::table('integration_oauth_states')->insert(
                $this->rawRowAttributes($firm, $connection, $firmUser, $this->encryptionKeyIdFor($firm))
            );
        });
    }

    public function test_self_lookup_policy_is_select_only_and_user_context_alone_cannot_update_or_consume_a_state_row(): void
    {
        $firm = $this->firmWithActiveKey();
        $firmUser = $this->firmUserFor($firm);
        $state = $this->stateForFirm($firm, $firmUser);

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();
        $affected = $tenantContext->withUserContext(
            $firmUser->user_id,
            fn () => DB::table('integration_oauth_states')->where('id', $state->id)->update(['consumed_at' => now()]),
        );

        $this->assertSame(0, $affected, 'User context alone must never be able to self-consume a state — only the firm-context-bootstrapped atomic claim may write consumed_at.');

        $reRead = $this->runWithFirmContext($firm, fn () => DB::table('integration_oauth_states')->where('id', $state->id)->value('consumed_at'));
        $this->assertNull($reRead);
    }

    public function test_self_lookup_policy_cannot_be_used_to_change_firm_id_or_initiating_user_id(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $firmUser = $this->firmUserFor($firmA);
        $otherFirmUser = $this->firmUserFor($firmA);
        $state = $this->stateForFirm($firmA, $firmUser);

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();

        $affectedFirmIdChange = $tenantContext->withUserContext(
            $firmUser->user_id,
            fn () => DB::table('integration_oauth_states')->where('id', $state->id)->update(['firm_id' => $firmB->id]),
        );
        $affectedUserIdChange = $tenantContext->withUserContext(
            $firmUser->user_id,
            fn () => DB::table('integration_oauth_states')->where('id', $state->id)->update(['initiating_user_id' => $otherFirmUser->user_id]),
        );

        $this->assertSame(0, $affectedFirmIdChange);
        $this->assertSame(0, $affectedUserIdChange);

        $fresh = $this->runWithFirmContext($firmA, fn () => DB::table('integration_oauth_states')->where('id', $state->id)->first());
        $this->assertSame($firmA->id, $fresh->firm_id);
        $this->assertSame($firmUser->user_id, $fresh->initiating_user_id);
    }

    /**
     * Concrete "cannot enumerate" proof (frozen design §26): loop over a
     * range of known-existing IDs belonging to OTHER users/firms under
     * one withUserContext() session, and assert the visible set is
     * exactly that one caller's own rows and nothing else — not merely
     * that a SINGLE other row happens to be invisible.
     */
    public function test_user_context_alone_via_self_lookup_cannot_enumerate_other_users_pending_states_by_scanning_sequential_ids(): void
    {
        $firm = $this->firmWithActiveKey();
        $caller = $this->firmUserFor($firm);
        $ownState = $this->stateForFirm($firm, $caller);

        $otherStateIds = [];
        for ($i = 0; $i < 5; $i++) {
            $otherFirm = $this->firmWithActiveKey();
            $otherFirmUser = $this->firmUserFor($otherFirm);
            $otherStateIds[] = $this->stateForFirm($otherFirm, $otherFirmUser)->id;
        }

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();
        $visibleIds = $tenantContext->withUserContext($caller->user_id, fn () => DB::table('integration_oauth_states')->pluck('id')->all());

        $this->assertSame([$ownState->id], $visibleIds, 'A single withUserContext() session must reveal EXACTLY the caller\'s own rows, never any subset of other users\' rows.');

        foreach ($otherStateIds as $otherId) {
            $this->assertNotContains($otherId, $visibleIds);
        }
    }

    /**
     * Reading a row via the self-lookup carve-out is not equivalent to
     * being able to CONSUME it — the correct raw-token hash match (an
     * explicit WHERE clause in application code) and the firm-context-
     * bootstrapped atomic claim are both still required.
     */
    public function test_the_self_lookup_carve_out_cannot_be_combined_with_a_wrong_hash_to_complete_someone_elses_state(): void
    {
        $firm = $this->firmWithActiveKey();
        $firmUser = $this->firmUserFor($firm);
        $this->stateForFirm($firm, $firmUser);

        $tenantContext = new TenantContextService();
        $tenantContext->clearDatabaseTenantContext();

        // The caller is authenticated as the correct user (self-lookup
        // would show them the row), but queries with an unrelated hash
        // — the same shape IntegrationOAuthStateService::resolveAndConsume()
        // uses — proving the hash filter, not the RLS predicate alone,
        // is what actually resolves a specific state.
        $row = $tenantContext->withUserContext(
            $firmUser->user_id,
            fn () => DB::table('integration_oauth_states')->where('opaque_token_hash', hash('sha256', 'a-totally-wrong-raw-state'))->first(),
        );

        $this->assertNull($row);
    }

    // ------------------------------------------------------------
    // 5. Migration rollback and reapplication
    // ------------------------------------------------------------

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));

        $this->assertTrue(Schema::hasTable('integration_oauth_states'));
        $this->assertNotNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNotNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

        $forceRow = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertTrue((bool) $forceRow->relforcerowsecurity);

        $rlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => self::RLS_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $rlsRollbackExit, 'migrate:rollback (RLS migration) failed: '.Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity, 'Rolling back the RLS migration must fully disable RLS, not merely clear FORCE.');
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        // Both policies must be gone — not just the first one checked
        // (new relative to the single-policy integration_credentials
        // precedent).
        $tenantPolicyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_oauth_states'::regclass and polname = ?",
            [self::TENANT_POLICY_NAME]
        );
        $selfLookupPolicyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_oauth_states'::regclass and polname = ?",
            [self::SELF_LOOKUP_POLICY_NAME]
        );
        $this->assertNull($tenantPolicyAfterRollback, 'Rolling back the RLS migration must drop the tenant isolation policy.');
        $this->assertNull($selfLookupPolicyAfterRollback, 'Rolling back the RLS migration must ALSO drop the self-lookup policy, not just the first one.');

        $tableRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => self::TABLE_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $tableRollbackExit, 'migrate:rollback (table migration) failed: '.Artisan::output());

        $this->assertFalse(Schema::hasTable('integration_oauth_states'));
        $this->assertNull(DB::selectOne("select relname from pg_class where relname = 'integration_oauth_states'"));
        $this->assertNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

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

        $this->assertTrue(Schema::hasTable('integration_oauth_states'));

        $columns = Schema::getColumnListing('integration_oauth_states');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $expiresAtIndex = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_oauth_states' and indexname like '%expires_at%'"
        );
        $this->assertNotNull($expiresAtIndex, 'The index on expires_at must be restored.');

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");
        $this->assertCount(2, $policiesAfterReapply);
        $this->assertSame(
            [self::SELF_LOOKUP_POLICY_NAME, self::TENANT_POLICY_NAME],
            array_map(fn ($p) => $p->policyname, $policiesAfterReapply)
        );

        $this->assertNotNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNotNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());
    }

    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $this->assertTrue(Schema::hasTable('integration_oauth_states'));

        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_oauth_states'), 'Table must be fully dropped after both down() calls.');

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('integration_oauth_states'), 'Table must be fully restored after both up() calls.');

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_oauth_states'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);

        $policies = DB::select("select policyname from pg_policies where tablename = 'integration_oauth_states' order by policyname");
        $this->assertCount(2, $policies);
        $this->assertSame(
            [self::SELF_LOOKUP_POLICY_NAME, self::TENANT_POLICY_NAME],
            array_map(fn ($p) => $p->policyname, $policies)
        );
    }

    // ------------------------------------------------------------
    // 6. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_integration_oauth_states(): void
    {
        $model = new IntegrationOAuthState();

        $this->assertSame('integration_oauth_states', $model->getTable());
    }

    public function test_model_fillable_contains_exactly_the_expected_fields(): void
    {
        $model = new IntegrationOAuthState();

        $expected = [
            'firm_id',
            'firm_integration_id',
            'initiating_user_id',
            'initiating_firm_user_id',
            'opaque_token_hash',
            'redirect_uri',
            'verifier_ciphertext',
            'encryption_key_id',
            'expires_at',
            'consumed_at',
        ];

        $fillable = $model->getFillable();

        sort($fillable);
        sort($expected);

        $this->assertSame($expected, $fillable);
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('created_at', $fillable);
        $this->assertNotContains('updated_at', $fillable);
    }

    public function test_model_uses_belongs_to_tenant_trait(): void
    {
        $traits = class_uses_recursive(IntegrationOAuthState::class);

        $this->assertArrayHasKey(BelongsToTenant::class, $traits);
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new IntegrationOAuthState();

        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
    }

    public function test_casts_are_correct(): void
    {
        $firm = $this->firmWithActiveKey();
        $state = $this->stateForFirm($firm);

        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->findOrFail($state->id));

        $this->assertInstanceOf(Carbon::class, $fresh->expires_at);
    }

    public function test_is_expired_reflects_the_expires_at_column(): void
    {
        $notExpired = IntegrationOAuthState::factory()->make(['expires_at' => now()->addMinutes(5)]);
        $expired = IntegrationOAuthState::factory()->make(['expires_at' => now()->subMinute()]);

        $this->assertFalse($notExpired->isExpired());
        $this->assertTrue($expired->isExpired());
    }

    public function test_is_consumed_reflects_the_consumed_at_column(): void
    {
        $unconsumed = IntegrationOAuthState::factory()->make(['consumed_at' => null]);
        $consumed = IntegrationOAuthState::factory()->make(['consumed_at' => now()]);

        $this->assertFalse($unconsumed->isConsumed());
        $this->assertTrue($consumed->isConsumed());
    }

    public function test_factory_produces_valid_non_colliding_rows(): void
    {
        $states = IntegrationOAuthState::factory()->count(3)->create();

        $this->assertSame(3, $states->pluck('id')->unique()->count());
        $this->assertSame(3, $states->pluck('opaque_token_hash')->unique()->count());

        foreach ($states as $state) {
            $this->assertNotNull($state->firm_id);
            $this->assertNotNull($state->firm_integration_id);
            $this->assertNotNull($state->initiating_user_id);
            $this->assertNotNull($state->initiating_firm_user_id);
        }
    }

    // ------------------------------------------------------------
    // 7. Compensating same-firm/same-user invariant (saving listener)
    // ------------------------------------------------------------

    public function test_saving_listener_rejects_an_initiating_firm_user_belonging_to_a_different_firm(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionA = $this->connectionForFirm($firmA);
        $firmUserB = $this->firmUserFor($firmB);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must reference a firm_users row belonging to the same firm_id/');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionA, $firmUserB) {
            IntegrationOAuthState::create([
                'firm_id' => $firmA->id,
                'firm_integration_id' => $connectionA->id,
                'initiating_user_id' => $firmUserB->user_id,
                'initiating_firm_user_id' => $firmUserB->id,
                'opaque_token_hash' => hash('sha256', Str::random(43)),
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'expires_at' => now()->addMinutes(10),
            ]);
        });
    }

    public function test_saving_listener_rejects_a_mismatched_initiating_user_id_and_firm_user_pair(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $firmUser = $this->firmUserFor($firm);
        $otherFirmUser = $this->firmUserFor($firm);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/user_id matches this row.s own/');

        $this->runWithFirmContext($firm, function () use ($firm, $connection, $firmUser, $otherFirmUser) {
            IntegrationOAuthState::create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                // Deliberately mismatched: initiating_user_id belongs to
                // $otherFirmUser, but initiating_firm_user_id points at
                // $firmUser — the two columns must always be set
                // together from the SAME FirmUser row.
                'initiating_user_id' => $otherFirmUser->user_id,
                'initiating_firm_user_id' => $firmUser->id,
                'opaque_token_hash' => hash('sha256', Str::random(43)),
                'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
                'expires_at' => now()->addMinutes(10),
            ]);
        });
    }

    // ------------------------------------------------------------
    // 8. Secret-shaped column secrecy
    // ------------------------------------------------------------

    public function test_only_a_hash_of_the_state_token_is_persisted_never_the_raw_token(): void
    {
        $rawToken = Str::random(43);
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $firmUser = $this->firmUserFor($firm);

        $state = $this->runWithFirmContext($firm, function () use ($firm, $connection, $firmUser, $rawToken) {
            return IntegrationOAuthState::create(array_merge(
                $this->rawRowAttributes($firm, $connection, $firmUser, $this->encryptionKeyIdFor($firm)),
                ['opaque_token_hash' => hash('sha256', $rawToken)],
            ));
        });

        $raw = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_oauth_states')->where('id', $state->id)->value('opaque_token_hash'),
        );

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($rawToken, $raw);
        $this->assertSame(hash('sha256', $rawToken), $raw);
    }

    public function test_to_array_excludes_opaque_token_hash_and_verifier_ciphertext(): void
    {
        $firm = $this->firmWithActiveKey();
        $state = $this->stateForFirm($firm);

        $array = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->findOrFail($state->id)->toArray());

        $this->assertArrayNotHasKey('opaque_token_hash', $array);
        $this->assertArrayNotHasKey('verifier_ciphertext', $array);
    }

    public function test_to_json_excludes_opaque_token_hash_and_verifier_ciphertext(): void
    {
        $firm = $this->firmWithActiveKey();
        $state = $this->stateForFirm($firm);

        $json = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->findOrFail($state->id)->toJson());
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('opaque_token_hash', $decoded);
        $this->assertArrayNotHasKey('verifier_ciphertext', $decoded);
    }

    public function test_model_hidden_attributes_contains_opaque_token_hash_and_verifier_ciphertext(): void
    {
        $model = new IntegrationOAuthState();

        $this->assertContains('opaque_token_hash', $model->getHidden());
        $this->assertContains('verifier_ciphertext', $model->getHidden());
    }

    public function test_expired_but_unconsumed_states_remain_isolated_by_rls_like_any_other_row(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $expiredStateB = $this->runWithFirmContext(
            $firmB,
            fn () => IntegrationOAuthState::factory()->forFirmIntegration($this->connectionForFirm($firmB))->expired()->create(),
        );

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('integration_oauth_states')->pluck('id')->all());

        $this->assertNotContains($expiredStateB->id, $visibleIds, 'Expiry is an application-layer concept — RLS isolation must still apply identically to expired rows.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $key = TenantEncryptionKey::factory()->forFirm($firm)->create();

        $this->encryptionKeyIds[$firm->id] = $key->id;

        return $firm;
    }

    private function encryptionKeyIdFor(Firm $firm): int
    {
        return $this->encryptionKeyIds[$firm->id]
            ?? throw new RuntimeException("No active encryption key provisioned for firm {$firm->id} in this test.");
    }

    private function connectionForFirm(Firm $firm): FirmIntegration
    {
        return FirmIntegration::factory()->forFirm($firm)->create();
    }

    private function firmUserFor(Firm $firm): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());
    }

    private function stateForFirm(Firm $firm, ?FirmUser $firmUser = null): IntegrationOAuthState
    {
        if (! isset($this->encryptionKeyIds[$firm->id])) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
        }

        $connection = $this->connectionForFirm($firm);

        $factory = IntegrationOAuthState::factory()->forFirmIntegration($connection);

        if ($firmUser !== null) {
            $factory = $factory->initiatedBy($firmUser);
        }

        return $factory->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection, FirmUser $firmUser, int $encryptionKeyId): array
    {
        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'initiating_user_id' => $firmUser->user_id,
            'initiating_firm_user_id' => $firmUser->id,
            'opaque_token_hash' => hash('sha256', Str::random(43)),
            'redirect_uri' => 'https://app.firmsbase.test/integrations/oauth/callback',
            'verifier_ciphertext' => null,
            'encryption_key_id' => $encryptionKeyId,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function singleColumnForeignKeyTarget(string $column): ?object
    {
        return DB::selectOne(
            "select confrelid::regclass::text as foreign_table,
                    (select attname from pg_attribute where attrelid = c.confrelid and attnum = c.confkey[1]) as foreign_column
             from pg_constraint c
             where c.conrelid = 'integration_oauth_states'::regclass
               and c.contype = 'f'
               and array_length(c.conkey, 1) = 1
               and c.conkey[1] = (
                   select attnum from pg_attribute
                   where attrelid = c.conrelid and attname = ?
               )",
            [$column]
        );
    }
}
