<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use App\Services\ClientPortalService;
use App\Services\ComplianceGapRegistryService;
use App\Services\ConsentService;
use App\Services\NotificationDispatchService;
use App\Services\NotificationEligibilityService;
use App\Services\NotificationTemplateService;
use App\Services\PaymentPlanDunningService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\SenderDomainVerificationService;
use App\Services\SuppressionService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CommunicationConsentsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 11, Table Phase C. Proves the twenty-ninth staged FORCE
 * ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php)
 * is permanently active for communication_consents and behaves
 * correctly: fail-closed with no context, correct cross-firm
 * isolation, correct same-firm access, that every previously-forced
 * table remains forced simultaneously, and that ConsentService's
 * capture()/revoke() (each now wrapping its whole body in
 * runWithFirmContext()) and ClientPortalService::invite() (whose
 * isGranted() precondition read now shares its existing
 * runWithFirmContext() wrap) function correctly end-to-end under
 * FORCE.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * the migration's own docblock): communication_consents.client_id
 * firm-ownership is not validated at the app layer — ConsentService::
 * capture() never checks that the given client_id actually belongs to
 * the given firm before insert. FORCE RLS does not catch this (RLS
 * only checks communication_consents.firm_id itself, never a related
 * row's firm_id), so a cross-firm client_id reference remains possible
 * today. See
 * test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 *
 * Also proves, honestly, the current behavior of the two production
 * callers the migration's own docblock explicitly deferred fixing:
 * PaymentPlanDunningService::checkAndLog() and
 * NotificationDispatchService::dispatch() (via
 * NotificationEligibilityService::check()) both call
 * ConsentService::isGranted() unwrapped. Neither has a real production
 * caller today, so this is documented, not fixed. Empirically, the two
 * behave differently when invoked with genuinely zero tenant context:
 * dispatch() gracefully reports the notification as blocked (every
 * value it dereferences before the consent check is either an
 * already-resolved PHP object or tolerant of a null preference row),
 * while checkAndLog() throws (its own $plan->client lazy-load hits the
 * unrelated, already-FORCE-protected clients table before ever
 * reaching the consent check, and dereferencing the resulting null
 * throws). Both are named and proven exactly as they behave — neither
 * is described as a false "this is blocked" guarantee where it is
 * actually an uncaught error.
 */
class CommunicationConsentsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
    ];

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

    public function test_communication_consents_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'communication_consents'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_communication_consents_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'communication_consents'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'communication_consents must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Originally: exactly twenty-nine tables (the twenty-eight
     * previously forced plus communication_consents) had to be
     * FORCE-enabled among ALL prepared tables — no more, no less.
     *
     * Updated by Section 39A-3L, Checkpoint 12, Table Phase C:
     * communication_consent_events has since also been forced, so the
     * live, current expectation is now exactly THIRTY — this test's
     * method name is left unchanged (it names the batch this file is
     * about, not a permanently-fixed total), but its assertions below
     * check the true, current count.
     */
    public function test_exactly_twenty_nine_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table
        // Phase C (communication_consent_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (this repo's fortieth staged FORCE activation batch, covering payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans']);

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
        $this->assertSame(40, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (communication_consent_events added on top of this batch\'s own communication_consents, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     *
     * Updated by Section 39A-3L, Checkpoint 12, Table Phase C: at the
     * time this test was originally written (Checkpoint 11),
     * communication_consent_events was deliberately deferred — RLS
     * enabled but NOT forced, pending its own factory cross-firm-
     * mismatch fix. Checkpoint 12 has since landed
     * (database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php),
     * so communication_consent_events is now ALSO force-enabled. This
     * test is updated to assert the CURRENT true state rather than
     * continue asserting the now-superseded "deferred" state, which
     * would otherwise be a false claim about the live schema.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled_and_events_table_remains_correctly_scoped(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table
        // Phase C (communication_consent_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans']);

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }

        $this->assertContains('communication_consent_events', $coverage->preparedTables(), 'communication_consent_events must remain a genuinely prepared (RLS-enabled) table.');

        $eventsRow = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', ['communication_consent_events']);
        $this->assertNotNull($eventsRow, 'Table communication_consent_events not found in pg_class.');
        $this->assertTrue((bool) $eventsRow->relrowsecurity, 'communication_consent_events must remain RLS-enabled (prepared).');
        $this->assertTrue((bool) $eventsRow->relforcerowsecurity, 'communication_consent_events must now be FORCE RLS enabled — Section 39A-3L, Checkpoint 12 landed this activation.');
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'communication_consents'::regclass"
        );

        $this->assertNotNull($policy, 'The communication_consents tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the
     * read genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_communication_consents(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, CommunicationConsent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_communication_consents(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('communication_consents')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'client_id' => null,
            'channel' => ConsentChannel::Email->value,
            'status' => ConsentStatus::Granted->value,
            'consent_text_version' => 'v1',
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_communication_consent(): void
    {
        $firmA = Firm::factory()->create();
        $consentA = $this->runWithFirmContext($firmA, fn () => CommunicationConsent::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => CommunicationConsent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$consentA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_communication_consent(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => CommunicationConsent::factory()->forFirm($firmA)->create());
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => CommunicationConsent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($consentB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('communication_consents')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'client_id' => null,
                'channel' => ConsentChannel::Email->value,
                'status' => ConsentStatus::Granted->value,
                'consent_text_version' => 'v1',
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_communication_consent_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('communication_consents')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'client_id' => null,
                'channel' => ConsentChannel::Email->value,
                'status' => ConsentStatus::Granted->value,
                'consent_text_version' => 'v1',
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_communication_consent(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($consentB) {
            DB::table('communication_consents')->where('id', $consentB->id)->update(['consent_text_version' => 'hijacked-v2']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CommunicationConsent::withoutGlobalScopes()->find($consentB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            $consentB->consent_text_version,
            $reReadAsFirmB->consent_text_version,
            'Firm A context must not be able to update Firm B\'s communication_consents row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_communication_consent(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($consentB) {
            DB::table('communication_consents')->where('id', $consentB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CommunicationConsent::withoutGlobalScopes()->find($consentB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s communication_consents row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_communication_consent_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $consentB) {
            return DB::table('communication_consents')->where('id', $consentB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s communication consent to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CommunicationConsent::withoutGlobalScopes()->find($consentB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates communication_consents.firm_id, never client_id's OWN
     * firm_id — a raw insert whose firm_id matches the active context
     * still succeeds even when client_id points at a Client belonging
     * to a COMPLETELY DIFFERENT firm. This is a documented residual
     * DATABASE-CONSTRAINT gap, not something RLS itself closes — never
     * to be described as blocked.
     */
    public function test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());

        $mismatchedConsentId = $this->runWithFirmContext($firm, function () use ($firm, $foreignClient) {
            return DB::table('communication_consents')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'client_id' => $foreignClient->id,
                'channel' => ConsentChannel::Email->value,
                'status' => ConsentStatus::Granted->value,
                'consent_text_version' => 'v1',
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedConsentId,
            'RLS only checks the row\'s own firm_id — a client_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: a bare CommunicationConsent::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and the row must
     * actually be visible/readable under its own firm's context
     * afterward.
     */
    public function test_communication_consent_factory_default_creation_is_internally_consistent(): void
    {
        $consent = CommunicationConsent::factory()->create();

        $this->assertNotNull($consent->id);
        $this->assertNotNull($consent->firm_id);
        $this->assertNull($consent->client_id, 'The bare factory definition() deliberately leaves client_id null — no cross-firm mismatch to check for a bare default.');

        $persisted = $this->runWithFirmContext(
            $consent->firm,
            fn () => CommunicationConsent::withoutGlobalScopes()->find($consent->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($consent->firm_id, $persisted->firm_id);
    }

    /**
     * Explicit related-model factory state correctness: forClient()
     * must set firm_id/client_id to the EXACT client given, and the row
     * must be readable only under that firm's context.
     */
    public function test_communication_consent_factory_for_client_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forClient($client)->create());

        $this->assertSame($firm->id, $consent->firm_id);
        $this->assertSame($client->id, $consent->client_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => CommunicationConsent::withoutGlobalScopes()->find($consent->id),
        );

        $this->assertNotNull($persisted);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());

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

    /**
     * End-to-end proof that ConsentService::capture() functions
     * correctly under FORCE — wraps its whole body (including the
     * paired CommunicationConsentEvent::create() call) in a single
     * runWithFirmContext() call and clears context before returning.
     */
    public function test_the_capture_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $actor = User::factory()->create();
        $service = new ConsentService();

        $consent = $service->capture(
            firm: $firm,
            clientId: $client->id,
            channel: ConsentChannel::Sms,
            consentTextVersion: 'v1',
            actor: $actor,
            capturedVia: 'intake_form',
            capturedIp: '10.0.0.5',
        );

        $this->assertNoDatabaseTenantContext('capture() must clear its own context wrap before returning.');
        $this->assertSame(ConsentStatus::Granted, $consent->status);
        $this->assertNotNull($consent->granted_at);

        $eventCount = $this->runWithFirmContext($firm, fn () => $consent->events()->count());
        $this->assertSame(1, $eventCount, 'capture() must persist the paired CommunicationConsentEvent row under FORCE.');
    }

    /**
     * End-to-end proof that ConsentService::revoke() functions
     * correctly under FORCE, including re-capture (updating an
     * existing row in place, never duplicating it).
     */
    public function test_the_revoke_flow_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $service = new ConsentService();

        $service->capture($firm, $client->id, ConsentChannel::WhatsApp, 'v1');
        $revoked = $service->revoke($firm, $client->id, ConsentChannel::WhatsApp, reason: 'client requested opt-out');

        $this->assertNoDatabaseTenantContext('revoke() must clear its own context wrap before returning.');
        $this->assertSame(ConsentStatus::Revoked, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);

        $recapture = $service->capture($firm, $client->id, ConsentChannel::WhatsApp, 'v2');
        $this->assertNoDatabaseTenantContext();
        $this->assertSame($revoked->id, $recapture->id, 'capture() must update the existing row in place, never duplicate it.');
        $this->assertSame(ConsentStatus::Granted, $recapture->status);

        $rowCount = $this->runWithFirmContext(
            $firm,
            fn () => CommunicationConsent::withoutGlobalScopes()
                ->where('firm_id', $firm->id)
                ->where('client_id', $client->id)
                ->where('channel', ConsentChannel::WhatsApp->value)
                ->count(),
        );
        $this->assertSame(1, $rowCount);
    }

    /**
     * Proves the real fix this batch made to
     * ClientPortalService::invite(): moving the isGranted()
     * precondition check inside the existing runWithFirmContext() wrap
     * makes it correctly find a genuinely granted portal consent (NOT
     * silently always-throwing, which is exactly the bug this fix
     * closed — before the fix, isGranted() ran BEFORE the wrap began,
     * so it would always see zero rows once communication_consents
     * became FORCE-protected).
     */
    public function test_client_portal_invite_correctly_succeeds_when_portal_consent_is_genuinely_granted(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Portal, 'v1');

        $service = new ClientPortalService(new ConsentService());
        $invited = $service->invite($client);

        $this->assertNoDatabaseTenantContext('invite() must clear its own context wrap before returning.');
        $this->assertSame(ClientPortalStatus::Invited, $invited->portal_status);
        $this->assertNotNull($invited->portal_invitation_token);
    }

    /**
     * Companion proof: invite() must still genuinely throw (not
     * silently succeed) when no portal consent has been granted —
     * proving the fix did not flip the precondition to always-pass.
     */
    public function test_client_portal_invite_correctly_throws_when_no_portal_consent_is_granted(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $service = new ClientPortalService(new ConsentService());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot invite client to the portal without a granted, unrevoked portal consent record.');

        $service->invite($client);
    }

    /**
     * Regression proof (documented, not fixed — see this file's own
     * class docblock and the migration's docblock): calling
     * NotificationDispatchService::dispatch() with genuinely zero
     * tenant context still correctly reports the notification as
     * blocked/not-eligible even though a real, granted consent exists
     * — isGranted()'s unwrapped read simply sees zero rows and returns
     * false, which is the correct fail-closed direction. No production
     * caller invokes dispatch() without an already-active context
     * today; this proves the deferred gap fails closed, not open.
     */
    public function test_notification_dispatch_service_still_fails_closed_when_called_with_zero_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
            'granted_at' => now(),
        ]));
        \App\Models\NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => \App\Enums\NotificationTemplateStatus::Active,
        ]);

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = new NotificationDispatchService(
            new NotificationTemplateService(),
            new SenderDomainVerificationService(),
            new NotificationEligibilityService(new ConsentService(), new SuppressionService()),
        );

        $result = $service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertFalse(
            $result->accepted,
            'dispatch() has no production caller today and is documented as deferred — it must still correctly fail closed (never silently accept) when called with no active tenant context, even though a real granted consent exists.'
        );
        $this->assertStringContainsString('no granted consent', (string) $result->reason);
    }

    /**
     * Regression proof (documented, not fixed): unlike dispatch()
     * above, calling PaymentPlanDunningService::checkAndLog() with
     * genuinely zero tenant context does NOT gracefully report
     * ineligible — it throws, because its own unwrapped
     * `$plan = $installment->paymentPlan; ... $client = $plan->client;`
     * lazy-load hits the ALREADY-FORCE-PROTECTED clients table (forced
     * many checkpoints before this one) before ever reaching the
     * consent check, and the resulting null $client is then
     * dereferenced. This is real, pre-existing, and unrelated to
     * communication_consents itself — named and proven exactly as it
     * behaves, not smoothed over as "blocked". No production caller
     * invokes checkAndLog() without an already-active context today.
     */
    public function test_payment_plan_dunning_check_and_log_throws_rather_than_silently_succeeding_with_zero_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
            'granted_at' => now(),
        ]));
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forClient($client)->active()->create());
        $installment = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanInstallment::factory()->forPlan($plan)->status(\App\Enums\PaymentPlanInstallmentStatus::Missed)->create(),
        );

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentPlanDunningService(new ConsentService(), new TimelineEventRecorder());

        // Deliberately NOT asserting eligible=false here — that would
        // be a false guarantee. The real, current behavior is an
        // uncaught error, which this test names and proves honestly.
        $this->expectException(\Throwable::class);

        $service->checkAndLog($installment);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Twenty-eight previously forced tables plus communication_consents
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere
     * with any prior section's own enforcement. Uses clients as the
     * companion table.
     */
    public function test_communication_consents_is_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $consentA = $this->runWithFirmContext($firmA, fn () => CommunicationConsent::factory()->forClient($clientA)->create());
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forClient($clientB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'communication_consents' => CommunicationConsent::withoutGlobalScopes()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$consentA->id], $resultA['communication_consents']);
        $this->assertNotContains($consentB->id, $resultA['communication_consents']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself. Also proves rollback affects ONLY this one table — every
     * other previously-forced table must be untouched.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'communication_consents'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while communication_consents is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'communication_consents'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'communication_consents'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
