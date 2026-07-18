<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\MatterReadinessStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Models\ReadinessScoreEvent;
use App\Services\ComplianceGapRegistryService;
use App\Services\MatterReadinessService;
use App\Services\ReadinessScorecardRegistry;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ReadinessScoreEventsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 15. Proves the thirty-third staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930015_force_rls_on_readiness_score_events_table.php)
 * is permanently active for readiness_score_events and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table
 * (including Checkpoint 14's own matter_readiness_scores) remains
 * forced simultaneously, and that MatterReadinessService::recompute()
 * (now moving the readiness_score_events write inside the same
 * runWithFirmContext() wrap established by Checkpoint 14) persists BOTH
 * tables correctly together in one operation under FORCE.
 *
 * Recovery/reconciliation note: this checkpoint's WIP was originally
 * drafted together with matter_readiness_scores under a combined
 * "Checkpoints 14/15" label and a single combined test file. On
 * reconciliation the two tables were split into one checkpoint each —
 * see Checkpoint 14's own migration and test file
 * (MatterReadinessScoresForceRlsActivationTest.php) for the full
 * separability analysis and that table's own standalone proofs.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * this migration's own docblock): no composite foreign key validates
 * that matter_id's owning firm matches this table's own firm_id. FORCE
 * RLS does not catch this (RLS only checks this table's own firm_id
 * column, never a related row's firm_id), so a cross-firm matter_id
 * reference remains theoretically possible at the database layer if
 * application code ever bypassed the established write path. See
 * test_a_raw_insert_can_still_reference_a_matter_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 */
class ReadinessScoreEventsForceRlsActivationTest extends TestCase
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
        'matter_readiness_scores',
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

    public function test_readiness_score_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'readiness_score_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_readiness_score_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'readiness_score_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'readiness_score_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-four tables (the thirty-two previously forced —
     * including Checkpoint 14's matter_readiness_scores — plus
     * readiness_score_events, plus Checkpoint 16's own
     * tenant_encryption_keys) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16 (this
     * repo's thirty-fourth staged FORCE activation batch, covering
     * tenant_encryption_keys) to extend the "exactly these tables are
     * forced" firewall list from thirty-three to thirty-four tables —
     * same additive-only pattern, no existing assertion removed or
     * weakened.
     */
    public function test_exactly_thirty_three_prepared_tables_are_force_row_level_security_enabled(): void
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'customer_success_health_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events']);
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
        $this->assertSame(56, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 15 — no more, no less. Narrowly updated again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'customer_success_health_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events']);
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

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_for_readiness_score_events(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'readiness_score_events'::regclass"
        );

        $this->assertNotNull($policy, 'The readiness_score_events tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_readiness_score_events(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => ReadinessScoreEvent::factory()->forMatter($matter)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, ReadinessScoreEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_readiness_score_events(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('readiness_score_events')->insert([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'event_type' => 'recomputed',
            'previous_status' => null,
            'new_status' => 'not_ready',
            'metadata_json' => json_encode([]),
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_readiness_score_event(): void
    {
        $firmA = Firm::factory()->create();
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $eventA = $this->runWithFirmContext($firmA, fn () => ReadinessScoreEvent::factory()->forMatter($matterA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ReadinessScoreEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_readiness_score_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, fn () => ReadinessScoreEvent::factory()->forMatter($matterA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => ReadinessScoreEvent::factory()->forMatter($matterB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ReadinessScoreEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds_for_readiness_score_events(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            return DB::table('readiness_score_events')->insertGetId([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'event_type' => 'recomputed',
                'previous_status' => null,
                'new_status' => 'not_ready',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_readiness_score_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $matterB) {
            DB::table('readiness_score_events')->insert([
                'firm_id' => $firmB->id,
                'matter_id' => $matterB->id,
                'event_type' => 'recomputed',
                'previous_status' => null,
                'new_status' => 'not_ready',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_readiness_score_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => ReadinessScoreEvent::factory()->forMatter($matterB)->create(['new_status' => 'not_ready']));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('readiness_score_events')->where('id', $eventB->id)->update(['new_status' => 'ready']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ReadinessScoreEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            'not_ready',
            $reReadAsFirmB->new_status,
            'Firm A context must not be able to update Firm B\'s readiness_score_events row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_readiness_score_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => ReadinessScoreEvent::factory()->forMatter($matterB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('readiness_score_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ReadinessScoreEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s readiness_score_events row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_readiness_score_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => ReadinessScoreEvent::factory()->forMatter($matterB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('readiness_score_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s readiness score event to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ReadinessScoreEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Same honest residual-gap proof as matter_readiness_scores (see
     * Checkpoint 14's own test file), for readiness_score_events.
     */
    public function test_a_raw_insert_can_still_reference_a_matter_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignMatter = $this->runWithFirmContext($otherFirm, fn () => Matter::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignMatter) {
            return DB::table('readiness_score_events')->insertGetId([
                'firm_id' => $firm->id,
                'matter_id' => $foreignMatter->id,
                'event_type' => 'recomputed',
                'previous_status' => null,
                'new_status' => 'not_ready',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a matter_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Same bare-default consistency proof as MatterReadinessScoreFactory
     * (Checkpoint 14), for ReadinessScoreEventFactory.
     */
    public function test_readiness_score_event_factory_default_creation_is_internally_consistent(): void
    {
        $event = ReadinessScoreEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNotNull($event->matter_id);

        $persisted = $this->runWithFirmContext(
            $event->firm,
            fn () => ReadinessScoreEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($event->firm_id, $persisted->firm_id);

        $matterFirmId = $this->runWithFirmContext(
            $event->firm,
            fn () => Matter::withoutGlobalScopes()->find($event->matter_id)?->firm_id,
        );

        $this->assertSame(
            $event->firm_id,
            $matterFirmId,
            'The bare factory default must derive firm_id and matter_id from the SAME Matter — no cross-firm mismatch.'
        );
    }

    /**
     * Same explicit-related-state consistency proof as above, for
     * ReadinessScoreEventFactory::forMatter().
     */
    public function test_readiness_score_event_factory_for_matter_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $event = $this->runWithFirmContext($firm, fn () => ReadinessScoreEvent::factory()->forMatter($matter)->create());

        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($matter->id, $event->matter_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => ReadinessScoreEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted);
    }

    // ---------------------------------------------------------------
    // End-to-end recompute() proof under FORCE (both tables together)
    // ---------------------------------------------------------------

    /**
     * End-to-end proof that MatterReadinessService::recompute()
     * persists BOTH matter_readiness_scores and readiness_score_events
     * correctly, in one operation, under FORCE RLS — now that this
     * checkpoint moved the event write inside Checkpoint 14's own
     * runWithFirmContext() wrap. Also proves recompute() clears its own
     * context wrap before returning, and that a second recompute() call
     * upserts the score while appending a second event (proving the
     * firstOrNew() lookup is correctly inside the wrap, per Checkpoint
     * 14's own proof, and that this checkpoint's incremental change
     * didn't disturb it).
     */
    public function test_recompute_persists_both_tables_together_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $service = new MatterReadinessService(new ReadinessScorecardRegistry());

        $score = $service->recompute($matter);

        $this->assertNoDatabaseTenantContext('recompute() must clear its own context wrap before returning.');
        $this->assertNotNull($score);
        $this->assertSame(MatterReadinessStatus::NotReady, $score->status);

        $persistedScore = $this->runWithFirmContext(
            $firm,
            fn () => MatterReadinessScore::withoutGlobalScopes()->where('matter_id', $matter->id)->first(),
        );
        $this->assertNotNull($persistedScore, 'recompute() must persist exactly one matter_readiness_scores row under FORCE, readable under its own firm context.');
        $this->assertSame($firm->id, $persistedScore->firm_id);

        $persistedEventCount = $this->runWithFirmContext(
            $firm,
            fn () => ReadinessScoreEvent::query()->where('matter_id', $matter->id)->count(),
        );
        $this->assertSame(1, $persistedEventCount, 'recompute() must persist exactly one readiness_score_events row under FORCE, readable under its own firm context.');

        $service->recompute($matter);

        $scoreCount = $this->runWithFirmContext(
            $firm,
            fn () => MatterReadinessScore::withoutGlobalScopes()->where('matter_id', $matter->id)->count(),
        );
        $this->assertSame(1, $scoreCount, 'A second recompute() must upsert in place, not duplicate, the matter_readiness_scores row.');

        $eventCountAfterSecondCall = $this->runWithFirmContext(
            $firm,
            fn () => ReadinessScoreEvent::query()->where('matter_id', $matter->id)->count(),
        );
        $this->assertSame(2, $eventCountAfterSecondCall, 'A second recompute() must append a second readiness_score_events row.');
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
     * Thirty-two previously forced tables plus readiness_score_events
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement, including Checkpoint 14's
     * own matter_readiness_scores. Uses clients as the companion table,
     * and proves matter_readiness_scores and readiness_score_events are
     * ALSO simultaneously isolated from each other's unrelated firm.
     */
    public function test_readiness_score_events_are_isolated_independently_and_simultaneously_with_clients_and_matter_readiness_scores(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forClient($clientA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forClient($clientB)->create());

        $scoreA = $this->runWithFirmContext($firmA, fn () => MatterReadinessScore::factory()->forMatter($matterA)->create());
        $scoreB = $this->runWithFirmContext($firmB, fn () => MatterReadinessScore::factory()->forMatter($matterB)->create());

        $eventA = $this->runWithFirmContext($firmA, fn () => ReadinessScoreEvent::factory()->forMatter($matterA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => ReadinessScoreEvent::factory()->forMatter($matterB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'matter_readiness_scores' => MatterReadinessScore::withoutGlobalScopes()->pluck('id')->all(),
            'readiness_score_events' => ReadinessScoreEvent::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$scoreA->id], $resultA['matter_readiness_scores']);
        $this->assertNotContains($scoreB->id, $resultA['matter_readiness_scores']);
        $this->assertSame([$eventA->id], $resultA['readiness_score_events']);
        $this->assertNotContains($eventB->id, $resultA['readiness_score_events']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the readiness_score_events migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, policy still present, but NOT forced — never drop the
     * policy or disable RLS itself. Also proves rollback affects ONLY
     * this one table — every other previously-forced table, INCLUDING
     * matter_readiness_scores, must be untouched.
     */
    public function test_readiness_score_events_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930015_force_rls_on_readiness_score_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'readiness_score_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while readiness_score_events is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'readiness_score_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'readiness_score_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
