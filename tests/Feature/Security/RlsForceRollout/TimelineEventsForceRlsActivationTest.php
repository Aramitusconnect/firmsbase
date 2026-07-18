<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\KeyDestructionRequestStatus;
use App\Enums\LegalHoldScope;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PilotFeedbackItem;
use App\Models\RetentionPolicy;
use App\Models\TimeEntry;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\ComplianceGapRegistryService;
use App\Services\ConflictCheckService;
use App\Services\ConsentService;
use App\Services\EmployeeRateService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\InvoiceDraftingService;
use App\Services\KeyDestructionApprovalService;
use App\Services\KeyDestructionExecutionService;
use App\Services\KeyDestructionRequestService;
use App\Services\OffboardingExportService;
use App\Services\OffboardingRequestService;
use App\Services\PaymentPlanDunningService;
use App\Services\PaymentPlanInstallmentService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimelineEventRecorder;
use App\Services\WebhookReplayService;
use App\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TimelineEventsForceRlsActivationTest — Section 39A-3L, Checkpoint 33
 * (Phase B6). Proves the fifty-first staged FORCE ROW LEVEL SECURITY
 * activation batch (database/migrations/2026_08_25_930033_force_rls_on_
 * timeline_events_table.php) is permanently active for timeline_events
 * and behaves correctly.
 *
 * This table is fundamentally different from every prior nullable-
 * firm_id checkpoint in this arc (see the full design dossier,
 * rls-checkpoints/39a3l/B6-timeline_events-design-dossier.md, read in
 * full before writing this file): firm_id is nullable purely as an
 * orphaned-audit-trail artifact of Firm's ON DELETE SET NULL foreign
 * key, never a genuine platform-wide row — no application code ever
 * legitimately writes firm_id = NULL, and the single existing policy
 * (firm_id = current_firm, no IS NULL branch, doubling as WITH CHECK)
 * was ALREADY exactly correct before this checkpoint. This migration
 * therefore issues ONLY `ALTER TABLE timeline_events FORCE ROW LEVEL
 * SECURITY` — no DROP POLICY/CREATE POLICY at all, unlike every one of
 * the six prior nullable-firm_id checkpoints in this category. This
 * file's own policy-shape proofs assert the ORIGINAL policy text is
 * completely untouched, not merely that "a" policy exists.
 *
 * Central novel proof (unique to this table in this category): a
 * simulated orphaned row (firm_id forced to NULL, standing in for what
 * a real Firm ON DELETE SET NULL cascade would produce — there is no
 * live way to delete a Firm in this codebase, so this file simulates
 * the cascade's END STATE directly using this migration's own
 * published down()/up() toggle for exactly one row, immediately
 * restoring FORCE before any visibility assertion runs; NOT a
 * BYPASSRLS/global RLS bypass) is proven invisible under EVERY
 * context this file can construct, including a context that would see
 * a genuine platform-wide row on a different, six-prior-precedent
 * table (pilot_feedback_items) in the very same test. This is this
 * table's own deliberate fail-closed design, proven directly, not
 * assumed.
 *
 * This file also exercises three of the eight fixed call sites
 * (ConflictCheckService::run(), InvoiceDraftingService::createFlatFee(),
 * KeyDestructionExecutionService::execute() — the security-relevant
 * one) end-to-end through their REAL service call, plus the remaining
 * five (InvoiceDraftingService::draftFromTimeEntries(),
 * PaymentPlanDunningService::checkAndLog(),
 * PaymentPlanInstallmentService::markMissed()/markWaived(),
 * WebhookReplayService::replay()) for completeness, matching the
 * dossier's own "8 call sites across 6 services" scope exactly.
 */
class TimelineEventsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_25_930033_force_rls_on_timeline_events_table.php';

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
        'pilot_feedback_items',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    private function recorder(): TimelineEventRecorder
    {
        return new TimelineEventRecorder();
    }

    private function insertRow(?int $firmId, string $eventType): int
    {
        return DB::table('timeline_events')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firmId,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'metadata_json' => json_encode([]),
            'created_at' => now(),
        ]);
    }

    /**
     * Simulates what a real Firm ON DELETE SET NULL cascade would leave
     * behind — an orphaned timeline_events row with firm_id genuinely
     * NULL — WITHOUT ever deleting a Firm (there is no live way to do
     * so in this codebase) and WITHOUT any blanket/global RLS bypass.
     *
     * Mechanism, precisely: the migration's own down() disables FORCE
     * for timeline_events ONLY; the mission's own rls_test_runner_39a3l
     * role is this table's OWNER (confirmed non-superuser,
     * rolbypassrls=false — matching the dossier's own empirical setup);
     * PostgreSQL only exempts a table's OWNER from its row-security
     * policies when the table is NOT forced. This uses that exemption
     * for exactly one UPDATE on exactly one already-legitimately-created
     * row, then immediately restores FORCE via up() in a finally block
     * before returning — no assertion in this file ever runs while
     * FORCE is off, and no other table's FORCE state is touched.
     */
    private function insertOrphanedRow(Firm $firm, string $eventType): int
    {
        $id = $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, $eventType));

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();

        try {
            $affected = DB::table('timeline_events')->where('id', $id)->update(['firm_id' => null]);
            if ($affected !== 1) {
                throw new \RuntimeException('Failed to simulate the FK-cascade orphaned row for this test\'s own setup.');
            }
        } finally {
            $migration->up();
        }

        return $id;
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

    public function test_timeline_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'timeline_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_timeline_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'timeline_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'timeline_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly fifty-one tables (the fifty previously forced plus
     * timeline_events) must be FORCE-enabled among ALL prepared tables
     * — no more, no less.
     */
    public function test_exactly_fifty_one_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'customer_success_health_scores', 'timeline_events', 'security_events']);

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

        $this->assertSame(60, count($actuallyForced), 'Exactly fifty-one prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 33 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'customer_success_health_scores', 'timeline_events', 'security_events']);

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

    /**
     * Unlike every one of the six prior nullable-firm_id checkpoints in
     * this category, THIS migration issues no DROP POLICY/CREATE POLICY
     * at all — the original, already-live policy is exactly correct and
     * was never touched. Proves the ORIGINAL policy text (byte-for-byte
     * shape: current_setting-based, firm_id-matching, no IS NULL branch,
     * no separate WITH CHECK) is still exactly what it always was.
     */
    public function test_the_original_single_policy_is_completely_unchanged(): void
    {
        $policy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'timeline_events'::regclass"
        );

        $this->assertNotNull($policy, 'The original timeline_events_tenant_isolation policy must still exist — this checkpoint never drops it.');
        $this->assertSame('timeline_events_tenant_isolation', $policy->polname);
        $this->assertSame('*', $policy->polcmd, 'The original policy is a FOR ALL policy (polcmd = *), never split into FOR SELECT/FOR ALL like the six prior tables.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
        $this->assertStringNotContainsString('IS NULL', $policy->using_expr, 'timeline_events deliberately has NO IS NULL branch, unlike every one of the six prior nullable-firm_id checkpoints.');
        $this->assertNull($policy->with_check_expr, 'The original policy never had a separate WITH CHECK clause — USING doubles as WITH CHECK by omission, exactly as before this checkpoint.');
    }

    /**
     * Exactly one policy exists on this table — this checkpoint never
     * added a second policy (no FOR SELECT/FOR ALL split, unlike the
     * six prior tables).
     */
    public function test_exactly_one_policy_exists_on_timeline_events(): void
    {
        $count = DB::selectOne("select count(*) as c from pg_policy where polrelid = 'timeline_events'::regclass")->c;

        $this->assertSame(1, (int) $count, 'timeline_events must carry exactly one policy — this checkpoint never added a second.');
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and pilot_feedback_items' own two-policy shape (the
     * immediately prior checkpoint) as representative unrelated
     * policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $pilotFeedbackReadPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_read'");
        $pilotFeedbackWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_write'");
        $this->assertNotNull($pilotFeedbackReadPolicy);
        $this->assertNotNull($pilotFeedbackWritePolicy);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific-no-context-read'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, TimelineEvent::query()->where('firm_id', $firm->id)->count());
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

    public function test_firm_a_context_can_read_its_own_timeline_event(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TimelineEvent::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_timeline_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TimelineEvent::query()->pluck('id')->all(),
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

    public function test_firm_a_context_cannot_update_firm_b_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'update-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($rowB) {
            return DB::table('timeline_events')->where('id', $rowB)->update(['event_type' => 'hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s timeline_events row.');

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TimelineEvent::query()->find($rowB));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('update-target', $reReadAsFirmB->event_type);
    }

    public function test_firm_a_context_cannot_reassign_firm_b_row_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'reassign-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $rowB) {
            return DB::table('timeline_events')->where('id', $rowB)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s timeline_events row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TimelineEvent::query()->find($rowB));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target'));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('timeline_events')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => TimelineEvent::query()->find($rowB));
        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s timeline_events row.');
    }

    /**
     * Direct SQL-level proof a firm-scoped session cannot write into a
     * sibling firm's firm_id via UPDATE — the target row IS visible
     * under USING (firm A owns it), but WITH CHECK rejects the
     * resulting new row (firm_id = firm B) outright, raising a hard
     * row-level-security QueryException rather than returning 0.
     */
    public function test_a_firm_scoped_session_cannot_update_its_own_row_to_claim_sibling_firm_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'reassign-to-sibling'));

        try {
            $this->runWithFirmContext($firmA, function () use ($firmB, $rowA) {
                return DB::table('timeline_events')->where('id', $rowA)->update(['firm_id' => $firmB->id]);
            });
            $this->fail('Expected a row-level security policy violation when Firm A tries to reassign its own row to Firm B.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security policy', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext();

        $stillFirmAs = $this->runWithFirmContext($firmA, fn () => TimelineEvent::query()->find($rowA));
        $this->assertNotNull($stillFirmAs);
        $this->assertSame($firmA->id, $stillFirmAs->firm_id);
    }

    // ---------------------------------------------------------------
    // Central novel proof — this table's own deliberate fail-closed
    // design (no IS NULL branch), unlike all six prior nullable-
    // firm_id checkpoints. An orphaned row (firm_id genuinely NULL,
    // simulating what a real Firm ON DELETE SET NULL cascade would
    // leave behind) must be invisible under EVERY context.
    // ---------------------------------------------------------------

    public function test_an_orphaned_null_firm_id_row_is_invisible_under_a_firm_scoped_context(): void
    {
        $deletedFirmStandIn = Firm::factory()->create();
        $orphanId = $this->insertOrphanedRow($deletedFirmStandIn, 'orphaned-audit-trail-entry');

        $someOtherFirm = Firm::factory()->create();

        $visible = $this->runWithFirmContext($someOtherFirm, fn () => TimelineEvent::query()->find($orphanId));
        $this->assertNull($visible, 'An orphaned firm_id = NULL row must be invisible to a sibling firm\'s own context.');

        // Also invisible to a session scoped to what WAS this row's own
        // firm — its firm_id is genuinely NULL now, not merely hidden,
        // so even the "original" firm's own context does not match it.
        $visibleToOriginal = $this->runWithFirmContext($deletedFirmStandIn, fn () => TimelineEvent::query()->find($orphanId));
        $this->assertNull($visibleToOriginal, 'An orphaned row must be invisible even under the context of the firm it used to belong to — its firm_id column is genuinely NULL, not merely reassigned.');
    }

    public function test_an_orphaned_null_firm_id_row_is_invisible_under_no_context_at_all(): void
    {
        $deletedFirmStandIn = Firm::factory()->create();
        $orphanId = $this->insertOrphanedRow($deletedFirmStandIn, 'orphaned-no-context-check');

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $visible = TimelineEvent::query()->find($orphanId);
        $this->assertNull($visible, 'An orphaned row must be invisible even under NO ambient context — this table\'s policy has no IS NULL branch to ever match it.');
    }

    public function test_an_orphaned_null_firm_id_row_remains_invisible_across_several_different_firm_contexts(): void
    {
        $deletedFirmStandIn = Firm::factory()->create();
        $orphanId = $this->insertOrphanedRow($deletedFirmStandIn, 'orphaned-multi-firm-check');

        foreach (range(1, 3) as $i) {
            $firm = Firm::factory()->create();
            $visible = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->find($orphanId));
            $this->assertNull($visible, "An orphaned row must be invisible to firm #{$i}'s own context too.");
        }
    }

    /**
     * The deliberate contrast this table's own design creates: in the
     * SAME test, under the SAME firm-scoped session, a genuine
     * platform-wide row on pilot_feedback_items (the immediately prior
     * checkpoint, which DOES have an IS NULL read branch) IS visible,
     * while an orphaned timeline_events row is NOT — proving this
     * table's fail-closed design is a deliberate, narrower choice, not
     * an accident of "no context ever sees NULL rows anywhere."
     */
    public function test_an_orphaned_row_stays_invisible_even_to_a_session_that_would_see_another_tables_platform_wide_row(): void
    {
        $deletedFirmStandIn = Firm::factory()->create();
        $orphanTimelineEventId = $this->insertOrphanedRow($deletedFirmStandIn, 'orphaned-vs-platform-wide-contrast');

        $platformWidePilotFeedbackItem = PilotFeedbackItem::factory()->internal()->create();
        $this->assertNull($platformWidePilotFeedbackItem->firm_id);

        $someFirm = Firm::factory()->create();

        $result = $this->runWithFirmContext($someFirm, fn () => [
            'timeline_events' => TimelineEvent::query()->find($orphanTimelineEventId),
            'pilot_feedback_items' => PilotFeedbackItem::query()->find($platformWidePilotFeedbackItem->id),
        ]);

        $this->assertNull($result['timeline_events'], 'timeline_events\' orphaned NULL-firm_id row must remain invisible — this table\'s policy has no IS NULL branch.');
        $this->assertNotNull($result['pilot_feedback_items'], 'pilot_feedback_items\' genuine platform-wide NULL-firm_id row IS visible under the very same session — proving the timeline_events invisibility above is this table\'s own deliberate design, not a platform-wide inability to ever see any NULL-firm_id row.');
    }

    /**
     * Direct SQL-level proof a firm-scoped session cannot forge a
     * firm_id = NULL row via INSERT — unlike the six prior tables
     * (which all have a null-permitting WITH CHECK branch for a
     * legitimate context-free writer), timeline_events' policy has NO
     * null-permitting branch at all, so this fails from EVERY context,
     * not merely a firm-scoped one.
     */
    public function test_a_firm_scoped_session_cannot_insert_a_null_firm_id_row(): void
    {
        $firm = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => $this->insertRow(null, 'forged-null-firm-id'));
    }

    /**
     * Unlike every one of the six prior nullable-firm_id tables (where
     * a genuinely context-free session CAN legitimately insert a null-
     * firm_id platform-wide row), a context-free session CANNOT insert
     * a null-firm_id row here either — timeline_events' single-clause
     * policy has no null-permitting branch at all, so
     * `firm_id = NULLIF(current_setting(...), '')::bigint` evaluates
     * NULL = NULL (not TRUE) even with no ambient context, and the
     * INSERT is rejected exactly the same as the missing-context case
     * above.
     */
    public function test_a_context_free_session_also_cannot_insert_a_null_firm_id_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->insertRow(null, 'context-free-forged-null');
    }

    // ---------------------------------------------------------------
    // 8 fixed call sites — real end-to-end service proofs, exercising
    // TimelineEventRecorder::record() through the REAL production
    // service call, not a raw record() invocation. At least
    // ConflictCheckService::run(), InvoiceDraftingService::
    // createFlatFee(), and KeyDestructionExecutionService::execute()
    // (the security-relevant one) are required; all 8 are exercised
    // here for completeness.
    // ---------------------------------------------------------------

    public function test_conflict_check_service_run_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $matter = Matter::factory()->create();
        Client::factory()->forFirm($matter->firm)->create(['display_name' => 'Jane Conflict']);
        $actor = User::factory()->create();

        // Matter::factory()/Client::factory() (bare, above) each leave
        // DB-session tenant context set (the established context-hold
        // factory pattern) — establish a genuinely clean baseline
        // immediately before the call under test.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new ConflictCheckService($this->recorder());
        $summary = $service->run($matter, ['Jane Conflict'], [], $actor);

        $this->assertTrue($summary->resultCount >= 1);
        $this->assertNoDatabaseTenantContext('ConflictCheckService::run() must clear its own context wraps before returning.');

        $event = $this->runWithFirmContext(
            $matter->firm,
            fn () => TimelineEvent::query()->where('firm_id', $matter->firm_id)->where('event_type', 'conflict_check_completed')->first(),
        );

        $this->assertNotNull($event, 'ConflictCheckService::run() must genuinely persist its conflict_check_completed timeline event under FORCE, not throw.');
        $this->assertSame(Matter::class, $event->subject_type);
        $this->assertSame($matter->id, $event->subject_id);
    }

    public function test_invoice_drafting_service_create_flat_fee_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $createdBy = User::factory()->create();

        // Firm::factory()/Client::factory() (bare, above) each leave
        // DB-session tenant context set to $firm->id (the established
        // context-hold factory pattern) — establish a genuinely clean
        // baseline immediately before the call under test.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), $this->recorder());
        $invoice = $service->createFlatFee($firm, $client, 'Flat fee for initial consultation', 50000, null, $createdBy);

        $this->assertNotNull($invoice->id);
        $this->assertNoDatabaseTenantContext('InvoiceDraftingService::createFlatFee() must clear its own context wraps before returning.');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->where('event_type', 'invoice_drafted')->first(),
        );

        $this->assertNotNull($event, 'InvoiceDraftingService::createFlatFee() must genuinely persist its invoice_drafted timeline event under FORCE, not throw.');
        $this->assertSame($invoice->id, $event->subject_id);
    }

    public function test_invoice_drafting_service_draft_from_time_entries_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $entry = TimeEntry::factory()->forFirm($firm)->create([
            'client_id' => $client->id,
            'status' => \App\Enums\TimeEntryStatus::Approved,
            'is_billable' => true,
            'seconds' => 3600,
            'billing_rate_cents_snapshot' => 20000,
        ]);

        // The bare factory calls above each leave DB-session tenant
        // context set to $firm->id (the established context-hold
        // factory pattern) — establish a genuinely clean baseline
        // immediately before the call under test.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), $this->recorder());
        $invoice = $service->draftFromTimeEntries($firm, $client, [$entry]);

        $this->assertNotNull($invoice->id);
        $this->assertNoDatabaseTenantContext('InvoiceDraftingService::draftFromTimeEntries() must clear its own context wraps before returning.');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->where('event_type', 'invoice_drafted')->first(),
        );

        $this->assertNotNull($event, 'InvoiceDraftingService::draftFromTimeEntries() must genuinely persist its invoice_drafted timeline event under FORCE, not throw.');
    }

    /**
     * The security-relevant fix. Directly checks BOTH
     * key_destruction_requests.status AND the corresponding
     * timeline_events row exist together after a successful execute()
     * call — proving the audit trail is now atomic with the status
     * update, closing the gap the dossier documents (destruction
     * succeeds, status silently updates, but the audit entry for a
     * security-critical irreversible action is never written).
     */
    public function test_key_destruction_execution_service_execute_is_atomic_with_its_audit_trail_under_force(): void
    {
        $firm = Firm::factory()->create();
        app(EncryptionKeyService::class)->provision($firm);
        $admin1 = \App\Models\PlatformAdmin::factory()->create();
        $admin2 = \App\Models\PlatformAdmin::factory()->create();

        RetentionPolicy::factory()->create([
            'firm_id' => null,
            'record_type' => RetentionRecordType::Firm,
            'retention_period_days' => 1,
            'status' => RetentionPolicyStatus::Active,
        ]);
        $firm->forceFill(['created_at' => now()->subYears(10)])->save();

        $offboardingRequest = app(OffboardingRequestService::class)->request($firm, $admin1, 'Offboarding.');
        $export = app(OffboardingExportService::class)->generate($offboardingRequest, requestedByPlatformAdmin: $admin1);
        app(OffboardingExportService::class)->verify($export, $admin1);

        $request = app(KeyDestructionRequestService::class)->request($firm, $admin1, 'Destroy key.', $offboardingRequest);
        app(KeyDestructionRequestService::class)->submitForApproval($request);

        $approvalService = app(KeyDestructionApprovalService::class);
        $approval = $approvalService->requestApproval($request, $admin1, 'Two-person approval required.');
        $approvalService->firstApprove($approval, $admin1);
        $approvalService->secondApprove($approval->fresh(), $admin2);

        $this->assertSame(KeyDestructionRequestStatus::Approved, $request->fresh()->status);

        $executed = app(KeyDestructionExecutionService::class)->execute($request->fresh());

        $this->assertSame(KeyDestructionRequestStatus::Executed, $executed->status);
        $this->assertNotNull($executed->executed_at);
        $this->assertNoDatabaseTenantContext('KeyDestructionExecutionService::execute() must clear its own context wrap before returning.');

        // Both must exist TOGETHER — the status update on
        // key_destruction_requests (not itself FORCE-protected) AND the
        // timeline_events audit row (now FORCE-protected), proving
        // neither succeeded while the other silently failed.
        $statusFromDatabase = KeyDestructionRequestStatus::from(
            DB::table('key_destruction_requests')->where('id', $request->id)->value('status')
        );
        $this->assertSame(KeyDestructionRequestStatus::Executed, $statusFromDatabase, 'key_destruction_requests.status must genuinely be Executed in the database.');

        $auditEvent = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->where('event_type', 'key_destruction.executed')->first(),
        );
        $this->assertNotNull($auditEvent, 'The key_destruction.executed timeline_events audit row must exist alongside the Executed status — this is the security-critical fix under FORCE.');
        $this->assertSame($request->id, $auditEvent->metadata_json['key_destruction_request_id']);
    }

    public function test_payment_plan_dunning_service_check_and_log_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        (new ConsentService())->capture($firm, $client->id, ConsentChannel::Email, 'v1');

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forClient($client)->active()->create());
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();

        // PaymentPlanInstallment::factory() (bare, above) leaves
        // DB-session tenant context set to $plan->firm_id (the
        // established context-hold factory pattern) — establish a
        // genuinely clean baseline immediately before the call under
        // test.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentPlanDunningService(new ConsentService(), $this->recorder());
        $result = $this->runWithFirmContext($firm, fn () => $service->checkAndLog($installment));

        $this->assertTrue($result->eligible);
        $this->assertNoDatabaseTenantContext('PaymentPlanDunningService::logAndReturn()\'s own whole-method wrap must clear context before returning.');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->where('event_type', 'dunning_reminder_queued')->first(),
        );
        $this->assertNotNull($event, 'PaymentPlanDunningService::logAndReturn() must genuinely persist its dunning_reminder_queued timeline event under FORCE.');
    }

    public function test_payment_plan_installment_service_mark_missed_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create();

        // PaymentPlanInstallmentService::markMissed() now requires an
        // explicit PaymentPlan tenant anchor (Section 39A-3L
        // installment-lifecycle contract fix) — it no longer lazy-loads
        // $installment->paymentPlan->firm before its own context wrap
        // begins, so it no longer has any hidden precondition on the
        // caller having already established (or a factory having
        // accidentally left behind) ambient tenant context. The bare
        // factory calls above leave DB-session tenant context set to
        // $plan->firm_id (the established context-hold factory
        // pattern) — clear it explicitly so this proof exercises a
        // genuinely context-free baseline, not a coincidentally
        // matching leftover one.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentPlanInstallmentService($this->recorder());
        $missed = $service->markMissed($plan, $installment);

        $this->assertSame(PaymentPlanInstallmentStatus::Missed, $missed->status);
        $this->assertNotNull($missed, 'markMissed()\'s trailing fresh() must return a populated model under FORCE.');
        $this->assertNoDatabaseTenantContext('PaymentPlanInstallmentService::markMissed() must leave no tenant context behind when called from a context-free baseline.');

        $event = $this->runWithFirmContext(
            $plan->firm,
            fn () => TimelineEvent::query()->where('firm_id', $plan->firm_id)->where('event_type', 'payment_plan_installment_missed')->first(),
        );
        $this->assertNotNull($event, 'PaymentPlanInstallmentService::markMissed() must genuinely persist its payment_plan_installment_missed timeline event under FORCE.');
    }

    public function test_payment_plan_installment_service_mark_waived_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create();
        $actor = User::factory()->create();

        // PaymentPlanInstallmentService::markWaived() now requires an
        // explicit PaymentPlan tenant anchor (Section 39A-3L
        // installment-lifecycle contract fix) — see markMissed()'s own
        // proof above for the full rationale. The bare factory calls
        // above leave DB-session tenant context set to $plan->firm_id
        // (the established context-hold factory pattern) — clear it
        // explicitly so this proof exercises a genuinely context-free
        // baseline.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentPlanInstallmentService($this->recorder());
        $waived = $service->markWaived($plan, $installment, $actor, 'Hardship waiver approved');

        $this->assertSame(PaymentPlanInstallmentStatus::Waived, $waived->status);
        $this->assertNoDatabaseTenantContext('PaymentPlanInstallmentService::markWaived() must leave no tenant context behind when called from a context-free baseline.');

        $event = $this->runWithFirmContext(
            $plan->firm,
            fn () => TimelineEvent::query()->where('firm_id', $plan->firm_id)->where('event_type', 'payment_plan_installment_waived')->first(),
        );
        $this->assertNotNull($event, 'PaymentPlanInstallmentService::markWaived() must genuinely persist its payment_plan_installment_waived timeline event under FORCE.');
        $this->assertSame(User::class, $event->actor_type);
        $this->assertSame($actor->id, $event->actor_id);
    }

    public function test_webhook_replay_service_replay_succeeds_under_force_and_records_a_timeline_event(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'webhook', \App\Enums\EntitlementSource::AdminOverride, true);
        app(EncryptionKeyService::class)->provision($firm);
        $owner = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => \App\Enums\FirmUserRole::FirmOwner]);

        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        $webhookEvent = WebhookEvent::factory()->forFirm($firm)->create();
        $originalDelivery = WebhookDelivery::factory()->exhausted()->create([
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'webhook_event_id' => $webhookEvent->id,
        ]);

        // The preceding bare factory calls (FirmUser, WebhookEvent,
        // WebhookDelivery) each leave DB-session tenant context set to
        // $firm->id (the established context-hold factory pattern) —
        // establish a genuinely clean baseline immediately before the
        // call under test.
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = app(WebhookReplayService::class);
        $replay = $service->replay($firm, $originalDelivery, $owner);

        $this->assertSame(WebhookDeliveryStatus::Pending, $replay->status);
        $this->assertNoDatabaseTenantContext('WebhookReplayService::replay() must clear its own narrow context wrap before returning.');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->where('event_type', 'webhook_delivery_replayed')->first(),
        );
        $this->assertNotNull($event, 'WebhookReplayService::replay() must genuinely persist its webhook_delivery_replayed timeline event under FORCE.');
        $this->assertSame($replay->id, $event->metadata_json['new_webhook_delivery_id']);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_firm_scoped_and_immediately_readable_under_that_firm(): void
    {
        $event = TimelineEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id, 'TimelineEventFactory::definition() defaults firm_id to Firm::factory() — never null, matching TimelineEventRecorder::record()\'s own non-nullable Firm contract.');

        $persisted = $this->runWithFirmContext($event->firm_id, fn () => TimelineEvent::query()->find($event->id));
        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');

        $otherFirm = Firm::factory()->create();
        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => TimelineEvent::query()->find($event->id));
        $this->assertNull($notVisibleToOther, 'A bare factory-created row must not be visible under a sibling firm\'s context.');
    }

    public function test_explicit_for_firm_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $event = TimelineEvent::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $event->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->find($event->id));
        $this->assertNotNull($persisted);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => TimelineEvent::query()->find($event->id));
        $this->assertNull($notVisibleToOther);
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $event = TimelineEvent::factory()->create();

        $this->assertNotNull($event->firm_id, 'The bare factory create() must still succeed and produce its own genuinely resolved firm_id, despite the stale ambient context from a prior factory call.');
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

    public function test_recorder_record_clears_context_after_success_when_caller_wraps_it(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->recorder()->record($firm, 'lifecycle-check'));

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

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 33 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Fifty previously forced tables plus timeline_events must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses pilot_feedback_items as
     * the companion table (forced immediately prior, at Checkpoint 32).
     */
    public function test_timeline_events_are_isolated_independently_and_simultaneously_with_pilot_feedback_items(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b'));

        $pilotA = $this->runWithFirmContext($firmA, fn () => PilotFeedbackItem::factory()->forFirm($firmA)->create());
        $pilotB = $this->runWithFirmContext($firmB, fn () => PilotFeedbackItem::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'timeline_events' => TimelineEvent::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'pilot_feedback_items' => PilotFeedbackItem::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['timeline_events']);
        $this->assertNotContains($rowB, $resultA['timeline_events']);
        $this->assertSame([$pilotA->id], $resultA['pilot_feedback_items']);
        $this->assertNotContains($pilotB->id, $resultA['pilot_feedback_items']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: down() must genuinely restore the pre-FORCE
     * baseline — RLS still enabled, FORCE cleared. Unlike every one of
     * the six prior nullable-firm_id checkpoints, there is no policy to
     * restore because none was ever dropped: the original policy text
     * must be provably unchanged both BEFORE and AFTER the down()/up()
     * cycle. Also proves rollback affects ONLY this one table — every
     * other previously-forced table (including pilot_feedback_items,
     * the immediately prior checkpoint's own two-policy shape) must be
     * untouched throughout. up() is re-run in a finally block so this
     * test leaves the schema in the same state it found it in.
     */
    public function test_timeline_events_migration_down_leaves_the_original_policy_untouched_and_affects_only_this_table(): void
    {
        $policyBefore = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'timeline_events'::regclass and polname = 'timeline_events_tenant_isolation'"
        );
        $this->assertNotNull($policyBefore);

        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'timeline_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while timeline_events is rolled back."
                );
            }

            // pilot_feedback_items' own two policies (a DIFFERENT
            // checkpoint's own migration) must be completely untouched
            // by this rollback.
            $pilotFeedbackReadPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_read'");
            $pilotFeedbackWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_write'");
            $this->assertNotNull($pilotFeedbackReadPolicy);
            $this->assertNotNull($pilotFeedbackWritePolicy);

            // The critical proof: the ORIGINAL policy is still exactly
            // present, byte-for-byte, DURING the rollback window too —
            // down() never dropped it, because up() never created it.
            $policyDuringRollback = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'timeline_events'::regclass and polname = 'timeline_events_tenant_isolation'"
            );
            $this->assertNotNull($policyDuringRollback, 'The original policy must still exist even with FORCE off — down() never drops any policy for this table.');
            $this->assertSame($policyBefore->using_expr, $policyDuringRollback->using_expr, 'The policy text must be byte-for-byte identical before and during rollback.');
            $this->assertStringNotContainsString('IS NULL', $policyDuringRollback->using_expr);
            $this->assertNull($policyDuringRollback->with_check_expr);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'timeline_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $policyAfterUp = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'timeline_events'::regclass and polname = 'timeline_events_tenant_isolation'"
        );
        $this->assertNotNull($policyAfterUp, 'up() must leave the original policy in place — it never recreates one.');
        $this->assertSame($policyBefore->using_expr, $policyAfterUp->using_expr, 'The policy text must be byte-for-byte identical before and after the full down()/up() cycle — this migration never drops or recreates it.');
        $this->assertNull($policyAfterUp->with_check_expr);

        // Exactly one policy throughout — this checkpoint never added
        // a second policy.
        $count = DB::selectOne("select count(*) as c from pg_policy where polrelid = 'timeline_events'::regclass")->c;
        $this->assertSame(1, (int) $count);
    }
}
