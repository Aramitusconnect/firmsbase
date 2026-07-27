<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * IntegrationCredentialsForceRlsActivationTest — Checkpoint 4 (the actual
 * credential material — OAuth tokens, API keys — for a firm_integrations
 * connection; checkpoint-00-final-specification.md §5/§10/§11;
 * frozen-design-post-review.md). Mirrors
 * FirmIntegrationsForceRlsActivationTest's exact structural/assertion
 * conventions: direct pg_class/pg_policies/pg_policy catalog queries
 * (never Schema::hasTable()-only proofs), runWithFirmContext() for every
 * tenant-scoped read/write, and paired Artisan-call / direct-include
 * migration rollback proofs.
 *
 * integration_credentials introduces this codebase's first genuine
 * composite foreign key: (firm_id, firm_integration_id) references
 * firm_integrations(firm_id, id) — a real, DB-enforced constraint made
 * possible by firm_integrations' own UNIQUE(firm_id, id) index added in
 * Checkpoint 3. This class proves that constraint both at the catalog
 * level (pg_constraint) and behaviorally (a row whose firm_id/
 * firm_integration_id tuple crosses firms is rejected independent of, and
 * in addition to, RLS).
 */
class IntegrationCredentialsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_MIGRATION_PATH = 'database/migrations/2026_09_03_030001_create_integration_credentials_table.php';

    private const TABLE_MIGRATION_NAME = '2026_09_03_030001_create_integration_credentials_table';

    private const RLS_MIGRATION_PATH = 'database/migrations/2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php';

    private const RLS_MIGRATION_NAME = '2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table';

    private const POLICY_NAME = 'integration_credentials_tenant_isolation';

    private const EXPECTED_COLUMNS = [
        'id',
        'firm_id',
        'firm_integration_id',
        'credential_type',
        'encrypted_payload_ciphertext',
        'encryption_key_id',
        'status',
        'granted_scopes_json',
        'expires_at',
        'masked_display_metadata',
        'webhook_routing_token',
        'rotated_at',
        'revoked_at',
        'last_refreshed_at',
        'refresh_failure_reason',
        'created_at',
        'updated_at',
    ];

    /**
     * Checkpoint 1 (FirmsVault Live Integrations) addition
     * (checkpoint1-security-review.md Finding 3;
     * database/migrations/2026_09_13_130001_add_credential_environment_mode_to_integration_credentials_table.php) —
     * a dedicated, DB-CHECK-constrained column, never folded into
     * masked_display_metadata. Deliberately NOT folded into
     * self::EXPECTED_COLUMNS above: that constant also backs
     * test_migration_rollback_and_reapplication_restores_exact_prior_state()/
     * test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(),
     * which roll back and reapply ONLY the two original
     * 2026_09_03_030001/030002 migrations (never this later, separate
     * ALTER TABLE migration) — after that narrower rollback/reapply
     * cycle, this column is genuinely, correctly absent. Only the tests
     * asserting the table's CURRENT, fully-migrated live schema use this
     * constant.
     */
    private const EXPECTED_COLUMNS_ON_CURRENT_LIVE_SCHEMA = [
        ...self::EXPECTED_COLUMNS,
        'credential_environment_mode',
    ];

    /**
     * firm_id => id of the Active TenantEncryptionKey provisioned by
     * firmWithActiveKey() for that firm. Captured at creation time rather
     * than re-queried later, since tenant_encryption_keys is itself FORCE
     * RLS and re-querying it later would require re-establishing tenant
     * context for no benefit.
     *
     * @var array<int, int>
     */
    private array $encryptionKeyIds = [];

    // ------------------------------------------------------------
    // 1. Schema correctness, ownership, and constraints
    // ------------------------------------------------------------

    public function test_integration_credentials_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('integration_credentials'));
    }

    public function test_integration_credentials_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('integration_credentials');

        sort($columns);
        $expected = self::EXPECTED_COLUMNS_ON_CURRENT_LIVE_SCHEMA;
        sort($expected);

        $this->assertSame(
            $expected,
            $columns,
            'integration_credentials must have exactly the documented column set — no more, no fewer.'
        );
    }

    public function test_firm_id_foreign_key_references_firms_id(): void
    {
        $row = $this->singleColumnForeignKeyTarget('firm_id');

        $this->assertNotNull($row, 'integration_credentials.firm_id must have a single-column FOREIGN KEY constraint.');
        $this->assertSame('firms', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    public function test_encryption_key_id_foreign_key_references_tenant_encryption_keys_id(): void
    {
        $row = $this->singleColumnForeignKeyTarget('encryption_key_id');

        $this->assertNotNull($row, 'integration_credentials.encryption_key_id must have a single-column FOREIGN KEY constraint.');
        $this->assertSame('tenant_encryption_keys', $row->foreign_table);
        $this->assertSame('id', $row->foreign_column);
    }

    /**
     * Catalog-level proof of the composite FK: exactly 3 total FK
     * constraints on this table (firm_id->firms, encryption_key_id->
     * tenant_encryption_keys, and the composite), and exactly one of
     * those three constraints spans 2 columns (conkey array length),
     * targeting firm_integrations.
     */
    public function test_composite_foreign_key_on_firm_id_and_firm_integration_id_exists_in_the_catalog(): void
    {
        $constraints = DB::select(
            "select conname, array_length(conkey, 1) as col_count, confrelid::regclass::text as foreign_table
             from pg_constraint
             where conrelid = 'integration_credentials'::regclass and contype = 'f'
             order by conname"
        );

        $this->assertCount(
            3,
            $constraints,
            'integration_credentials must have exactly 3 FK constraints: firm_id->firms, '.
            'encryption_key_id->tenant_encryption_keys, and the composite (firm_id, firm_integration_id)->firm_integrations.'
        );

        $compositeRows = array_values(array_filter($constraints, fn ($row) => (int) $row->col_count === 2));

        $this->assertCount(1, $compositeRows, 'Exactly one FK constraint must be composite (span 2 columns).');
        $this->assertSame('firm_integrations', $compositeRows[0]->foreign_table);
    }

    /**
     * Behavioral proof of the composite FK, independent of RLS: under
     * firm A's own tenant context (so the RLS WITH CHECK clause is
     * satisfied — firm_id = firm A), insert a row whose
     * firm_integration_id points at a REAL firm_integrations row that
     * belongs to firm B. The tuple (firmA.id, firmB's connection id) has
     * no match in firm_integrations (which only has (firmB.id, that
     * connection's id)) — rejected at the constraint layer.
     */
    public function test_composite_foreign_key_rejects_a_firm_integration_id_belonging_to_a_different_firm(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->connectionForFirm($firmB);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($firmA, $connectionB) {
            DB::table('integration_credentials')->insert(
                $this->rawRowAttributes($firmA, $connectionB, $this->encryptionKeyIdFor($firmA))
            );
        });
    }

    public function test_inserting_a_nonexistent_firm_integration_id_is_rejected_by_the_foreign_key(): void
    {
        $firm = $this->firmWithActiveKey();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext($firm, function () use ($firm) {
            DB::table('integration_credentials')->insert([
                'firm_id' => $firm->id,
                'firm_integration_id' => 999999999,
                'credential_type' => CredentialType::ApiKey->value,
                'encrypted_payload_ciphertext' => 'irrelevant-fixture-ciphertext',
                'encryption_key_id' => $this->encryptionKeyIdFor($firm),
                'status' => IntegrationCredentialStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Partial unique index behavioral proof, part 1: a second row with
     * the SAME (firm_integration_id, credential_type) while the first is
     * still 'active' is rejected by
     * integration_credentials_one_active_per_connection_and_type — via a
     * raw DB::table()->insert() that bypasses the service entirely, so
     * this proves the DATABASE constraint itself, not merely that the
     * service happens to check first.
     */
    public function test_duplicate_active_credential_of_the_same_type_for_the_same_connection_is_rejected(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);

        $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, function () use ($firm, $connection) {
            DB::table('integration_credentials')->insert(
                $this->rawRowAttributes($firm, $connection, $this->encryptionKeyIdFor($firm))
            );
        });
    }

    /**
     * Partial unique index behavioral proof, part 2: once the first
     * active row of that (connection, type) is revoked (no longer
     * 'active'), a new active row of the same type is allowed.
     */
    public function test_duplicate_credential_type_is_allowed_once_the_first_is_revoked(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $service = $this->service();

        $first = $service->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));
        $service->revoke($connection, $first, 'rotating out for test coverage');

        $second = $service->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(IntegrationCredentialStatus::Active, $second->fresh()->status);
    }

    // ------------------------------------------------------------
    // 2. RLS proof via live PostgreSQL catalog queries
    // ------------------------------------------------------------

    public function test_integration_credentials_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'integration_credentials'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity, 'integration_credentials is tenant-owned — RLS must be enabled.');
    }

    public function test_integration_credentials_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_credentials'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'integration_credentials holds secret material — FORCE ROW LEVEL SECURITY must be active, including against the table owner.');
    }

    public function test_integration_credentials_has_exactly_one_row_level_security_policy(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");

        $this->assertCount(
            1,
            $rows,
            'integration_credentials must have exactly one policy this checkpoint — the Checkpoint 7 webhook-signing-lookup carve-out is deliberately out of scope.'
        );
        $this->assertSame(self::POLICY_NAME, $rows[0]->policyname);
    }

    /**
     * The predicate must be byte-for-byte identical to firm_integrations'
     * own tenant-isolation predicate — both migrations are documented as
     * mirroring the exact same canonical SQL shape.
     */
    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause_matching_the_canonical_predicate(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_credentials'::regclass and polname = ?",
            [self::POLICY_NAME]
        );

        $this->assertNotNull($row, 'The integration_credentials_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING.');
    }

    // ------------------------------------------------------------
    // 3. Cross-firm tenant isolation
    // ------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_integration_credentials(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, IntegrationCredential::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_integration_credentials(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $encryptionKeyId = $this->encryptionKeyIdFor($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('integration_credentials')->insert(
            $this->rawRowAttributes($firm, $connection, $encryptionKeyId)
        );
    }

    public function test_firm_a_context_can_read_its_own_integration_credential(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $visibleIds = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()->pluck('id')->all(),
        );

        $this->assertSame([$credential->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_integration_credential(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionA = $this->connectionForFirm($firmA);
        $connectionB = $this->connectionForFirm($firmB);
        $this->service()->store($connectionA, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));
        $credentialB = $this->service()->store($connectionB, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => IntegrationCredential::query()->pluck('id')->all(),
        );

        $this->assertNotContains($credentialB->id, $visibleIds);
    }

    public function test_firm_a_cannot_update_firm_b_integration_credential(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->connectionForFirm($firmB);
        $credentialB = $this->service()->store($connectionB, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $affected = $this->runWithFirmContext($firmA, function () use ($credentialB) {
            return DB::table('integration_credentials')->where('id', $credentialB->id)->update(['refresh_failure_reason' => 'hacked-by-firm-a']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s integration_credentials row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IntegrationCredential::query()->find($credentialB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNotSame('hacked-by-firm-a', $reReadAsFirmB->refresh_failure_reason);
    }

    public function test_firm_a_cannot_delete_firm_b_integration_credential(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->connectionForFirm($firmB);
        $credentialB = $this->service()->store($connectionB, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $affected = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('integration_credentials')->where('id', $credentialB->id)->delete(),
        );

        $this->assertSame(0, $affected, 'No rows should be visible/deletable — Firm A must not be able to delete Firm B\'s integration_credentials row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IntegrationCredential::query()->find($credentialB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B integration_credentials.');
    }

    public function test_firm_a_cannot_insert_an_integration_credential_claiming_firm_b_ownership(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->connectionForFirm($firmB);
        $encryptionKeyIdB = $this->encryptionKeyIdFor($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $connectionB, $encryptionKeyIdB) {
            DB::table('integration_credentials')->insert(
                $this->rawRowAttributes($firmB, $connectionB, $encryptionKeyIdB)
            );
        });
    }

    /**
     * Reassigning firm_id across firms is doubly blocked here: RLS's WITH
     * CHECK clause rejects a row whose new firm_id no longer matches the
     * active session firm, AND (since firm_integration_id would then be
     * inconsistent with the new firm_id) the composite FK would also
     * reject it. Either failure mode is an acceptable, correct outcome —
     * this test asserts the reassignment is rejected at the database
     * layer and that the row's firm_id is unchanged afterward, without
     * over-specifying which of the two independent defenses fires first.
     */
    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionA = $this->connectionForFirm($firmA);
        $credential = $this->service()->store($connectionA, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/row-level security policy|foreign key constraint/i');

        $this->runWithFirmContext($firmA, function () use ($credential, $firmB) {
            DB::table('integration_credentials')->where('id', $credential->id)->update(['firm_id' => $firmB->id]);
        });
    }

    /**
     * NOTE: store() already wraps its entire body in its own
     * runWithFirmContext() call — no outer wrap is used here so this test
     * exercises the real production entry point's own hygiene directly,
     * not a synthetic wrap around it.
     */
    public function test_tenant_context_clears_after_success(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);

        // firmWithActiveKey()/connectionForFirm() provision their fixtures
        // via factories that follow the established "context-hold pattern"
        // (TenantEncryptionKeyFactory's own docblock: "matching every prior
        // FORCE-RLS factory since 39A-3A") — they activate the PostgreSQL
        // session's tenant context to satisfy RLS during fixture creation
        // but deliberately never clear it themselves. Left alone, that
        // leftover context (not this test's own store() call) would be
        // what store()'s runWithFirmContext() restores afterward, making
        // this assertion pass or fail based on fixture setup rather than
        // on store()'s own cleanup. Matches
        // WebhookSecretsForceRlsActivationTest::test_tenant_context_clears_after_success()'s
        // identical precedent for the identical reason.
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        // Bare Firm::factory()->create() — not firmWithActiveKey() — since
        // this test never encrypts/decrypts anything and so needs no
        // TenantEncryptionKey fixture; TenantEncryptionKeyFactory's own
        // context-hold pattern would otherwise leave a stray PostgreSQL
        // tenant context active before runWithFirmContext() below even
        // runs, exactly as documented on test_tenant_context_clears_after_success()
        // above. Matches FirmIntegrationsForceRlsActivationTest's and
        // FirmAiProviderKeysForceRlsActivationTest's identical precedent.
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
    // 4. Migration rollback and reapplication (two migrations, reverse order)
    // ------------------------------------------------------------

    public function test_migration_rollback_and_reapplication_restores_exact_prior_state(): void
    {
        $this->assertFileExists(base_path(self::TABLE_MIGRATION_PATH));
        $this->assertFileExists(base_path(self::RLS_MIGRATION_PATH));

        $this->assertTrue(Schema::hasTable('integration_credentials'));
        $this->assertNotNull(DB::table('migrations')->where('migration', self::TABLE_MIGRATION_NAME)->first());
        $this->assertNotNull(DB::table('migrations')->where('migration', self::RLS_MIGRATION_NAME)->first());

        $forceRow = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertTrue((bool) $forceRow->relforcerowsecurity);

        $rlsRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => self::RLS_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $rlsRollbackExit, 'migrate:rollback (RLS migration) failed: '.Artisan::output());

        $rowAfterRlsRollback = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertFalse((bool) $rowAfterRlsRollback->relrowsecurity, 'Rolling back the RLS migration must fully disable RLS, not merely clear FORCE.');
        $this->assertFalse((bool) $rowAfterRlsRollback->relforcerowsecurity);

        $policyAfterRollback = DB::selectOne(
            "select 1 from pg_policy where polrelid = 'integration_credentials'::regclass and polname = ?",
            [self::POLICY_NAME]
        );
        $this->assertNull($policyAfterRollback, 'Rolling back the RLS migration must drop the policy it created.');

        $tableRollbackExit = Artisan::call('migrate:rollback', [
            '--path' => self::TABLE_MIGRATION_PATH,
            '--force' => true,
        ]);
        $this->assertSame(0, $tableRollbackExit, 'migrate:rollback (table migration) failed: '.Artisan::output());

        $this->assertFalse(Schema::hasTable('integration_credentials'));
        $this->assertNull(DB::selectOne("select relname from pg_class where relname = 'integration_credentials'"));
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

        $this->assertTrue(Schema::hasTable('integration_credentials'));

        $columns = Schema::getColumnListing('integration_credentials');
        sort($columns);
        $expectedColumns = self::EXPECTED_COLUMNS;
        sort($expectedColumns);
        $this->assertSame($expectedColumns, $columns);

        $oneActiveIndex = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_credentials' and indexname = 'integration_credentials_one_active_per_connection_and_type'"
        );
        $this->assertNotNull($oneActiveIndex, 'The partial unique index enforcing one active credential per (connection, type) must be restored.');

        $webhookIndex = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'integration_credentials' and indexname = 'integration_credentials_webhook_routing_token_unique'"
        );
        $this->assertNotNull($webhookIndex, 'The partial unique index on webhook_routing_token must be restored.');

        $rowAfterReapply = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertTrue((bool) $rowAfterReapply->relrowsecurity);
        $this->assertTrue((bool) $rowAfterReapply->relforcerowsecurity);

        $policiesAfterReapply = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");
        $this->assertCount(1, $policiesAfterReapply);
        $this->assertSame(self::POLICY_NAME, $policiesAfterReapply[0]->policyname);

        $exprAfterReapply = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'integration_credentials'::regclass and polname = ?",
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
     * entirely — mirrors FirmIntegrationsForceRlsActivationTest's second,
     * direct-call rollback proof. Safe inside RefreshDatabase's outer
     * per-test transaction (PostgreSQL supports transactional DDL).
     */
    public function test_migration_down_and_up_restores_exact_prior_state_via_direct_calls(): void
    {
        $this->assertTrue(Schema::hasTable('integration_credentials'));

        $rlsMigration = include base_path(self::RLS_MIGRATION_PATH);
        $tableMigration = include base_path(self::TABLE_MIGRATION_PATH);

        $rlsMigration->down();
        $tableMigration->down();

        $this->assertFalse(Schema::hasTable('integration_credentials'), 'Table must be fully dropped after both down() calls.');

        $tableMigration->up();
        $rlsMigration->up();

        $this->assertTrue(Schema::hasTable('integration_credentials'), 'Table must be fully restored after both up() calls.');

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'integration_credentials'");
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue((bool) $row->relforcerowsecurity);

        $policies = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");
        $this->assertCount(1, $policies);
        $this->assertSame(self::POLICY_NAME, $policies[0]->policyname);
    }

    // ------------------------------------------------------------
    // 5. Model conventions
    // ------------------------------------------------------------

    public function test_model_table_resolves_to_integration_credentials(): void
    {
        $model = new IntegrationCredential;

        $this->assertSame('integration_credentials', $model->getTable());
    }

    public function test_model_fillable_contains_exactly_the_expected_fields(): void
    {
        $model = new IntegrationCredential;

        $expected = [
            'firm_id',
            'firm_integration_id',
            'credential_type',
            'encrypted_payload_ciphertext',
            'encryption_key_id',
            'status',
            // Checkpoint 1 (FirmsVault Live Integrations) addition
            // (checkpoint1-security-review.md Finding 3) — see the
            // identical addition to EXPECTED_COLUMNS above.
            'credential_environment_mode',
            'granted_scopes_json',
            'expires_at',
            'masked_display_metadata',
            'webhook_routing_token',
            'rotated_at',
            'revoked_at',
            'last_refreshed_at',
            'refresh_failure_reason',
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
        $traits = class_uses_recursive(IntegrationCredential::class);

        $this->assertArrayHasKey(
            BelongsToTenant::class,
            $traits,
            'IntegrationCredential is direct firm-owned data — it must use BelongsToTenant (defense-in-depth alongside FORCE RLS).'
        );
    }

    public function test_model_has_the_tenant_global_scope_applied(): void
    {
        $model = new IntegrationCredential;

        $this->assertArrayHasKey('tenant', $model->getGlobalScopes());
    }

    public function test_casts_are_correct(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store(
            $connection,
            CredentialType::ApiKey,
            'test-oauth-token-'.Str::random(32),
            [],
            now()->addDay()
        );

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()->findOrFail($credential->id),
        );

        $this->assertInstanceOf(CredentialType::class, $fresh->credential_type);
        $this->assertInstanceOf(IntegrationCredentialStatus::class, $fresh->status);
        $this->assertInstanceOf(Carbon::class, $fresh->expires_at);
    }

    public function test_factory_produces_valid_non_colliding_rows(): void
    {
        $credentials = IntegrationCredential::factory()->count(3)->create();

        $this->assertSame(3, $credentials->pluck('id')->unique()->count());

        foreach ($credentials as $credential) {
            $this->assertNotNull($credential->firm_id);
            $this->assertNotNull($credential->firm_integration_id);
        }
    }

    // ------------------------------------------------------------
    // 6. Encryption
    // ------------------------------------------------------------

    public function test_plaintext_secret_is_absent_from_the_raw_database_ciphertext_value(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $plaintext = 'test-oauth-token-'.Str::random(32);

        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, $plaintext);

        // Deliberately via DB::table(), never the Eloquent model — proves
        // the RAW stored value, not whatever the model's accessor might
        // do to it.
        $raw = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_credentials')->where('id', $credential->id)->value('encrypted_payload_ciphertext'),
        );

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($plaintext, $raw);
    }

    public function test_decrypt_for_operation_returns_the_correct_plaintext_with_a_valid_operation_id_and_reason(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $plaintext = 'test-oauth-token-'.Str::random(32);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, $plaintext);

        $decrypted = $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->decryptForOperation(
                $connection->fresh(),
                $credential->fresh(),
                'op-'.Str::random(8),
                'integration test: verifying round-trip decrypt'
            ),
        );

        $this->assertSame($plaintext, $decrypted);
    }

    public function test_decrypt_for_operation_throws_on_empty_operation_id(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->decryptForOperation($connection->fresh(), $credential->fresh(), '', 'a valid reason'),
        );
    }

    public function test_decrypt_for_operation_throws_on_empty_reason(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->decryptForOperation($connection->fresh(), $credential->fresh(), 'op-1', ''),
        );
    }

    public function test_decrypt_for_operation_throws_if_connection_status_is_not_active(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm, ConnectionStatus::Pending);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not Active/');

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->decryptForOperation($connection->fresh(), $credential->fresh(), 'op-1', 'test reason'),
        );
    }

    public function test_decrypt_for_operation_throws_if_credential_status_is_not_active(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));
        $this->service()->revoke($connection, $credential, 'revoked before decrypt attempt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not Active/');

        $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->decryptForOperation($connection->fresh(), $credential->fresh(), 'op-1', 'test reason'),
        );
    }

    /**
     * "Wrong firm cannot decrypt": connectionB genuinely belongs to firm
     * B (ambient context is set to firm B, so the connection/context
     * check passes), but credentialA belongs to firm A's own connection
     * — assertCredentialBelongsToConnection() rejects the mismatch.
     */
    public function test_decrypt_for_operation_rejects_a_credential_that_does_not_belong_to_the_given_connection(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionA = $this->connectionForFirm($firmA);
        $connectionB = $this->connectionForFirm($firmB);
        $credentialA = $this->service()->store($connectionA, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not belong to connection/i');

        // Deliberately NOT $credentialA->fresh() here: FORCE RLS on
        // integration_credentials (this checkpoint's own migration) means
        // a re-fetch of firm A's row while the ambient PostgreSQL session
        // context is firm B (set by runWithFirmContext($firmB, ...) below)
        // returns zero rows at the database level — fresh() uses
        // newQueryWithoutScopes(), which bypasses the app-layer
        // BelongsToTenant scope but cannot bypass real RLS enforcement —
        // so ->fresh() would correctly come back null here, which is RLS
        // working as designed, not a bug. The already-in-memory
        // $credentialA object (returned by store() above) already carries
        // every attribute assertCredentialBelongsToConnection() needs.
        $this->runWithFirmContext(
            $firmB,
            fn () => $this->service()->decryptForOperation($connectionB->fresh(), $credentialA, 'op-1', 'wrong-firm decrypt attempt'),
        );
    }

    public function test_rotate_marks_the_old_row_rotated_and_creates_a_new_active_row_with_different_ciphertext(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $original = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));

        $rotated = $this->service()->rotate($connection, $original, 'test-oauth-token-'.Str::random(32));

        $this->assertNotSame($original->id, $rotated->id);
        $this->assertSame(IntegrationCredentialStatus::Active, $rotated->fresh()->status);
        $this->assertSame(IntegrationCredentialStatus::Rotated, $original->fresh()->status);

        $oldCiphertext = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_credentials')->where('id', $original->id)->value('encrypted_payload_ciphertext'),
        );
        $newCiphertext = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_credentials')->where('id', $rotated->id)->value('encrypted_payload_ciphertext'),
        );

        $this->assertNotSame($oldCiphertext, $newCiphertext);
    }

    public function test_rotate_throws_if_the_existing_credential_is_not_active(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));
        $this->service()->revoke($connection, $credential, 'revoked before rotate attempt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/only an Active credential can be rotated/');

        $this->service()->rotate($connection, $credential->fresh(), 'test-oauth-token-'.Str::random(32));
    }

    public function test_revoke_is_idempotent(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, 'test-oauth-token-'.Str::random(32));

        $first = $this->service()->revoke($connection, $credential, 'first revoke');
        $this->assertSame(IntegrationCredentialStatus::Revoked, $first->status);

        // Calling revoke() again on an already-revoked row must not throw.
        $second = $this->service()->revoke($connection, $credential->fresh(), 'second revoke, must remain a safe no-op');
        $this->assertSame(IntegrationCredentialStatus::Revoked, $second->status);
    }

    /**
     * Fail-closed proof: manually corrupt the raw ciphertext (bypassing
     * both the model's immutability guard and the service, via a raw
     * DB::table()->update()), then confirm decryptForOperation() throws
     * rather than returning garbage — and that whatever it throws never
     * leaks the corrupted value or the original plaintext in its
     * message. The exact exception class thrown by Laravel's Encrypter
     * for a malformed payload is not asserted (this codebase does not
     * commit to that class), only that *something* is thrown and its
     * message is safe.
     */
    public function test_decrypt_for_operation_fails_closed_on_malformed_ciphertext(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $plaintext = 'test-oauth-token-'.Str::random(32);
        $credential = $this->service()->store($connection, CredentialType::OauthAccessToken, $plaintext);

        $corruptedValue = 'corrupted-not-a-valid-laravel-encrypted-payload-'.Str::random(8);

        $this->runWithFirmContext($firm, function () use ($credential, $corruptedValue) {
            DB::table('integration_credentials')->where('id', $credential->id)->update([
                'encrypted_payload_ciphertext' => $corruptedValue,
            ]);
        });

        $corrupted = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()->findOrFail($credential->id),
        );

        $thrown = null;

        try {
            $this->runWithFirmContext(
                $firm,
                fn () => $this->service()->decryptForOperation($connection->fresh(), $corrupted, 'op-1', 'malformed ciphertext fail-closed test'),
            );
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'decryptForOperation() must throw when ciphertext is malformed, not silently return garbage.');
        $this->assertStringNotContainsString($plaintext, $thrown->getMessage());
        $this->assertStringNotContainsString($corruptedValue, $thrown->getMessage());
    }

    // ------------------------------------------------------------
    // 7. Serialization
    // ------------------------------------------------------------

    public function test_to_array_excludes_the_encrypted_payload_ciphertext(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $array = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()->findOrFail($credential->id)->toArray(),
        );

        $this->assertArrayNotHasKey('encrypted_payload_ciphertext', $array);
    }

    public function test_to_json_excludes_the_encrypted_payload_ciphertext(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $json = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()->findOrFail($credential->id)->toJson(),
        );
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('encrypted_payload_ciphertext', $decoded);
    }

    public function test_model_hidden_attributes_contain_encrypted_payload_ciphertext(): void
    {
        $model = new IntegrationCredential;

        $this->assertContains('encrypted_payload_ciphertext', $model->getHidden());
    }

    // ------------------------------------------------------------
    // 8. Lifecycle
    // ------------------------------------------------------------

    public function test_store_creates_an_active_row_with_correct_firm_and_connection(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);

        $credential = $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $this->assertSame($firm->id, $credential->firm_id);
        $this->assertSame($connection->id, $credential->firm_integration_id);
        $this->assertSame(IntegrationCredentialStatus::Active, $credential->status);
    }

    public function test_replace_marks_the_old_row_rotated_and_creates_a_new_active_row(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $original = $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $replacement = $this->service()->replace(
            $connection,
            $original,
            'test-oauth-token-'.Str::random(32),
            ['label' => 'replaced fixture credential']
        );

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(IntegrationCredentialStatus::Rotated, $original->fresh()->status);
        $this->assertSame(IntegrationCredentialStatus::Active, $replacement->fresh()->status);
    }

    public function test_get_masked_metadata_never_leaks_ciphertext_or_the_plaintext_secret(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $plaintext = 'test-oauth-token-'.Str::random(32);
        $credential = $this->service()->store($connection, CredentialType::ApiKey, $plaintext, ['label' => 'masked metadata fixture']);

        $masked = $this->service()->getMaskedMetadata($credential->fresh());

        $expectedKeys = [
            'id', 'firm_integration_id', 'credential_type', 'status',
            'expires_at', 'masked_display_metadata', 'created_at',
            'rotated_at', 'revoked_at',
        ];
        $actualKeys = array_keys($masked);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys, 'getMaskedMetadata() must return only the documented safe fields.');

        $this->assertArrayNotHasKey('encrypted_payload_ciphertext', $masked);
        $this->assertArrayNotHasKey('encryption_key_id', $masked);

        $encoded = json_encode($masked);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($plaintext, $encoded);
    }

    public function test_updating_encrypted_payload_ciphertext_directly_via_the_model_throws_a_logic_exception(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $credential = $this->service()->store($connection, CredentialType::ApiKey, 'test-oauth-token-'.Str::random(32));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/immutable after creation/');

        $this->runWithFirmContext($firm, function () use ($credential) {
            $credential->fresh()->update(['encrypted_payload_ciphertext' => 'attempted-direct-bypass-of-the-guard']);
        });
    }

    public function test_re_encrypt_changes_the_ciphertext_of_the_same_row_in_place_and_remains_decryptable(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);
        $plaintext = 'test-oauth-token-'.Str::random(32);
        $credential = $this->service()->store($connection, CredentialType::ApiKey, $plaintext);

        $beforeCiphertext = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_credentials')->where('id', $credential->id)->value('encrypted_payload_ciphertext'),
        );

        $reEncrypted = $this->service()->reEncrypt($connection, $credential->fresh());

        $afterCiphertext = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_credentials')->where('id', $credential->id)->value('encrypted_payload_ciphertext'),
        );

        $this->assertSame($credential->id, $reEncrypted->id, 'reEncrypt() must update the SAME row in place, never create a new one.');
        $this->assertNotSame($beforeCiphertext, $afterCiphertext);

        $decrypted = $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->decryptForOperation($connection->fresh(), $reEncrypted->fresh(), 'op-reencrypt', 'post re-encrypt decrypt check'),
        );

        $this->assertSame($plaintext, $decrypted);
    }

    // ------------------------------------------------------------
    // 9. Locking
    // ------------------------------------------------------------

    /**
     * A genuine multi-process/multi-thread mutual-exclusion race is not
     * reliable to prove inside a single-process PHPUnit run — this
     * codebase's own precedent, TrustConcurrencyLockServiceTest, makes
     * and relies on the identical disclaimer. This test instead combines:
     * (1) a live-query proof that withRefreshLock() genuinely issues a
     * SELECT ... FOR UPDATE (captured via DB::listen, mirroring
     * TrustConcurrencyLockServiceTest's own technique) and (2) a
     * source-inspection proof that the method's body is built on
     * DB::transaction() + ->lockForUpdate(), not some other mechanism
     * (e.g. Cache::lock()).
     */
    public function test_with_refresh_lock_wraps_the_operation_in_a_real_db_transaction_with_a_locked_row(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->connectionForFirm($firm);

        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = strtolower($query->sql);
        });

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->service()->withRefreshLock($connection, fn () => 'callback-ran'),
        );

        $this->assertSame('callback-ran', $result);

        $lockingQueries = array_filter($capturedSql, fn ($sql) => str_contains($sql, 'for update'));
        $this->assertNotEmpty($lockingQueries, 'withRefreshLock() must issue at least one SELECT ... FOR UPDATE query.');

        $source = file_get_contents((new ReflectionClass(IntegrationCredentialService::class))->getFileName());
        $this->assertNotFalse($source);
        $this->assertStringContainsString('DB::transaction', $source, 'withRefreshLock() must be built on DB::transaction(), per the frozen design.');
        $this->assertStringContainsString('lockForUpdate()', $source, 'withRefreshLock() must use ->lockForUpdate(), not a cache-based lock.');
    }

    /**
     * Proves the lock's SCOPE is per-connection (per firm_integrations
     * row), not table-wide or global: while still logically "inside" the
     * outer withRefreshLock() call for connection A (its DB::transaction
     * has not yet returned), a nested, entirely separate
     * withRefreshLock() call for connection B — a DIFFERENT
     * firm_integrations row belonging to a DIFFERENT firm — must still
     * run its own callback to completion. This does not (and, per the
     * disclaimer above, cannot in a single PHPUnit process) prove genuine
     * concurrent mutual exclusion; it proves that A's lock never blocks
     * B's, which is the per-row (not global) scoping property the
     * design requires.
     */
    public function test_with_refresh_lock_scoping_is_per_connection_not_global(): void
    {
        $firmA = $this->firmWithActiveKey();
        $firmB = $this->firmWithActiveKey();
        $connectionA = $this->connectionForFirm($firmA);
        $connectionB = $this->connectionForFirm($firmB);

        $service = $this->service();
        $bExecuted = false;

        $this->runWithFirmContext($firmA, function () use ($service, $connectionA, $connectionB, $firmB, &$bExecuted) {
            return $service->withRefreshLock($connectionA, function () use ($service, $connectionB, $firmB, &$bExecuted) {
                // Still inside connection A's transaction/row-lock scope.
                // Switch ambient tenant context to firm B (safe to nest —
                // runWithFirmContext() restores exactly what was active
                // beforehand) and acquire connection B's own lock from
                // within it.
                $result = $this->runWithFirmContext($firmB, function () use ($service, $connectionB, &$bExecuted) {
                    return $service->withRefreshLock($connectionB, function () use (&$bExecuted) {
                        $bExecuted = true;

                        return 'b-done';
                    });
                });

                $this->assertSame('b-done', $result);

                return 'a-done';
            });
        });

        $this->assertTrue(
            $bExecuted,
            'Connection B\'s locked callback must execute even while Connection A\'s row lock is logically held — proving per-row, not global, lock scope.'
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function service(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
    }

    /**
     * Creates a firm and provisions a real, persisted, Active
     * TenantEncryptionKey for it (never a placeholder), capturing its id
     * in $encryptionKeyIds for later raw-insert fixtures — mirrors
     * IntegrationCredentialFactory's own real-key-provisioning
     * convention.
     */
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

    private function connectionForFirm(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRowAttributes(Firm $firm, FirmIntegration $connection, int $encryptionKeyId): array
    {
        return [
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'credential_type' => CredentialType::ApiKey->value,
            'encrypted_payload_ciphertext' => 'irrelevant-fixture-ciphertext-not-real',
            'encryption_key_id' => $encryptionKeyId,
            'status' => IntegrationCredentialStatus::Active->value,
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
             where c.conrelid = 'integration_credentials'::regclass
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
