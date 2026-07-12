<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\ComplianceGapRegistryService;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantEncryptionKeysForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 16. Proves the thirty-fourth staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php)
 * is permanently active for tenant_encryption_keys and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, that EncryptionKeyService's four methods
 * (provision, rotate, decryptActiveKey, destroy — each replacing a
 * plain DB::transaction() atomicity-only boundary with
 * runWithFirmContext()) function correctly under FORCE, and that
 * EmailBodyEncryptionService's own two direct TenantEncryptionKey
 * queries (in encrypt()/decrypt(), each wrapped in its own standalone,
 * non-nested runWithFirmContext() call, sequential with the
 * decryptActiveKey() call that follows) do too.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * this checkpoint's migration docblock): KeyDestructionExecutionService
 * (the sole caller of destroy()) establishes no tenant context of its
 * own around its key_destruction_requests reads/writes — out of this
 * checkpoint's scope, since that table is not yet FORCE RLS and is not
 * this checkpoint's own table. provision()/rotate() currently have no
 * live production caller (confirmed by direct repository search) —
 * real but currently-dormant service API, wrapped anyway since the fix
 * is narrow.
 */
class TenantEncryptionKeysForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events', 'intake_submissions',
        'matter_readiness_scores', 'readiness_score_events',
    ];

    // ---------------------------------------------------------------
    // FORCE state / policy / cumulative-coverage proofs
    // ---------------------------------------------------------------

    public function test_every_previously_forced_table_remains_force_row_level_security_enabled(): void
    {
        foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must remain FORCE RLS enabled after this batch."
            );
        }
    }

    public function test_tenant_encryption_keys_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'tenant_encryption_keys'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_tenant_encryption_keys_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'tenant_encryption_keys'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'tenant_encryption_keys must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-four tables (the thirty-three previously forced
     * plus tenant_encryption_keys) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 17 (this
     * repo's thirty-fifth staged FORCE activation batch, covering
     * document_chase_events) to extend the "exactly these tables are
     * forced" firewall list from thirty-four to thirty-five tables —
     * same additive-only pattern, no existing assertion removed or
     * weakened.
     */
    public function test_exactly_thirty_four_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (this repo's fortieth staged FORCE activation batch, covering payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans']);

        $actuallyForced = [];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ((bool) $row->relforcerowsecurity) {
                $actuallyForced[] = $table;
            }
        }

        sort($expectedForced);
        sort($actuallyForced);

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21, Table
        // Phase C (this repo's thirty-ninth staged FORCE activation batch,
        // covering time_entries) for the same reason — additive only, no
        // existing assertion removed or weakened.
        $this->assertSame(40, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 16 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans']);

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'tenant_encryption_keys'::regclass"
        );

        $this->assertNotNull($policy, 'The tenant_encryption_keys tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_tenant_encryption_keys(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, TenantEncryptionKey::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_tenant_encryption_keys(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('tenant_encryption_keys')->insert([
            'firm_id' => $firm->id,
            'key_version' => 1,
            'status' => 'active',
            'encrypted_key' => Crypt::encryptString('x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_tenant_encryption_key(): void
    {
        $firmA = Firm::factory()->create();
        $keyA = $this->runWithFirmContext($firmA, fn () => TenantEncryptionKey::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TenantEncryptionKey::query()->pluck('id')->all(),
        );

        $this->assertSame([$keyA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_tenant_encryption_key(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => TenantEncryptionKey::factory()->forFirm($firmA)->create());
        $keyB = $this->runWithFirmContext($firmB, fn () => TenantEncryptionKey::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TenantEncryptionKey::query()->pluck('id')->all(),
        );

        $this->assertNotContains($keyB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('tenant_encryption_keys')->insertGetId([
                'firm_id' => $firm->id,
                'key_version' => 1,
                'status' => 'active',
                'encrypted_key' => Crypt::encryptString('x'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_tenant_encryption_key_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('tenant_encryption_keys')->insert([
                'firm_id' => $firmB->id,
                'key_version' => 1,
                'status' => 'active',
                'encrypted_key' => Crypt::encryptString('x'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_tenant_encryption_key(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $keyB = $this->runWithFirmContext($firmB, fn () => TenantEncryptionKey::factory()->forFirm($firmB)->create(['status' => TenantEncryptionKeyStatus::Active]));

        $this->runWithFirmContext($firmA, function () use ($keyB) {
            DB::table('tenant_encryption_keys')->where('id', $keyB->id)->update(['status' => 'rotated']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TenantEncryptionKey::query()->find($keyB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            TenantEncryptionKeyStatus::Active,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s tenant_encryption_keys row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_tenant_encryption_key(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $keyB = $this->runWithFirmContext($firmB, fn () => TenantEncryptionKey::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($keyB) {
            DB::table('tenant_encryption_keys')->where('id', $keyB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TenantEncryptionKey::query()->find($keyB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s tenant_encryption_keys row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_tenant_encryption_key_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $keyB = $this->runWithFirmContext($firmB, fn () => TenantEncryptionKey::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $keyB) {
            return DB::table('tenant_encryption_keys')->where('id', $keyB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s encryption key to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TenantEncryptionKey::query()->find($keyB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare TenantEncryptionKey::factory()->
     * create() must succeed even from outside any already-active
     * tenant context (the factory's context-hold create() override),
     * and the row must actually be visible/readable under its own
     * firm's context afterward. This table has only ONE tenant-scoped
     * foreign key (firm_id), so there is no cross-firm-mismatch bug
     * class to prove here — unlike several prior checkpoints'
     * factories, definition() needed no change, only this override.
     */
    public function test_tenant_encryption_key_factory_default_creation_is_internally_consistent(): void
    {
        $key = TenantEncryptionKey::factory()->create();

        $this->assertNotNull($key->id);
        $this->assertNotNull($key->firm_id);

        $persisted = $this->runWithFirmContext(
            $key->firm,
            fn () => TenantEncryptionKey::query()->find($key->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($key->firm_id, $persisted->firm_id);
    }

    public function test_tenant_encryption_key_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $key->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TenantEncryptionKey::query()->find($key->id),
        );

        $this->assertNotNull($persisted);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

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
    // End-to-end EncryptionKeyService proofs under FORCE
    // ---------------------------------------------------------------

    public function test_provision_creates_an_active_key_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $service = new EncryptionKeyService();

        $key = $service->provision($firm);

        $this->assertNoDatabaseTenantContext('provision() must clear its own context wrap before returning.');
        $this->assertSame(1, $key->key_version);
        $this->assertSame(TenantEncryptionKeyStatus::Active, $key->status);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TenantEncryptionKey::query()->where('firm_id', $firm->id)->first(),
        );
        $this->assertNotNull($persisted, 'provision() must persist exactly one tenant_encryption_keys row under FORCE, readable under its own firm context.');
    }

    public function test_rotate_demotes_and_creates_new_active_key_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $service = new EncryptionKeyService();

        $original = $service->provision($firm);
        $rotated = $service->rotate($firm);

        $this->assertNoDatabaseTenantContext('rotate() must clear its own context wrap before returning.');
        $this->assertSame(2, $rotated->key_version);
        $this->assertSame(TenantEncryptionKeyStatus::Active, $rotated->status);

        $originalFresh = $this->runWithFirmContext($firm, fn () => $original->fresh());
        $this->assertSame(TenantEncryptionKeyStatus::Rotated, $originalFresh->status);
    }

    public function test_decrypt_active_key_returns_original_plaintext_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $service = new EncryptionKeyService();
        $service->provision($firm);

        $plaintext = $service->decryptActiveKey($firm);

        $this->assertNoDatabaseTenantContext('decryptActiveKey() must clear its own context wrap before returning.');
        $this->assertNotEmpty($plaintext);
    }

    public function test_decrypt_active_key_throws_when_no_active_key_exists_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $service = new EncryptionKeyService();

        $this->expectException(\RuntimeException::class);

        $service->decryptActiveKey($firm);
    }

    /**
     * The governed crypto-shredding step: proves destroy() correctly
     * finds and tombstones every non-destroyed key version for the
     * firm under FORCE, and never touches another firm's keys.
     */
    public function test_destroy_tombstones_every_non_destroyed_key_for_the_firm_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $service = new EncryptionKeyService();

        $service->provision($firm);
        $service->rotate($firm);
        $service->provision($otherFirm);

        $destroyedCount = $service->destroy($firm);

        $this->assertNoDatabaseTenantContext('destroy() must clear its own context wrap before returning.');
        $this->assertSame(2, $destroyedCount, 'destroy() must tombstone BOTH the rotated and active key versions for the firm.');

        $firmKeys = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::query()->where('firm_id', $firm->id)->get());
        foreach ($firmKeys as $key) {
            $this->assertTrue($key->isDestroyed());
            $this->assertNotNull($key->destroyed_at);
        }

        $otherFirmKey = $this->runWithFirmContext($otherFirm, fn () => TenantEncryptionKey::query()->where('firm_id', $otherFirm->id)->first());
        $this->assertTrue($otherFirmKey->isActive(), 'destroy() must never touch another firm\'s keys.');
    }

    // ---------------------------------------------------------------
    // EmailBodyEncryptionService proofs under FORCE (the adjacent gap
    // found and fixed in the same pass — see the migration's docblock)
    // ---------------------------------------------------------------

    public function test_email_body_encryption_service_encrypt_and_decrypt_round_trip_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        app(EncryptionKeyService::class)->provision($firm);

        $service = app(EmailBodyEncryptionService::class);
        $result = $service->encrypt($firm, 'sensitive plaintext');

        $this->assertNoDatabaseTenantContext('encrypt() must not leave a context wrap active after returning.');
        $this->assertTrue($result->succeeded);

        $decrypted = $service->decrypt($firm, $result->ciphertext, $result->encryptionKeyId);

        $this->assertNoDatabaseTenantContext('decrypt() must not leave a context wrap active after returning.');
        $this->assertSame('sensitive plaintext', $decrypted);
    }

    public function test_email_body_encryption_service_encrypt_fails_closed_when_firm_has_no_active_key(): void
    {
        $firm = Firm::factory()->create();

        $result = app(EmailBodyEncryptionService::class)->encrypt($firm, 'sensitive plaintext');

        $this->assertNoDatabaseTenantContext();
        $this->assertFalse($result->succeeded);
    }

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Thirty-three previously forced tables plus tenant_encryption_keys
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses clients as the
     * companion table.
     */
    public function test_tenant_encryption_keys_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => \App\Models\Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => \App\Models\Client::factory()->forFirm($firmB)->create());

        $keyA = $this->runWithFirmContext($firmA, fn () => TenantEncryptionKey::factory()->forFirm($firmA)->create());
        $keyB = $this->runWithFirmContext($firmB, fn () => TenantEncryptionKey::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'tenant_encryption_keys' => TenantEncryptionKey::query()->pluck('id')->all(),
            'clients' => \App\Models\Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$keyA->id], $resultA['tenant_encryption_keys']);
        $this->assertNotContains($keyB->id, $resultA['tenant_encryption_keys']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the tenant_encryption_keys migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, policy still present, but NOT forced — never drop the
     * policy or disable RLS itself. Also proves rollback affects ONLY
     * this one table — every other previously-forced table must be
     * untouched.
     */
    public function test_tenant_encryption_keys_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930016_force_rls_on_tenant_encryption_keys_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'tenant_encryption_keys'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while tenant_encryption_keys is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'tenant_encryption_keys'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'tenant_encryption_keys'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
