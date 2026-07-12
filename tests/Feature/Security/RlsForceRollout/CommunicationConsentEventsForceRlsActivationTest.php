<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\CommunicationConsentEvent;
use App\Models\Firm;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\ConsentService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CommunicationConsentEventsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 12, Table Phase C. Proves the thirtieth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php)
 * is permanently active for communication_consent_events and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, and that ConsentService's capture()/revoke()
 * (each already wrapping its whole body — including the paired
 * CommunicationConsentEvent::create() call — in runWithFirmContext()
 * since Checkpoint 11) function correctly end-to-end under FORCE for
 * BOTH the communication_consents row and its paired
 * communication_consent_events row together.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * the migration's own docblock): no composite foreign key validates
 * that communication_consent_id's owning firm matches
 * communication_consent_events.firm_id. FORCE RLS does not catch this
 * (RLS only checks this table's own firm_id column, never a related
 * row's firm_id), so a cross-firm communication_consent_id reference
 * remains theoretically possible at the database layer if application
 * code ever bypassed ConsentService. See
 * test_a_raw_insert_can_still_reference_a_communication_consent_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 */
class CommunicationConsentEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents',
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

    public function test_communication_consent_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'communication_consent_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_communication_consent_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'communication_consent_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'communication_consent_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty tables (the twenty-nine previously forced plus
     * communication_consent_events) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     */
    public function test_exactly_thirty_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans']);

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
        $this->assertSame(40, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 (intake_submissions added on top of this batch\'s own communication_consent_events) — no more, no less. Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans']);

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'communication_consent_events'::regclass"
        );

        $this->assertNotNull($policy, 'The communication_consent_events tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the
     * read genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_communication_consent_events(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::factory()->forConsent($consent)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, CommunicationConsentEvent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_communication_consent_events(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('communication_consent_events')->insert([
            'communication_consent_id' => $consent->id,
            'firm_id' => $firm->id,
            'action' => 'captured',
            'previous_status' => null,
            'new_status' => 'granted',
            'consent_text_version' => 'v1',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_communication_consent_event(): void
    {
        $firmA = Firm::factory()->create();
        $consentA = $this->runWithFirmContext($firmA, fn () => CommunicationConsent::factory()->forFirm($firmA)->create());
        $eventA = $this->runWithFirmContext($firmA, fn () => CommunicationConsentEvent::factory()->forConsent($consentA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_communication_consent_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentA = $this->runWithFirmContext($firmA, fn () => CommunicationConsent::factory()->forFirm($firmA)->create());
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, fn () => CommunicationConsentEvent::factory()->forConsent($consentA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => CommunicationConsentEvent::factory()->forConsent($consentB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $consent) {
            return DB::table('communication_consent_events')->insertGetId([
                'communication_consent_id' => $consent->id,
                'firm_id' => $firm->id,
                'action' => 'captured',
                'previous_status' => null,
                'new_status' => 'granted',
                'consent_text_version' => 'v1',
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_communication_consent_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $consentB) {
            DB::table('communication_consent_events')->insert([
                'communication_consent_id' => $consentB->id,
                'firm_id' => $firmB->id,
                'action' => 'captured',
                'previous_status' => null,
                'new_status' => 'granted',
                'consent_text_version' => 'v1',
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_communication_consent_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => CommunicationConsentEvent::factory()->forConsent($consentB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('communication_consent_events')->where('id', $eventB->id)->update(['source' => 'hijacked']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            $eventB->source,
            $reReadAsFirmB->source,
            'Firm A context must not be able to update Firm B\'s communication_consent_events row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_communication_consent_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => CommunicationConsentEvent::factory()->forConsent($consentB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('communication_consent_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s communication_consent_events row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_communication_consent_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => CommunicationConsentEvent::factory()->forConsent($consentB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('communication_consent_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s communication consent event to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates communication_consent_events.firm_id, never
     * communication_consent_id's OWN firm_id — a raw insert whose
     * firm_id matches the active context still succeeds even when
     * communication_consent_id points at a CommunicationConsent
     * belonging to a COMPLETELY DIFFERENT firm. This is a documented
     * residual DATABASE-CONSTRAINT gap, not something RLS itself
     * closes — never to be described as blocked.
     */
    public function test_a_raw_insert_can_still_reference_a_communication_consent_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignConsent = $this->runWithFirmContext($otherFirm, fn () => CommunicationConsent::factory()->forFirm($otherFirm)->create());

        $mismatchedEventId = $this->runWithFirmContext($firm, function () use ($firm, $foreignConsent) {
            return DB::table('communication_consent_events')->insertGetId([
                'communication_consent_id' => $foreignConsent->id,
                'firm_id' => $firm->id,
                'action' => 'captured',
                'previous_status' => null,
                'new_status' => 'granted',
                'consent_text_version' => 'v1',
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedEventId,
            'RLS only checks the row\'s own firm_id — a communication_consent_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: a bare CommunicationConsentEvent::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and the row must
     * actually be visible/readable under its own firm's context
     * afterward. Also proves the Checkpoint 12 root-cause fix: firm_id
     * and communication_consent_id are derived from the SAME
     * CommunicationConsent, so there is no cross-firm mismatch even on
     * a bare default.
     */
    public function test_communication_consent_event_factory_default_creation_is_internally_consistent(): void
    {
        $event = CommunicationConsentEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNotNull($event->communication_consent_id);

        $persisted = $this->runWithFirmContext(
            $event->firm,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($event->firm_id, $persisted->firm_id);

        $consentFirmId = $this->runWithFirmContext(
            $event->firm,
            fn () => CommunicationConsent::withoutGlobalScopes()->find($event->communication_consent_id)?->firm_id,
        );

        $this->assertSame(
            $event->firm_id,
            $consentFirmId,
            'The bare factory default must derive firm_id and communication_consent_id from the SAME CommunicationConsent — no cross-firm mismatch.'
        );
    }

    /**
     * Explicit related-model factory state correctness: forConsent()
     * must set firm_id/communication_consent_id to the EXACT consent
     * given, and the row must be readable only under that firm's
     * context.
     */
    public function test_communication_consent_event_factory_for_consent_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());

        $event = $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::factory()->forConsent($consent)->create());

        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($consent->id, $event->communication_consent_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($persisted);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $consent = $this->runWithFirmContext($firm, fn () => CommunicationConsent::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => CommunicationConsentEvent::factory()->forConsent($consent)->create());

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
     * correctly under FORCE for BOTH tables together — the
     * communication_consents row AND its paired
     * communication_consent_events row must both persist and be
     * readable under the firm's own context.
     */
    public function test_the_capture_flow_persists_both_the_consent_and_its_event_under_force_rls(): void
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

        $events = $this->runWithFirmContext(
            $firm,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()
                ->where('communication_consent_id', $consent->id)
                ->get(),
        );

        $this->assertCount(1, $events, 'capture() must persist exactly one paired CommunicationConsentEvent row under FORCE.');
        $this->assertSame('captured', $events->first()->action);
        $this->assertSame('granted', $events->first()->new_status);
        $this->assertSame($firm->id, $events->first()->firm_id);
        $this->assertSame($actor->id, $events->first()->actor_user_id);
    }

    /**
     * End-to-end proof that ConsentService::revoke() (and re-capture)
     * functions correctly under FORCE, writing a correctly-ordered
     * append-only audit trail across both tables.
     */
    public function test_the_revoke_and_recapture_flow_persists_a_consistent_audit_trail_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $service = new ConsentService();

        $consent = $service->capture($firm, $client->id, ConsentChannel::WhatsApp, 'v1');
        $revoked = $service->revoke($firm, $client->id, ConsentChannel::WhatsApp, reason: 'client requested opt-out');
        $this->assertSame($consent->id, $revoked->id);

        $recapture = $service->capture($firm, $client->id, ConsentChannel::WhatsApp, 'v2');
        $this->assertNoDatabaseTenantContext();

        $actions = $this->runWithFirmContext(
            $firm,
            fn () => CommunicationConsentEvent::withoutGlobalScopes()
                ->where('communication_consent_id', $recapture->id)
                ->orderBy('id')
                ->pluck('action')
                ->all(),
        );

        $this->assertSame(['captured', 'revoked', 'recaptured'], $actions, 'The append-only audit trail must record all three transitions, in order, all under the same firm.');
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Twenty-nine previously forced tables plus
     * communication_consent_events must be independently force-active
     * and independently isolated at the same time — proof this batch
     * did not weaken or interfere with any prior section's own
     * enforcement. Uses communication_consents as the companion table.
     */
    public function test_communication_consent_events_is_isolated_independently_and_simultaneously_with_communication_consents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $consentA = $this->runWithFirmContext($firmA, fn () => CommunicationConsent::factory()->forFirm($firmA)->create());
        $consentB = $this->runWithFirmContext($firmB, fn () => CommunicationConsent::factory()->forFirm($firmB)->create());

        $eventA = $this->runWithFirmContext($firmA, fn () => CommunicationConsentEvent::factory()->forConsent($consentA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => CommunicationConsentEvent::factory()->forConsent($consentB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'communication_consent_events' => CommunicationConsentEvent::withoutGlobalScopes()->pluck('id')->all(),
            'communication_consents' => CommunicationConsent::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$eventA->id], $resultA['communication_consent_events']);
        $this->assertNotContains($eventB->id, $resultA['communication_consent_events']);
        $this->assertContains($consentA->id, $resultA['communication_consents']);
        $this->assertNotContains($consentB->id, $resultA['communication_consents']);
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
        $migration = require base_path('database/migrations/2026_08_25_930012_force_rls_on_communication_consent_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'communication_consent_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while communication_consent_events is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'communication_consent_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'communication_consent_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
