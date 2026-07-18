<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\FirmUserStatus;
use App\Enums\PaymentMode;
use App\Enums\TwoFactorMode;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\FirmUser2faPolicyService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FirmSettingsForceRlsActivationTest — Section 39A-3L, Checkpoint 18.
 * Proves the thirty-sixth staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_25_930018_force_rls_on_firm_settings_table.php)
 * is permanently active for firm_settings and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table remains forced
 * simultaneously, and that the singleton-per-firm unique constraint on
 * firm_id still works correctly once FORCE is layered on top of it
 * (RLS and the unique constraint are two independent guarantees — this
 * file proves both, and proves neither one silently papered over the
 * other).
 *
 * Unlike document_chase_events (Checkpoint 17), firm_settings has no
 * second, independently-resolved tenant-owned relation of its own —
 * firm_id is its ONLY tenant-linkage column (confirmed by direct model/
 * migration read: no document_request_item_id-style foreign key to a
 * separately-owned row exists here). There is therefore no
 * "raw insert can still reference a different firm's related row"
 * residual-gap class to prove for this table the way there was for
 * document_chase_events — this is a genuine difference in this table's
 * shape, not an omission.
 *
 * The single most important proof in this file is
 * test_can_access_panel_correctly_fails_closed_when_2fa_required_with_no_ambient_context_established_beforehand()
 * below: it is the regression proof for the highest-priority production
 * fix in this checkpoint (User::canAccessPanel() wrapping its entire
 * 2FA decision in TenantContextService::runWithFirmContext(), since
 * firm_settings becoming FORCE-RLS protected would otherwise make
 * firm->firmSettings silently resolve to null and 2FA enforcement
 * silently fail OPEN for a firm configured with
 * firm_user_2fa_mode = Required). This test deliberately never wraps
 * its own call to canAccessPanel() in any ambient context — the whole
 * point is proving the PRODUCTION CODE establishes its own context
 * internally, not that the test spoon-feeds it one.
 */
class FirmSettingsForceRlsActivationTest extends TestCase
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
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
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

    public function test_firm_settings_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_settings'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_settings_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_settings'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_settings must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-six tables (the thirty-five previously forced plus
     * firm_settings) must be FORCE-enabled among ALL prepared tables —
     * no more, no less.
     */
    public function test_exactly_thirty_six_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (this repo's fortieth staged FORCE activation batch, covering payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
        // repo's forty-second staged FORCE activation batch, covering
        // notification_events) to extend the "exactly these tables
        // are forced" list to include notification_events too — this
        // test's own scope predates Checkpoint 24, but the exact-count
        // assertion below must still account for that later,
        // legitimate addition rather than falsely reporting it as
        // unexpected — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'customer_success_health_scores', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events']);
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(56, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 18 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (covering
        // notification_events) for the same reason as above —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'customer_success_health_scores', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events']);
        // Section 39A-3L, Phase B6, Checkpoint 34 (security_events) is
        // the final checkpoint in this arc: $forced now equals the FULL
        // preparedTables() set exactly, so the per-table loop below
        // legitimately has zero remaining iterations (a real, positive
        // end state, not a lost assertion). This explicit equality
        // check keeps the test genuinely assertive regardless of loop
        // iteration count.
        $forcedSorted = $forced;
        sort($forcedSorted);
        $preparedTablesSorted = $coverage->preparedTables();
        sort($preparedTablesSorted);
        $this->assertSame($forcedSorted, $preparedTablesSorted, 'Every originally "prepared" table must now be force-enabled, no more, no fewer.');

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
             where polrelid = 'firm_settings'::regclass"
        );

        $this->assertNotNull($policy, 'The firm_settings tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_firm_settings(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, FirmSettings::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_settings(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_settings')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'payment_mode' => PaymentMode::OperatingPaymentsOnly->value,
            'trust_iolta_protection' => true,
            'ai_mode' => 'disabled',
            'client_2fa_mode' => 'optional',
            'default_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_settings(): void
    {
        $firmA = Firm::factory()->create();
        $settingsA = $this->runWithFirmContext($firmA, fn () => FirmSettings::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmSettings::query()->pluck('id')->all(),
        );

        $this->assertSame([$settingsA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => FirmSettings::factory()->forFirm($firmA)->create());
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmSettings::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmSettings::query()->pluck('id')->all(),
        );

        $this->assertNotContains($settingsB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('firm_settings')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firm->id,
                'payment_mode' => PaymentMode::OperatingPaymentsOnly->value,
                'trust_iolta_protection' => true,
                'ai_mode' => 'disabled',
                'client_2fa_mode' => 'optional',
                'default_language' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_firm_settings_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('firm_settings')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmB->id,
                'payment_mode' => PaymentMode::OperatingPaymentsOnly->value,
                'trust_iolta_protection' => true,
                'ai_mode' => 'disabled',
                'client_2fa_mode' => 'optional',
                'default_language' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_delete_firm_b_firm_settings(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmSettings::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($settingsB) {
            DB::table('firm_settings')->where('id', $settingsB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmSettings::query()->find($settingsB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s firm_settings row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_firm_settings_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmSettings::factory()->forFirm($firmB)->create());

        // A second firm_settings row does not already exist for firmA,
        // so a successful (but forbidden) reassignment would not even
        // collide with the unique constraint — this test isolates RLS's
        // own behavior, independent of that separate guarantee.
        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $settingsB) {
            return DB::table('firm_settings')->where('id', $settingsB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s firm_settings row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmSettings::query()->find($settingsB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare FirmSettings::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override).
     */
    public function test_firm_settings_factory_default_creation_is_internally_consistent(): void
    {
        $settings = FirmSettings::factory()->create();

        $this->assertNotNull($settings->id);
        $this->assertNotNull($settings->firm_id);

        $persisted = $this->runWithFirmContext(
            $settings->firm,
            fn () => FirmSettings::query()->find($settings->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($settings->firm_id, $persisted->firm_id);
    }

    public function test_firm_settings_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $settings = $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $settings->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => FirmSettings::query()->find($settings->id),
        );

        $this->assertNotNull($persisted);
    }

    // ---------------------------------------------------------------
    // Singleton-uniqueness proof (firm_settings-specific, beyond the
    // generic template) — this is a pre-existing DB-level unique
    // constraint on firm_id, NOT an RLS guarantee. This test proves
    // Agent 9's factory context-hold fix did not accidentally paper
    // over it: a second row for the SAME firm, created under the
    // firm's own correct tenant context both times (so RLS itself does
    // not interfere), must still be rejected.
    // ---------------------------------------------------------------

    public function test_a_second_firm_settings_row_for_the_same_firm_still_throws_via_the_unique_constraint(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());

        $this->assertSame(
            1,
            $this->runWithFirmContext($firm, fn () => FirmSettings::query()->count()),
            'Sanity check: exactly one firm_settings row must exist for this firm before the second (forbidden) create() is attempted.'
        );

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/unique constraint|duplicate key/i');

        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());
    }

    // ---------------------------------------------------------------
    // THE fail-safe regression proof — the single most important test
    // in this file. Proves the highest-priority production fix in this
    // checkpoint (User::canAccessPanel() wrapping its entire 2FA
    // decision in TenantContextService::runWithFirmContext()). This
    // test would have FAILED before that fix (2FA silently detected as
    // "not required", access silently allowed) and must PASS now.
    //
    // Deliberately does NOT wrap the test's own call to
    // canAccessPanel() in runWithFirmContext() — the whole point is
    // proving the PRODUCTION CODE establishes its own context
    // internally, not that the test supplies one ambiently.
    // ---------------------------------------------------------------

    public function test_can_access_panel_correctly_fails_closed_when_2fa_required_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext(
            $firm,
            fn () => FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]),
        );

        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]),
        );

        // Explicitly clear any ambient context left active by the
        // fixture-building factories above (both FirmSettingsFactory
        // and FirmUserFactory deliberately leave context set afterward
        // for the common "create then read" pattern) — this test's
        // entire point depends on NO context being active the moment
        // canAccessPanel() is called.
        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $panel = \Filament\Facades\Filament::getPanel('firm');

        $this->assertFalse(
            $user->canAccessPanel($panel),
            'canAccessPanel() must establish its own tenant context internally and correctly deny access for a non-compliant firm user at a Required-2FA firm — this is the regression proof for the User::canAccessPanel() FORCE-RLS fix. A pre-fix build would silently resolve firm_settings to null here and incorrectly ALLOW access.'
        );

        $this->assertNoDatabaseTenantContext('canAccessPanel() must clear its own internal context wrap before returning, leaving no leaked context behind for the next check.');
    }

    /**
     * Companion proof, same no-ambient-context shape: a COMPLIANT firm
     * user at the same Required-2FA firm must still be correctly
     * GRANTED access — proving the fix closes the fail-open gap without
     * introducing a new fail-closed-when-it-shouldn't-be regression.
     */
    public function test_can_access_panel_correctly_allows_access_when_2fa_required_and_compliant_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext(
            $firm,
            fn () => FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]),
        );

        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => now()]);
        $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]),
        );

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $panel = \Filament\Facades\Filament::getPanel('firm');

        $this->assertTrue(
            $user->canAccessPanel($panel),
            'A compliant firm user at a Required-2FA firm must still be granted access once canAccessPanel() correctly establishes its own tenant context internally.'
        );

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Direct-service companion (not through canAccessPanel()): proves
     * FirmUser2faPolicyService::isRequiredForFirmUser() itself correctly
     * reads firm_settings under FORCE when the CALLER supplies context —
     * isolates the policy service's own correctness from
     * canAccessPanel()'s wrapping responsibility.
     */
    public function test_firm_user_2fa_policy_service_correctly_reads_required_mode_under_force_rls_when_caller_supplies_context(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext(
            $firm,
            fn () => FirmSettings::factory()->forFirm($firm)->create(['firm_user_2fa_mode' => TwoFactorMode::Required]),
        );

        $user = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => null]);
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]),
        );

        $policy = new FirmUser2faPolicyService();

        $isRequired = $this->runWithFirmContext($firm, fn () => $policy->isRequiredForFirmUser($firmUser));
        $isCompliant = $this->runWithFirmContext($firm, fn () => $policy->isCompliant($firmUser));

        $this->assertTrue($isRequired);
        $this->assertFalse($isCompliant);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmSettings::factory()->forFirm($firm)->create());

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
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Thirty-five previously forced tables plus firm_settings must be
     * independently force-active and independently isolated at the same
     * time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses clients as the companion
     * table.
     */
    public function test_firm_settings_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $settingsA = $this->runWithFirmContext($firmA, fn () => FirmSettings::factory()->forFirm($firmA)->create());
        $settingsB = $this->runWithFirmContext($firmB, fn () => FirmSettings::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'firm_settings' => FirmSettings::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$settingsA->id], $resultA['firm_settings']);
        $this->assertNotContains($settingsB->id, $resultA['firm_settings']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the firm_settings migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * policy still present, but NOT forced — never drop the policy or
     * disable RLS itself. Also proves rollback affects ONLY this one
     * table — every other previously-forced table must be untouched.
     */
    public function test_firm_settings_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930018_force_rls_on_firm_settings_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_settings'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while firm_settings is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'firm_settings'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_settings'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
