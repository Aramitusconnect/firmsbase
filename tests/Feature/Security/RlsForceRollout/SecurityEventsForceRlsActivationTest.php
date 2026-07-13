<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\FirmUserStatus;
use App\Enums\SupportAccessType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\ComplianceGapRegistryService;
use App\Services\DedicatedCustomerTypeApprovalService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\SupportAccessPolicyService;
use App\Services\SupportAccessRequestService;
use App\Services\TenantContextService;
use App\Services\WebhookReplayService;
use App\Services\WebhookSubscriptionService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SecurityEventsForceRlsActivationTest — Section 39A-3L, Phase B6,
 * Checkpoint 34. Proves the fifty-second and FINAL staged FORCE ROW
 * LEVEL SECURITY activation batch in this arc
 * (database/migrations/2026_08_25_930034_force_rls_on_security_events_
 * table.php) is permanently active for security_events and behaves
 * correctly.
 *
 * This is the eighth and final nullable-firm_id table in this category,
 * and the highest production-blast-radius checkpoint in the whole
 * mission: two of this table's four write call sites
 * (AppServiceProvider's Login/Failed guard-event listeners, both the
 * web and platform_admin guards) fire synchronously inside Laravel's
 * own authentication flow. A defective fix here does not merely lose an
 * audit row — it throws a hard, unhandled Postgres RLS exception out of
 * Auth::attemptWhen(), breaking login itself. This file's login proofs
 * are therefore real, end-to-end dispatches through Illuminate's actual
 * Login/Failed events (not raw SecurityEvent::create() calls), matching
 * this repo's own established idiom for exercising these listeners
 * (see FirmUserLoginPanelAccessTest/PlatformAdminLoginPanelAccessTest).
 *
 * Full design dossier: rls-checkpoints/39a3l/B6-security_events-design-
 * dossier.md. Unlike every prior checkpoint in this arc, this table
 * needed a genuinely THIRD distinct nullable-firm_id design (see the
 * dossier's own "why firm_id is nullable here, and why that's a third
 * distinct case" section): not the six "easy" tables' "visible to every
 * tenant regardless of context" shape, and not timeline_events'
 * "fail-closed to everyone" shape — instead, null rows are visible ONLY
 * when NO tenant context is active (a firm-scoped session may only read
 * its own firm's rows or, when no context is active at all, the
 * platform-wide rows; a context-free session may read only the
 * platform-wide rows, never any firm's private events). Both the READ
 * policy and the WRITE policy changed (a first for this arc's tables
 * with no pre-existing leak-vector concern on the read side alone), and
 * the write policy is deliberately FOR INSERT only (not FOR ALL) —
 * narrower than every other write policy in this category, since this
 * table is genuinely append-only with no live UPDATE/DELETE call site
 * anywhere. Under FORCE RLS with only FOR SELECT/FOR INSERT policies, a
 * stray UPDATE/DELETE is NOT rejected by Postgres with an exception —
 * it is a silent 0-row no-op — so real protection against a stray
 * mutation comes from a NEW app-layer booted() guard on SecurityEvent
 * (mirroring TrustLedgerEntry::booted() exactly), proven directly below
 * alongside the RLS-layer no-op behavior it complements.
 *
 * This checkpoint also bundles a real, independent, pre-existing bug
 * fix (dossier fix #0): the Login listener's $firmId resolution
 * previously always returned NULL under firm_users' own FORCE RLS
 * (a raw, unguarded firmUsers() query establishes no session setting),
 * regardless of whether a real active membership existed — fixed by
 * routing through User::activeFirmUser(), which correctly bootstraps
 * via the narrow app.current_user_id self-lookup primitive. This file's
 * successful-firm-user-login proof directly re-verifies that fix.
 */
class SecurityEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_25_930034_force_rls_on_security_events_table.php';

    private const ORIGINAL_POLICY = 'security_events_tenant_isolation';

    private const WRITE_POLICY = 'security_events_platform_write';

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events', 'intake_submissions',
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans',
        'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests',
        'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates',
        'pilot_feedback_items', 'timeline_events',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    private function insertRow(?int $firmId, string $eventType, array $overrides = []): int
    {
        return DB::table('security_events')->insertGetId(array_merge([
            'firm_id' => $firmId,
            'actor_type' => 'User',
            'actor_id' => null,
            'event_type' => $eventType,
            'category' => 'authentication',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'RLS proof agent',
            'metadata' => json_encode([]),
            'created_at' => now(),
        ], $overrides));
    }

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

    public function test_security_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'security_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_security_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'security_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'security_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly fifty-two tables (the fifty-one previously forced plus
     * security_events, the eighth and final nullable-firm_id table in
     * this arc) must be FORCE-enabled among ALL prepared tables — no
     * more, no less.
     */
    public function test_exactly_fifty_two_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['security_events']);

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

        $this->assertSame(52, count($actuallyForced), 'Exactly fifty-two prepared tables must be FORCE RLS enabled after Section 39A-3L, Phase B6, Checkpoint 34 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     * security_events is the eighth and FINAL nullable-firm_id table in
     * this arc, and this checkpoint is the last of the fifty-two
     * originally "prepared" (RLS-enabled-but-not-forced) tables — so
     * $forced now equals the FULL preparedTables() set exactly, and the
     * per-table loop below legitimately has zero remaining iterations.
     * That is a real, positive end state, not a test that accidentally
     * lost its assertions — so this test first asserts that fact
     * directly (every prepared table is accounted for, no more, no
     * fewer) before the now-vacuous loop, so the test always performs a
     * genuine assertion regardless of how many prepared tables exist.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['security_events']);

        sort($forced);
        $preparedTables = $coverage->preparedTables();
        sort($preparedTables);

        $this->assertSame(
            $forced,
            $preparedTables,
            'security_events is the final checkpoint in this arc — every originally "prepared" table must now be force-enabled, no more, no fewer.'
        );

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    /**
     * Unlike pilot_feedback_items (which drops the original policy NAME
     * entirely and replaces it with two brand-new names), this
     * migration DROPS the original security_events_tenant_isolation
     * policy and immediately RE-CREATES a policy under the SAME NAME —
     * but with a genuinely different shape (FOR SELECT only, with a new
     * IS NULL branch, not the original implicit FOR ALL with no IS
     * NULL branch). This test proves the original FOR ALL SHAPE is
     * gone even though the NAME persists — the real, structural change
     * this checkpoint made — while test_the_read_and_write_policies_
     * exist_with_the_expected_shape below proves the new shape directly.
     */
    public function test_the_original_policys_for_all_shape_no_longer_exists(): void
    {
        $policy = DB::selectOne(
            "select polcmd, pg_get_expr(polwithcheck, polrelid) as with_check_expr from pg_policy where polrelid = 'security_events'::regclass and polname = ?",
            [self::ORIGINAL_POLICY]
        );

        $this->assertNotNull($policy, 'A policy with the original name must still exist — it was re-created, not renamed away.');
        $this->assertNotSame('*', $policy->polcmd, 'The original policy was an implicit FOR ALL (polcmd = *) — that shape must be gone, replaced by a narrower FOR SELECT policy under the same name.');
        $this->assertNull($policy->with_check_expr, 'The original FOR ALL policy had no separate WITH CHECK (it reused USING) — the re-created FOR SELECT policy structurally cannot have one either, but for a different reason (SELECT never has WITH CHECK at all).');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'security_events'::regclass and polname = ?",
            [self::ORIGINAL_POLICY]
        );

        $this->assertNotNull($readPolicy, 'security_events_tenant_isolation must still exist, now re-created as FOR SELECT.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only — reusing the original policy NAME, but a genuinely narrower shape than the original FOR ALL policy it replaced.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr, 'unlike timeline_events, this table DOES have an IS NULL branch — but a narrower one than the six "easy" tables (only when context itself is also null).');
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'security_events'::regclass and polname = ?",
            [self::WRITE_POLICY]
        );

        $this->assertNotNull($writePolicy, 'security_events_platform_write must exist.');
        $this->assertSame('a', $writePolicy->polcmd, 'the write policy must be FOR INSERT only (polcmd = a) — deliberately narrower than every other write policy in this Phase B6 category, since this table has no live UPDATE/DELETE call site anywhere.');
        $this->assertNull($writePolicy->using_expr, 'a FOR INSERT-only policy has no USING clause at all — nothing to filter for a command with no pre-existing rows.');
        $this->assertNotNull($writePolicy->with_check_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);

        // Both policies use the IDENTICAL boolean expression — deliberate,
        // not an oversight (see the dossier's own "read and write
        // policies with matching, narrower semantics" section).
        $this->assertSame($readPolicy->using_expr, $writePolicy->with_check_expr, 'The read USING and write WITH CHECK expressions must be byte-for-byte identical.');
    }

    /**
     * Exactly two policies exist on this table.
     */
    public function test_exactly_two_policies_exist_on_security_events(): void
    {
        $count = DB::selectOne("select count(*) as c from pg_policy where polrelid = 'security_events'::regclass")->c;

        $this->assertSame(2, (int) $count, 'security_events must carry exactly two policies (read + write) — not the six-table three/four shape, and not timeline_events\' single-policy shape.');
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and timeline_events' own single, untouched policy (the
     * immediately prior checkpoint) as representative unrelated
     * policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $timelineEventsPolicy = DB::selectOne("select polname, pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'timeline_events'::regclass");
        $this->assertNotNull($timelineEventsPolicy);
        $this->assertSame('timeline_events_tenant_isolation', $timelineEventsPolicy->polname);
        $this->assertStringNotContainsString('IS NULL', $timelineEventsPolicy->using_expr, 'timeline_events\' own deliberately fail-closed policy must remain completely untouched by this checkpoint.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs — a real, non-null firm_id row
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific-no-context-read'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, SecurityEvent::query()->where('firm_id', $firm->id)->count());
    }

    public function test_missing_tenant_context_cannot_insert_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->insertRow($firm->id, 'no-context-insert');
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_security_event(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SecurityEvent::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_security_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => SecurityEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($rowB, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'valid-insert'));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_row_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmB->id, 'claimed-ownership'));
    }

    // ---------------------------------------------------------------
    // Central novel proof — this table's own deliberate middle-ground
    // design: null rows are visible ONLY when no tenant context is
    // active, never to a firm-scoped session regardless of which firm.
    // This is the one assertion with no precedent in any prior
    // checkpoint's test file in this arc.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_context_cannot_read_a_platform_wide_null_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-not-visible-to-firm'));

        $firm = Firm::factory()->create();

        $visible = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()->find($platformWideId));

        $this->assertNull($visible, 'Unlike the six "easy" tables in this arc, a firm-scoped session must NOT see a platform-wide (firm_id = NULL) security_events row — this table\'s own deliberate, narrower design (see the dossier\'s own "considered tradeoff" section).');
    }

    public function test_a_firm_scoped_context_cannot_read_a_platform_wide_null_row_regardless_of_which_firm(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-invisible-to-every-firm'));

        foreach (range(1, 3) as $i) {
            $firm = Firm::factory()->create();
            $visible = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()->find($platformWideId));
            $this->assertNull($visible, "A platform-wide row must be invisible to firm #{$i}'s own context too — no firm-scoped session may see it, unlike the six-table 'visible to everyone' pattern.");
        }
    }

    public function test_a_context_free_session_can_read_a_platform_wide_row_but_not_any_firms_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-context-free-visible'));
        $firm = Firm::factory()->create();
        $firmRowId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-row-context-free-invisible'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertNotNull(SecurityEvent::query()->find($platformWideId), 'A genuinely context-free session must be able to read the platform-wide row it is also able to write.');
        $this->assertNull(SecurityEvent::query()->find($firmRowId), 'A genuinely context-free session must NOT be able to read a real firm\'s own row.');
    }

    /**
     * Direct SQL-level proof this table's own middle-ground design does
     * not leak a sibling firm's real rows even though a platform-wide
     * row can, under the right (context-free) circumstances, be seen —
     * the two guarantees are independent.
     */
    public function test_a_platform_wide_row_does_not_expose_any_firms_specific_row(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked'));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => SecurityEvent::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visibleToA, 'Firm A must still not see Firm B\'s firm-specific row.');
    }

    // ---------------------------------------------------------------
    // WITH CHECK forgery-prevention proofs
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_insert_a_forged_platform_wide_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-platform-wide'));
    }

    public function test_a_genuinely_context_free_session_can_insert_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $insertedId = $this->insertRow(null, 'legitimate-platform-wide');

        $this->assertIsInt($insertedId);
    }

    // ---------------------------------------------------------------
    // Append-only enforcement proofs — TWO independent layers,
    // deliberately both proven directly: the app-layer LogicException
    // guard (real enforcement) AND the RLS-layer silent no-op (the
    // FOR INSERT-only write policy's own actual, corrected behavior —
    // NOT an exception, per Design Reviewer 1's empirical finding).
    // ---------------------------------------------------------------

    public function test_updating_an_existing_security_event_throws_a_logic_exception_at_the_app_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => SecurityEvent::create([
            'firm_id' => $firm->id,
            'actor_type' => 'User',
            'event_type' => 'append-only-update-check',
            'category' => 'authentication',
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('security_events is append-only: an existing row can never be updated.');

        $this->runWithFirmContext($firm, fn () => $event->update(['event_type' => 'mutated']));
    }

    public function test_deleting_an_existing_security_event_throws_a_logic_exception_at_the_app_layer(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => SecurityEvent::create([
            'firm_id' => $firm->id,
            'actor_type' => 'User',
            'event_type' => 'append-only-delete-check',
            'category' => 'authentication',
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('security_events is append-only: an existing row can never be deleted.');

        $this->runWithFirmContext($firm, fn () => $event->delete());
    }

    /**
     * Defense-in-depth completeness proof, matching the dossier's own
     * corrected understanding: bypassing the Eloquent model entirely
     * (raw DB::table() update, no booted() guard involved) and issuing
     * the update from the ROW'S OWN OWNING FIRM's context (so the row
     * is genuinely visible under the read policy's USING clause) still
     * affects ZERO rows — a silent no-op, NOT a thrown exception —
     * because no UPDATE policy exists for this table at all (only
     * FOR SELECT/FOR INSERT), so Postgres has no policy to consult and
     * no row qualifies for the command.
     */
    public function test_a_direct_database_update_bypassing_the_model_is_a_silent_no_op_under_force(): void
    {
        $firm = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'raw-update-bypass-target'));

        $affected = $this->runWithFirmContext($firm, function () use ($rowId) {
            return DB::table('security_events')->where('id', $rowId)->update(['event_type' => 'hijacked-via-raw-update']);
        });

        $this->assertSame(0, $affected, 'A direct DB::table() UPDATE must affect zero rows under FORCE RLS — no UPDATE policy exists, so no row ever qualifies, and this is a SILENT no-op, not an exception.');

        $stillOriginal = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()->find($rowId));
        $this->assertNotNull($stillOriginal);
        $this->assertSame('raw-update-bypass-target', $stillOriginal->event_type, 'The row\'s content must be completely unchanged after the no-op update attempt.');
    }

    public function test_a_direct_database_delete_bypassing_the_model_is_a_silent_no_op_under_force(): void
    {
        $firm = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'raw-delete-bypass-target'));

        $affected = $this->runWithFirmContext($firm, function () use ($rowId) {
            return DB::table('security_events')->where('id', $rowId)->delete();
        });

        $this->assertSame(0, $affected, 'A direct DB::table() DELETE must affect zero rows under FORCE RLS — no DELETE policy exists either, matching the UPDATE case exactly.');

        $stillExists = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()->find($rowId));
        $this->assertNotNull($stillExists, 'The row must genuinely still exist after the no-op delete attempt.');
    }

    // ---------------------------------------------------------------
    // Real end-to-end production writer proofs — the highest-blast-
    // radius call sites in this entire mission: AppServiceProvider's
    // Login/Failed listeners, fired through Illuminate's real auth
    // events (not a raw SecurityEvent::create() call), proving auth
    // itself survives FORCE for both guards.
    // ---------------------------------------------------------------

    public function test_successful_web_guard_firm_user_login_survives_force_and_records_a_real_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        // Real end-to-end dispatch through the actual Login event and
        // AppServiceProvider's registered listener — proving auth
        // itself does not throw under FORCE, not just that a row can be
        // written in isolation.
        auth('web')->login($user);

        $event = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('actor_type', User::class)
                ->where('actor_id', $user->id)
                ->where('event_type', 'login_succeeded')
                ->first(),
        );

        $this->assertNotNull($event, 'A successful web-guard firm-user login must survive FORCE and genuinely persist a security_events row.');
        // Fix #0 re-verified directly: firm_id must be the user's REAL
        // active firm, resolved via User::activeFirmUser()'s
        // app.current_user_id bootstrap — not silently NULL, which is
        // what the pre-existing, independent bug produced before this
        // checkpoint's fix.
        $this->assertSame($firm->id, $event->firm_id);
    }

    public function test_failed_web_guard_login_survives_force_and_records_a_null_firm_id_event(): void
    {
        $email = 'attempted-user@example.com';

        event(new Failed('web', null, ['email' => $email, 'password' => 'wrong']));

        $event = SecurityEvent::query()
            ->where('event_type', 'login_failed')
            ->where('category', 'authentication')
            ->first();

        $this->assertNotNull($event, 'A failed web-guard login must survive FORCE and genuinely persist a security_events row, readable with no ambient context.');
        $this->assertNull($event->firm_id, 'A failed login is never attributable to a firm.');
        $this->assertSame($email, $event->metadata['attempted_email'] ?? null);
        $this->assertArrayNotHasKey('password', $event->metadata, 'The audit log must never store the attempted password.');
    }

    public function test_successful_platform_admin_guard_login_survives_force_and_records_a_null_firm_id_event(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        auth('platform_admin')->login($admin);

        $event = SecurityEvent::query()
            ->where('actor_type', PlatformAdmin::class)
            ->where('actor_id', $admin->id)
            ->where('event_type', 'login_succeeded')
            ->first();

        $this->assertNotNull($event, 'A successful platform_admin-guard login must survive FORCE and genuinely persist a security_events row, readable with no ambient context.');
        $this->assertNull($event->firm_id, 'A platform admin has no firm membership at all.');
    }

    public function test_failed_platform_admin_guard_login_survives_force_and_records_a_null_firm_id_event(): void
    {
        PlatformAdmin::factory()->create(['email' => 'admin@example.com', 'is_active' => true]);

        event(new Failed('platform_admin', null, ['email' => 'admin@example.com', 'password' => 'wrong']));

        $event = SecurityEvent::query()
            ->where('event_type', 'login_failed')
            ->where('category', 'authentication')
            ->where('metadata->guard', 'platform_admin')
            ->first();

        $this->assertNotNull($event, 'A failed platform_admin-guard login must survive FORCE and genuinely persist a security_events row.');
        $this->assertNull($event->firm_id);
    }

    public function test_login_listener_clears_database_context_after_a_successful_firm_user_login(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        FirmUser::factory()->forFirm($firm)->forUser($user)->create(['status' => FirmUserStatus::Active]);

        auth('web')->login($user);

        $this->assertNoDatabaseTenantContext('The Login listener\'s try/finally must clear DB-session context before returning, regardless of firm_id.');
    }

    // ---------------------------------------------------------------
    // Real end-to-end production writer proofs — SupportAccessPolicyService
    // ---------------------------------------------------------------

    public function test_support_access_policy_service_log_notification_survives_force_and_records_a_firm_scoped_event(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $request = app(SupportAccessRequestService::class)->request($firm, $admin, SupportAccessType::Standard, 'reason', 60);

        app(SupportAccessPolicyService::class)->logNotification($request, 'support_access_notification_sent');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()->where('event_type', 'support_access_notification_sent')->first(),
        );

        $this->assertNotNull($event, 'SupportAccessPolicyService::logNotification() must genuinely persist its row under FORCE.');
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_support_access_policy_service_log_session_audit_survives_force_and_records_a_firm_scoped_event(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $firmOwner = FirmUser::factory()->forFirm($firm)->create();
        $request = app(SupportAccessRequestService::class)->request($firm, $admin, SupportAccessType::Standard, 'reason', 60);
        app(SupportAccessRequestService::class)->approve($request, $firmOwner);

        $sessionId = DB::table('support_access_sessions')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'support_access_request_id' => $request->id,
            'firm_id' => $firm->id,
            'platform_admin_id' => $admin->id,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $session = \App\Models\SupportAccessSession::query()->find($sessionId);

        app(SupportAccessPolicyService::class)->logSessionAudit($session, 'support_access_session_started');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()->where('event_type', 'support_access_session_started')->first(),
        );

        $this->assertNotNull($event, 'SupportAccessPolicyService::logSessionAudit() must genuinely persist its row under FORCE.');
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Real end-to-end production writer proof — WebhookReplayService
    // ---------------------------------------------------------------

    public function test_webhook_replay_service_replay_survives_force_and_records_a_firm_scoped_security_event(): void
    {
        $firm = Firm::factory()->create();
        app(\App\Services\EntitlementService::class)->setForSource($firm, 'webhook', \App\Enums\EntitlementSource::AdminOverride, true);
        app(\App\Services\EncryptionKeyService::class)->provision($firm);
        $owner = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => \App\Enums\FirmUserRole::FirmOwner]);

        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        $webhookEvent = WebhookEvent::factory()->forFirm($firm)->create();
        $originalDelivery = WebhookDelivery::factory()->exhausted()->create([
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'webhook_event_id' => $webhookEvent->id,
        ]);

        $replay = app(WebhookReplayService::class)->replay($firm, $originalDelivery, $owner);

        $this->assertSame(WebhookDeliveryStatus::Pending, $replay->status);
        $this->assertNoDatabaseTenantContext('WebhookReplayService::replay() must clear its own narrow context wrap before returning.');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()->where('firm_id', $firm->id)->where('event_type', 'webhook_delivery_replayed')->first(),
        );
        $this->assertNotNull($event, 'WebhookReplayService::replay() must genuinely persist its security_events audit row under FORCE — the call site deliberately deferred from the timeline_events checkpoint.');
        $this->assertSame('webhook_replay', $event->category);
        $this->assertSame($replay->id, $event->metadata['new_webhook_delivery_id']);
    }

    // ---------------------------------------------------------------
    // Real end-to-end production writer proofs —
    // HighRiskPlatformChangePolicyService, exercised through 2 of its 6
    // real callers (not a direct call to the policy service itself).
    // ---------------------------------------------------------------

    public function test_support_access_request_service_emergency_request_survives_force_and_audits_via_high_risk_policy(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        app(SupportAccessRequestService::class)->request(
            $firm, $admin, SupportAccessType::Emergency, 'production incident', 30, 'Active outage impacting client access'
        );

        // HighRiskPlatformChangePolicyService::audit() always writes
        // firm_id = null, wrapped in runWithoutFirmContext() — this is
        // NOT merely defensive: a coordinator-run full-suite check
        // proved it LIVE, not latent — TrustModeActivationServiceTest
        // creates a FirmUser via a factory that deliberately leaves
        // DB-session context set to that firm afterward (the same
        // established "leave it set" factory pattern used throughout
        // this mission), and audit() runs moments later in the same
        // test — without the wrap, that stale non-null context makes
        // the null-firm_id insert fail its WITH CHECK outright.
        $event = SecurityEvent::query()
            ->where('event_type', 'high_risk_change_requested')
            ->where('category', 'high_risk_change')
            ->first();

        $this->assertNotNull($event, 'SupportAccessRequestService (real caller 1 of 6) must survive FORCE and genuinely persist its high_risk_change audit row via HighRiskPlatformChangePolicyService.');
        $this->assertNull($event->firm_id);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_dedicated_customer_type_approval_service_survives_force_and_audits_via_high_risk_policy(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $request = app(DedicatedCustomerTypeApprovalService::class)->requestApproval($firm, $admin, 'Firm is a legal specialist, not a traditional law firm.');

        $event = SecurityEvent::query()
            ->where('event_type', 'high_risk_change_requested')
            ->where('category', 'high_risk_change')
            ->first();

        $this->assertNotNull($event, 'DedicatedCustomerTypeApprovalService (real caller 2 of 6) must survive FORCE and genuinely persist its high_risk_change audit row via HighRiskPlatformChangePolicyService.');
        $this->assertNull($event->firm_id, 'HighRiskPlatformChangePolicyService::audit() always writes firm_id = null, defensively wrapped in runWithoutFirmContext() (proven load-bearing, not merely defensive — see note above).');
        $this->assertSame($request->id, $event->metadata['high_risk_change_request_id']);
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_platform_wide_and_readable_with_no_context(): void
    {
        $event = SecurityEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNull($event->firm_id, 'SecurityEventFactory::definition() defaults firm_id to null — platform-level events are legitimate, matching every prior nullable-firm_id table\'s null-by-default factory except pilot_feedback_items.');

        $this->tenantContext()->clearDatabaseTenantContext();
        $persisted = SecurityEvent::query()->find($event->id);
        $this->assertNotNull($persisted, 'A bare factory-created platform-wide row must be visible with no ambient context.');

        $firm = Firm::factory()->create();
        $notVisibleToFirm = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()->find($event->id));
        $this->assertNull($notVisibleToFirm, 'A bare factory-created platform-wide row must NOT be visible under any firm\'s own context — this table\'s own narrower design, unlike pilot_feedback_items.');
    }

    public function test_explicit_for_firm_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $event = SecurityEvent::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $event->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()->find($event->id));
        $this->assertNotNull($persisted);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => SecurityEvent::query()->find($event->id));
        $this->assertNull($notVisibleToOther);
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        \App\Models\Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $event = SecurityEvent::factory()->create();

        $this->assertNull($event->firm_id, 'The bare factory create() must still succeed and produce its own genuinely resolved null firm_id, despite the stale ambient context from a prior factory call — this table\'s context-hold override must clear context for the null-default group regardless of what preceded it.');
    }

    /**
     * The multi-firm factory grouping fix, proven directly: a single
     * count()->create() call spanning two different explicit firm_ids
     * must correctly set DB-session context once per group, not once
     * for the whole batch (this exact scenario is unique to this
     * table's factory in this arc, since it's the only nullable-firm_id
     * model using BelongsToTenant, matching BackupRestoreTestFactory's
     * own proven template).
     */
    public function test_factory_create_with_multiple_firms_in_one_call_groups_correctly(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $events = SecurityEvent::factory()->count(2)->state(new \Illuminate\Database\Eloquent\Factories\Sequence(
            ['firm_id' => $firmA->id],
            ['firm_id' => $firmB->id],
        ))->create();

        $eventA = $events->firstWhere('firm_id', $firmA->id);
        $eventB = $events->firstWhere('firm_id', $firmB->id);

        $visibleToA = $this->runWithFirmContext($firmA, fn () => SecurityEvent::query()->find($eventA->id));
        $this->assertNotNull($visibleToA);

        $visibleToB = $this->runWithFirmContext($firmB, fn () => SecurityEvent::query()->find($eventB->id));
        $this->assertNotNull($visibleToB);

        $crossVisible = $this->runWithFirmContext($firmA, fn () => SecurityEvent::query()->find($eventB->id));
        $this->assertNull($crossVisible, 'Firm A\'s context must not see Firm B\'s row from the same batched factory call.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'context-clears-success'));

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

    public function test_failed_listener_clears_database_context_after_success(): void
    {
        event(new Failed('web', null, ['email' => 'lifecycle-check@example.com', 'password' => 'wrong']));

        $this->assertNoDatabaseTenantContext('The Failed listener\'s try/finally must clear DB-session context before returning.');
    }

    public function test_high_risk_platform_change_policy_service_audit_clears_context_after_success(): void
    {
        $firm = Firm::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        app(DedicatedCustomerTypeApprovalService::class)->requestApproval($firm, $admin, 'Context lifecycle proof.');

        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation / scope-boundary proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg('app/Services/ComplianceGapRegistryService.php')
        ));

        $this->assertSame('', $changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    /**
     * No UI/route/domain/deployment/payment/storage/AI/client-portal/
     * marketplace surface was added by this checkpoint — an
     * application-code-prerequisite-plus-migration-plus-test change
     * only.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Phase B6, Checkpoint 34 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Fifty-one previously forced tables plus security_events must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses timeline_events as the
     * companion table (forced immediately prior, at Checkpoint 33).
     */
    public function test_security_events_are_isolated_independently_and_simultaneously_with_timeline_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b'));

        $timelineA = $this->runWithFirmContext($firmA, fn () => DB::table('timeline_events')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firmA->id,
            'event_type' => 'simultaneous-proof-a',
            'occurred_at' => now(),
            'metadata_json' => json_encode([]),
            'created_at' => now(),
        ]));
        $timelineB = $this->runWithFirmContext($firmB, fn () => DB::table('timeline_events')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firmB->id,
            'event_type' => 'simultaneous-proof-b',
            'occurred_at' => now(),
            'metadata_json' => json_encode([]),
            'created_at' => now(),
        ]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'security_events' => SecurityEvent::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'timeline_events' => DB::table('timeline_events')->where('firm_id', $firmA->id)->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['security_events']);
        $this->assertNotContains($rowB, $resultA['security_events']);
        $this->assertContains($timelineA, $resultA['timeline_events']);
        $this->assertNotContains($timelineB, $resultA['timeline_events']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: down() must genuinely restore the original,
     * pre-checkpoint baseline — RLS still enabled, FORCE cleared, both
     * new policies dropped, and the ORIGINAL single-expression policy
     * restored byte-for-byte (no IS NULL branch, no separate WITH
     * CHECK — quoted directly from the Phase 1 preparation migration).
     * Also proves rollback affects ONLY this one table. up() is
     * re-run in a finally block so this test leaves the schema in the
     * same state it found it in.
     */
    public function test_security_events_migration_down_restores_the_original_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'security_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while security_events is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'security_events'::regclass and polname = ?", [self::ORIGINAL_POLICY.'_never_created_placeholder']);
            $this->assertNull($readPolicy, 'sanity check: this placeholder policy name must never exist.');

            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'security_events'::regclass and polname = ?", [self::WRITE_POLICY]);
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'security_events'::regclass and polname = ?",
                [self::ORIGINAL_POLICY]
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertSame('*', $originalPolicy->polcmd, 'The restored original policy must be a FOR ALL policy (polcmd = *), not the FOR SELECT it was re-created as during this checkpoint.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 1 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');

            $policyCount = DB::selectOne("select count(*) as c from pg_policy where polrelid = 'security_events'::regclass")->c;
            $this->assertSame(1, (int) $policyCount, 'Exactly one policy must exist during the rollback window.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'security_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'security_events'::regclass and polname = ?", [self::WRITE_POLICY]);
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');

        $readPolicyAfterUp = DB::selectOne("select polcmd from pg_policy where polrelid = 'security_events'::regclass and polname = ?", [self::ORIGINAL_POLICY]);
        $this->assertSame('r', $readPolicyAfterUp->polcmd, 'up() must recreate the read policy as FOR SELECT, not the original FOR ALL shape.');

        $policyCountAfterUp = DB::selectOne("select count(*) as c from pg_policy where polrelid = 'security_events'::regclass")->c;
        $this->assertSame(2, (int) $policyCountAfterUp, 'Exactly two policies must exist again after up() is restored.');
    }
}
