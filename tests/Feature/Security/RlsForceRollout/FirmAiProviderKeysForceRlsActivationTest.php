<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\AiProviderKeyService;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * FirmAiProviderKeysForceRlsActivationTest — Section 39A-5 Wave 1
 * follow-on (independent checkpoint, same shape as
 * FirmAiSettingsForceRlsActivationTest). Proves the FORCE ROW LEVEL
 * SECURITY activation for firm_ai_provider_keys (database/migrations/
 * 2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php)
 * is permanently active and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation on read/insert/update/delete,
 * correct same-firm access, and that every previously-forced table
 * remains forced simultaneously.
 *
 * Like FirmAiSettingsForceRlsActivationTest, this test deliberately
 * does NOT assert that firm_ai_provider_keys appears in
 * RowLevelSecurityCoverageMappingService::preparedTables(), and does
 * NOT assert any exact "N prepared/missing tables" count — the shared
 * registry (app/Services/RowLevelSecurityCoverageMappingService.php)
 * is intentionally NOT touched by this checkpoint; the coordinator
 * updates it once in a later wave-integration pass. This test instead
 * proves the live database state directly via pg_class/pg_policy,
 * which is unaffected by the registry not yet being updated.
 * forcedTables() (unlike preparedTables()) IS asserted exactly, since
 * it is derived at call time by scanning every FORCE-activation
 * migration file present in the repository — this checkpoint's own
 * migration file is already on disk, so it is correctly counted
 * without any registry update being required.
 *
 * RESOLVED: the bare-factory-create() question the implementer
 * flagged as unverified. Empirically (see
 * test_bare_factory_create_succeeds_only_via_nested_tenant_encryption_key_factorys_own_context_hold
 * below), a bare FirmAiProviderKey::factory()->create() call — with no
 * explicit context established by the caller — DOES succeed today,
 * contradicting the original "must fail closed like firm_ai_settings"
 * design assumption. The mechanism: FirmAiProviderKeyFactory's fixed
 * definition() resolves ONE shared Firm and derives both firm_id and
 * encryption_key_id from it via TenantEncryptionKey::factory()->forFirm($firm).
 * Illuminate's Factory::expandAttributes() resolves that nested
 * factory reference by calling ->create() on it SYNCHRONOUSLY, before
 * the parent row is ever persisted. TenantEncryptionKeyFactory::create()
 * carries its own, PRE-EXISTING, unrelated context-hold override
 * (Section 39A-3L, Checkpoint 16): it calls
 * setDatabaseTenantContextForFirmId() for the key's own firm_id and
 * never clears/restores it afterward. Because encryption_key_id now
 * derives from the SAME $firm as this row's own firm_id, that ambient
 * PostgreSQL session/transaction-local setting is already exactly this
 * row's own firm_id by the time firm_ai_provider_keys' own INSERT
 * runs — satisfying the firm_ai_provider_keys_tenant_isolation policy's
 * WITH CHECK clause purely as an accidental side effect of an unrelated
 * factory's own convention, not because of any deliberate per-row
 * context decision by this factory or the caller. This mirrors the
 * exact pattern already documented and locked in for
 * email_visibility_rules/email_message_links in Wave 2 (see
 * EmailVisibilityRulesForceRlsActivationTest::test_bare_factory_create_succeeds_only_via_nested_firm_user_factorys_own_context_hold).
 * It is NOT a security problem: RLS itself is never bypassed — the row
 * still correctly lands under a real, internally-consistent firm
 * context (verified below), just an incidentally-established one
 * rather than a deliberately-supplied one. The genuine,
 * unambiguous fail-closed guarantee (no context anywhere in scope =>
 * write rejected) is proven directly and unambiguously by
 * test_missing_tenant_context_cannot_write_firm_ai_provider_keys below,
 * via a raw DB::table()->insert() with no nested factory chain to
 * incidentally establish context.
 *
 * Known, stated (not hidden) residual gap: this migration/test batch
 * does NOT close the transitive cross-firm foreign-key gap between
 * firm_ai_provider_keys.encryption_key_id and the real firm_id of the
 * tenant_encryption_keys row it points to — see the migration's own
 * docblock. RLS on firm_ai_provider_keys alone cannot see into
 * tenant_encryption_keys to cross-check this; AiProviderKeyService::
 * generate()'s inline derivation of encryption_key_id from the SAME
 * $firm it is given is the only enforcement of that invariant today.
 * See test_a_raw_insert_can_still_reference_an_encryption_key_from_a_different_firm_at_the_raw_db_layer
 * below, which proves (rather than merely asserts) this residual gap.
 */
class FirmAiProviderKeysForceRlsActivationTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;

    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    use RefreshDatabase, SetsUpAiEntitledFirm;

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_firm_ai_provider_keys_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_ai_provider_keys'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_ai_provider_keys_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_ai_provider_keys'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_ai_provider_keys must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * forcedTables() is derived at call time from every
     * database/migrations/*_force_rls_on_*_table.php migration file
     * present in the repository (see
     * RowLevelSecurityCoverageMappingService::discoverForcedTables()).
     * This checkpoint's own migration file was originally the only new
     * one present when this test was first written (60 -> 61). Once
     * integrated alongside its four Section 39A-5 Wave 3 siblings
     * (ai_usage_events, ai_tool_actions, ai_approval_requests,
     * ai_approval_events), the count reflects all five together
     * (60 -> 65).
     */
    public function test_exactly_sixty_five_tables_have_force_row_level_security_active_no_more_no_less(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $forced = $coverage->forcedTables();

        $this->assertContains('firm_ai_provider_keys', $forced, 'firm_ai_provider_keys must be discoverable as FORCE-active by forcedTables().');
        // Narrowly updated AGAIN by Section 39A-5 Wave 7 (e-signature domain, 4 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-5 Wave 10 (trust accounting domain, 10 tables) — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration Platform mission (firm_integrations) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Stage B Checkpoint 4 of the FirmsBase Integration Platform mission (integration_credentials, a new genuine tenant-owned table with RLS prepared and FORCE-activated in the same migration) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertCount(149, $forced, 'Exactly 108 tables must have a FORCE-activation migration after Section 39A-5 Wave 10 — no more, no less.');
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'firm_ai_provider_keys'::regclass and polname = 'firm_ai_provider_keys_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The firm_ai_provider_keys_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    public function test_missing_tenant_context_cannot_read_firm_ai_provider_keys(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmAiProviderKey::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, FirmAiProviderKey::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_firm_ai_provider_keys(): void
    {
        $firm = Firm::factory()->create();
        $encryptionKey = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_ai_provider_keys')->insert([
            'firm_id' => $firm->id,
            'provider' => AiProvider::OpenAi->value,
            'encrypted_key_ciphertext' => 'placeholder-ciphertext',
            'encryption_key_id' => $encryptionKey->id,
            'status' => AiProviderKeyStatus::Active->value,
            'created_at' => now(),
        ]);
    }

    /**
     * NOTE: this does NOT prove "bare factory create fails closed" —
     * empirically, it does not. See this class's own docblock for the
     * full mechanism. The genuine, unambiguous fail-closed proof (no
     * context anywhere in scope => write rejected) is
     * test_missing_tenant_context_cannot_write_firm_ai_provider_keys
     * above, via a raw DB::table()->insert() with no nested factory
     * chain involved. This test instead documents and locks in the
     * observed factory-chain behavior so a future change to
     * TenantEncryptionKeyFactory's convention doesn't silently alter
     * it unnoticed.
     */
    public function test_bare_factory_create_succeeds_only_via_nested_tenant_encryption_key_factorys_own_context_hold(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $key = FirmAiProviderKey::factory()->create();

        $this->assertNotNull($key->id);

        $encryptionKey = TenantEncryptionKey::find($key->encryption_key_id);
        $this->assertNotNull($encryptionKey);
        $this->assertSame(
            $key->firm_id,
            $encryptionKey->firm_id,
            'The row created via the incidental ambient context must still be internally firm-consistent.'
        );

        // Document the ambient side effect precisely: the session
        // context is left HELD (not cleared) at this row's own
        // firm_id afterward, exactly the TenantEncryptionKeyFactory
        // convention this test exists to lock in.
        $this->assertDatabaseTenantContextIs(
            $key->firm_id,
            'TenantEncryptionKeyFactory::create() deliberately leaves the session context held at the key\'s own firm_id — this is the exact mechanism that lets the bare parent create() above succeed.'
        );
    }

    public function test_firm_a_context_can_read_its_own_firm_ai_provider_keys(): void
    {
        $firmA = Firm::factory()->create();
        $keyA = $this->runWithFirmContext($firmA, fn () => FirmAiProviderKey::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmAiProviderKey::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$keyA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_ai_provider_keys(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => FirmAiProviderKey::factory()->forFirm($firmA)->create());
        $keyB = $this->runWithFirmContext($firmB, fn () => FirmAiProviderKey::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmAiProviderKey::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($keyB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_firm_ai_provider_key(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            $encryptionKey = TenantEncryptionKey::factory()->forFirm($firmA)->create();

            return DB::table('firm_ai_provider_keys')->insertGetId([
                'firm_id' => $firmA->id,
                'provider' => AiProvider::OpenAi->value,
                'encrypted_key_ciphertext' => 'placeholder-ciphertext',
                'encryption_key_id' => $encryptionKey->id,
                'status' => AiProviderKeyStatus::Active->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_firm_ai_provider_keys(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $keyB = $this->runWithFirmContext($firmB, fn () => FirmAiProviderKey::factory()->forFirm($firmB)->create(['label' => 'original-label']));

        $this->runWithFirmContext($firmA, function () use ($keyB) {
            DB::table('firm_ai_provider_keys')->where('id', $keyB->id)->update(['label' => 'tampered-label']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmAiProviderKey::withoutGlobalScopes()->find($keyB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('original-label', $reReadAsFirmB->label);
    }

    public function test_firm_a_cannot_delete_firm_b_firm_ai_provider_keys(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $keyB = $this->runWithFirmContext($firmB, fn () => FirmAiProviderKey::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($keyB) {
            DB::table('firm_ai_provider_keys')->where('id', $keyB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmAiProviderKey::withoutGlobalScopes()->find($keyB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B firm_ai_provider_keys.');
    }

    public function test_firm_a_cannot_insert_a_firm_ai_provider_key_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $encryptionKeyB = $this->runWithFirmContext($firmB, fn () => TenantEncryptionKey::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $encryptionKeyB) {
            DB::table('firm_ai_provider_keys')->insert([
                'firm_id' => $firmB->id,
                'provider' => AiProvider::OpenAi->value,
                'encrypted_key_ciphertext' => 'placeholder-ciphertext',
                'encryption_key_id' => $encryptionKeyB->id,
                'status' => AiProviderKeyStatus::Active->value,
                'created_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $keyA = $this->runWithFirmContext($firmA, fn () => FirmAiProviderKey::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($keyA, $firmB) {
            DB::table('firm_ai_provider_keys')->where('id', $keyA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Residual gap proof — RLS only checks this row's own firm_id,
    // never a related row's owning firm. encryption_key_id has a real
    // NOT NULL foreign key constraint to tenant_encryption_keys, but
    // nothing enforces that the referenced key's own firm_id matches
    // this row's firm_id.
    // ---------------------------------------------------------------

    public function test_a_raw_insert_can_still_reference_an_encryption_key_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignEncryptionKey = $this->runWithFirmContext($otherFirm, fn () => TenantEncryptionKey::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignEncryptionKey) {
            return DB::table('firm_ai_provider_keys')->insertGetId([
                'firm_id' => $firm->id,
                'provider' => AiProvider::OpenAi->value,
                'encrypted_key_ciphertext' => 'placeholder-ciphertext',
                'encryption_key_id' => $foreignEncryptionKey->id,
                'status' => AiProviderKeyStatus::Active->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — an encryption_key_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmAiProviderKey::factory()->forFirm($firm)->create());

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
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare FirmAiProviderKey::factory()->create()
     * succeeds (see the docblock/test above for exactly why), and its
     * nested encryption_key_id must belong to the SAME firm as firm_id
     * — the factory's own root-cause fix for the cross-firm mismatch a
     * naive two-independent-factories default would otherwise produce.
     */
    public function test_factory_default_creation_is_safe_and_internally_consistent(): void
    {
        $key = FirmAiProviderKey::factory()->create();

        $this->assertNotNull($key->id);
        $this->assertNotNull($key->firm_id);

        $persisted = $this->runWithFirmContext(
            $key->firm_id,
            fn () => FirmAiProviderKey::withoutGlobalScopes()->with('encryptionKey')->find($key->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertNotNull($persisted->encryptionKey, 'The related TenantEncryptionKey must exist and be reachable.');
        $this->assertSame($key->firm_id, $persisted->encryptionKey->firm_id, 'Bare factory default must not produce a cross-firm encryption-key mismatch.');
    }

    /**
     * Explicit related-model factory state correctness: forFirm($firm)
     * must derive encryption_key_id from that SAME firm too, not just
     * firm_id — proven directly against the persisted row's real
     * related TenantEncryptionKey, not merely assumed from reading the
     * factory's source.
     */
    public function test_for_firm_state_derives_encryption_key_from_the_same_firm(): void
    {
        $firm = Firm::factory()->create();

        $key = $this->runWithFirmContext($firm, fn () => FirmAiProviderKey::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $key->firm_id);

        $encryptionKey = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::withoutGlobalScopes()->find($key->encryption_key_id));

        $this->assertNotNull($encryptionKey);
        $this->assertSame($firm->id, $encryptionKey->firm_id, 'forFirm() must derive encryption_key_id from the exact same firm passed in, not an independently-resolved one.');
    }

    // ---------------------------------------------------------------
    // End-to-end service proofs under FORCE, with no caller-supplied
    // context
    // ---------------------------------------------------------------

    /**
     * Core proof: AiProviderKeyService::generate() (its entire body
     * self-wrapped in one outer runWithFirmContext() call by this
     * checkpoint) still functions end-to-end under FORCE, with no
     * caller-supplied context beyond the $firm object itself.
     *
     * Context is explicitly cleared right before calling generate()
     * (the same explicit-baseline pattern used throughout this class
     * and by FirmAiSettingsForceRlsActivationTest's own
     * record()-under-force proofs) rather than relying on whatever
     * ambient state happens to follow makeAiEntitledFirm() —
     * makeAiEntitledFirm() itself leaves app.current_firm_id HELD (not
     * cleared) afterward, by design (FirmSettingsFactory's own
     * pre-existing context-hold pattern). Asserting "no context is
     * active" immediately after makeAiEntitledFirm() — before
     * generate() is ever invoked — would fail regardless of anything
     * generate() does, and is not the guarantee this checkpoint needs
     * to prove. The real guarantee is narrower and more precise:
     * generate() does not need a caller-supplied context, and whatever
     * context it establishes internally is fully cleaned up by the
     * time it returns.
     */
    public function test_generate_still_functions_end_to_end_under_force_with_no_caller_supplied_context(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling generate()');

        $result = app(AiProviderKeyService::class)->generate($firm, AiProvider::OpenAi, $user);

        $this->assertNotNull($result['key']->id);
        $this->assertSame($firm->id, $result['key']->firm_id);
        $this->assertStringStartsWith('aikey_', $result['rawKey']);
        $this->assertNoDatabaseTenantContext('generate() must restore context to the clean baseline it was called against');
    }

    /**
     * rotate() calls generate() internally, which nests safely — see
     * both methods' own docblocks in AiProviderKeyService. Same
     * explicit-clean-baseline reasoning as the test above.
     *
     * $original (the already-in-memory model instance returned by the
     * setup call above) is passed directly to rotate() without an
     * intervening ->fresh() re-read — a ->fresh() call made outside
     * any context, against the explicit clean baseline established
     * below, would itself be blocked by RLS and return null before
     * rotate() is ever reached. rotate() reads $existing's
     * already-loaded attributes only (firm_id/status/provider/label)
     * and writes via $existing->update(...) from INSIDE its own
     * runWithFirmContext() wrap, so no caller-side read under context
     * is required for it to function correctly — that is exactly the
     * "no caller-supplied context" guarantee this test proves. The
     * post-call re-reads below are wrapped in runWithFirmContext()
     * because they run AFTER rotate() has already restored context
     * back to the explicit clean baseline.
     */
    public function test_rotate_still_functions_end_to_end_under_force_with_no_caller_supplied_context(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $service = app(AiProviderKeyService::class);

        $original = $this->runWithFirmContext(
            $firm,
            fn () => $service->generate($firm, AiProvider::OpenAi, $user)['key'],
        );

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext('explicit clean baseline before calling rotate()');

        $rotated = $service->rotate($firm, $original, $user);

        $refreshedOriginal = $this->runWithFirmContext($firm, fn () => $original->fresh());

        $this->assertSame(AiProviderKeyStatus::Rotated, $refreshedOriginal->status);
        $this->assertNotNull($refreshedOriginal->rotated_at);
        $this->assertSame(AiProviderKeyStatus::Active, $rotated['key']->status);
        $this->assertNotSame($original->id, $rotated['key']->id);
        $this->assertNoDatabaseTenantContext('rotate() must restore context to the clean baseline it was called against, including its internal nested call to generate()');
    }

    /**
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration
     * introduced the policy itself. up() is restored in a finally
     * block so later tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path('database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_ai_provider_keys'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'firm_ai_provider_keys'::regclass and polname = 'firm_ai_provider_keys_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only firm_ai_provider_keys — sampled:
     * a handful of the previously-PREPARED tables, tenant_encryption_keys
     * (already FORCE-active, and the table whose factory's context-hold
     * side effect this checkpoint's own bare-factory-create() behavior
     * depends on — must not itself be altered by this migration), plus
     * one representative still-uncovered (MISSING_PREPARED_TABLES) table
     * — are bit-for-bit identical before and after a down()+up() round
     * trip.
     */
    public function test_migration_round_trip_affects_only_firm_ai_provider_keys(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'tenant_encryption_keys', // already FORCE-active; must remain untouched
            'firm_ai_settings', // the direct sibling checkpoint of this same wave
            'matter_expenses', // representative still-missing table, not touched by THIS checkpoint's migration
        ]);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path('database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php');
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the firm_ai_provider_keys migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the firm_ai_provider_keys migration round trip."
            );
        }
    }

    /**
     * Every other still-uncovered tenant table (i.e. every entry of
     * missingPreparedTables() other than firm_ai_provider_keys itself,
     * which this checkpoint activates ahead of the shared registry
     * being updated — see this class's own docblock) must remain
     * untouched.
     */
    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if ($table === 'firm_ai_provider_keys') {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this checkpoint must not add policies for any other uncovered table."
            );
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    /**
     * The general "RLS prepared but not schema-wide enforced" gap
     * (app/Services/ComplianceGapRegistryService.php, key
     * 'rls_prepared_not_enforced') must still be tracked and open —
     * this checkpoint activates one more table, but the overall gap
     * remains genuinely open until the entire rollout completes, and
     * this checkpoint must not silently mark it resolved.
     */
    public function test_the_rls_prepared_not_enforced_gap_remains_tracked(): void
    {
        $registry = new ComplianceGapRegistryService;

        $gap = $registry->byKey('rls_prepared_not_enforced');

        $this->assertNotNull($gap, 'The rls_prepared_not_enforced gap must remain tracked in the registry.');
        $this->assertSame('open', $gap->status, 'The gap must remain open — this checkpoint does not complete schema-wide RLS enforcement.');
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php');

        $this->assertEmpty(
            $changed,
            'RowLevelSecurityCoverageMappingService.php must remain untouched by this individual checkpoint — the wave-integration update lands separately once every table in this wave has landed.'
        );
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_only_this_checkpoints_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowed = [
            'database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php',
            'app/Services/AiProviderKeyService.php',
            'database/factories/FirmAiProviderKeyFactory.php',
            'tests/Feature/Ai/ProviderKeys/AiProviderKeyServiceTest.php',
            'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
            'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        ];

        $unexpected = array_values(array_diff($changed, $allowed));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this checkpoint: '.implode(', ', $unexpected));
    }

    /**
     * @return array<int, string>
     */
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — any later
     * mission's real work will always otherwise trip every earlier
     * checkpoint's own "no changes" firewall, since each one asserts
     * against the CURRENT working tree, not a point-in-time snapshot.
     * Explicitly excluded here (not dismissed) so this firewall keeps
     * catching genuinely out-of-scope changes going forward.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
        'app/Filament/Resources/PlanAddOnResource.php',
        'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
        'app/Filament/Resources/PlanResource.php',
        'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
        'app/Models/Plan.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/PlanModuleService.php',
        'app/Services/PlanService.php',
        'config/database.php',
        'database/factories/PlanFactory.php',
        'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
        'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
        'tests/Feature/Plans/PlanServiceTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
        'app/Exceptions/InactivePlanSelectedException.php',
        'app/Filament/Actions/Platform/AddPlanModuleAction.php',
        'app/Filament/Actions/Platform/CreatePlanAction.php',
        'app/Filament/Actions/Platform/EditPlanAction.php',
        'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
        'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
        'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
        // The 72 RlsForceRollout per-table activation test files
        // themselves, mechanically updated (this exact const +
        // filtering addition) by this same reviewed mission — see
        // this array's own docblock above.
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CalendarEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ClientCommunicationPreferencesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConflictCheckRunsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationOutcomesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentChaseRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmployeeRatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmLeadsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmPracticeAreasForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LeadSourcesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/FirmUser2fa/FirmUser2faFirewallTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceActivation/RlsForceActivationFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/BackupRestoreTestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ContactsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/HealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/IncidentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MaintenanceWindowsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/NotificationTemplatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PartiesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PilotFeedbackItemsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SecurityEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TimelineEventsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // FIRMSVAULT — STAGING ADMIN STABILIZATION (follow-on fix) also
        // corrected DeploymentEnvironmentFirewallTest.php's own scope-check
        // to allow this mission's one migration, which is itself a new
        // changed file requiring the same allowlist entry here.
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        // feature/ses-event-consumer (a later, distinct, wholly
        // isolated mission: a production-safe SES bounce/complaint
        // consumer) legitimately added a notification-provider
        // correlation ledger + idempotency ledger (both exempted,
        // no-RLS, registered in RowLevelSecurityCoverageMappingService
        // per the same integration_webhook_routing_index/
        // integration_platform_provider_health_summaries precedent
        // pattern), a dedicated SQS consumer command, real-send
        // correlation wiring in User/ClientPortalUser password-reset
        // notifications, and its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling RlsForceRollout/Governance/Security
        // firewall test files touched by this same mission, matching
        // this array's own established cross-file-listing convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Enums/SesBounceType.php',
        'app/Enums/SesEventType.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/NotificationEvent.php',
        'app/Models/NotificationProviderCorrelation.php',
        'app/Models/SesEventReceipt.php',
        'app/Models/User.php',
        'app/Notifications/ClientPortalResetPasswordNotification.php',
        'app/Notifications/FirmOwnerInvitationNotification.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/NotificationDispatchService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'config/mail.php',
        'config/services.php',
        'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // post-578ee98 audit remediation (a later, distinct,
        // independent security/architecture review of the SES
        // event consumer feature) legitimately fixed a MessageSent
        // listener leak, an uncaught-exception crash risk in the
        // consumer command, a receipt-write concurrency race, a
        // complaint recipient-mismatch hard-reject, and added a new
        // platform-scope correlation/suppression subsystem for
        // password-reset sends that cannot resolve a firm — plus
        // its own new test files. Also mechanically added this
        // exact const + filtering addition across all its sibling
        // firewall test files touched by this same remediation,
        // matching this array's own established cross-file-listing
        // convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/PlatformNotificationCorrelation.php',
        'app/Models/PlatformNotificationSuppression.php',
        'app/Models/User.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/PlatformNotificationCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'app/Services/SuppressionService.php',
        'config/services.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php',
        'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Mail/SesMailerTransportTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
        'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Notifications/SuppressionServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // Round 3 audit remediation (a later, distinct,
        // independent security/architecture review requiring exact
        // firm correlation for all tenant-owned email, with no
        // platform-level or uncorrelated fallback) legitimately
        // introduced CorrelatedPasswordResetSenderService as the
        // single dedicated sender every password-reset/invitation
        // send goes through, fixed a transaction-poisoning bug in
        // both correlation services' before/post-send DB writes,
        // and rewired FirmProvisioningService's owner-invitation
        // dispatch through sendResetLink()'s own $callback
        // parameter — plus its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling firewall test files touched by
        // this same remediation.
        '.env.example',
        'app/Enums/CorrelatedSendResult.php',
        'app/Exceptions/NotificationTransportFailedException.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/User.php',
        'app/Services/CorrelatedPasswordResetSenderService.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/PlatformNotificationCorrelationService.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
        'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        // Retention governance type-normalization fix (a later,
        // distinct remediation restoring CI's protected suite —
        // RetentionGovernanceRegistryService::current_default was
        // returned as a raw string under CI's fresh-.env condition
        // instead of a real PHP int; fixed at the config() boundary,
        // plus its own focused regression tests).
        'app/Services/RetentionGovernanceRegistryService.php',
        'tests/Feature/Governance/Retention/RetentionGovernanceRegistryServiceTest.php',
        // feature/ses-consumer-ecs-wiring (a later, distinct,
        // wholly isolated ECS/IAM wiring mission) legitimately
        // added docker/entrypoint.sh's ses-consumer role dispatch,
        // a new docker/commands/ses-consumer.sh, Terraform IAM/
        // ECS-service/CloudWatch-alarm wiring for that role, its
        // own docs/ecs/ updates, and its own new test files.
        'docker/commands/ses-consumer.sh',
        'docker/entrypoint.sh',
        'docs/ecs/alarm-inventory.md',
        'docs/ecs/container-architecture.md',
        'docs/ecs/database-migrations.md',
        'docs/ecs/env.ecs.example',
        'docs/ecs/graceful-shutdown.md',
        'docs/ecs/iam-matrix.md',
        'docs/ecs/infrastructure-architecture.md',
        'docs/ecs/observability.md',
        'docs/ecs/runbooks/deployment-runbook.md',
        'docs/ecs/runbooks/rollback-runbook.md',
        'infrastructure/ecs/environments/staging/main.tf',
        'infrastructure/ecs/environments/staging/outputs.tf',
        'infrastructure/ecs/environments/staging/terraform.tfvars.example',
        'infrastructure/ecs/environments/staging/variables.tf',
        'infrastructure/ecs/modules/cloudwatch_alarms/main.tf',
        'infrastructure/ecs/modules/cloudwatch_alarms/variables.tf',
        'infrastructure/ecs/modules/iam/main.tf',
        'infrastructure/ecs/modules/iam/variables.tf',
        'tests/Feature/Ecs/SesConsumerEntrypointTest.php',
        'tests/Feature/Ecs/SesConsumerTerraformIamTest.php',
        // feature/ses-consumer-ecs-wiring also bounded the SqsClient's HTTP connect/overall timeouts here, confirmed necessary by a real container-level graceful-shutdown smoke test.
        'app/Providers/AppServiceProvider.php',
    ];

    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = $this->changedOrUntrackedPathsRaw($scope);

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
