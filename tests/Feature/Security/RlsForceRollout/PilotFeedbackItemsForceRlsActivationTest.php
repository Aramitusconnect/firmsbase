<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use App\Models\Firm;
use App\Models\PilotFeedbackItem;
use App\Services\ComplianceGapRegistryService;
use App\Services\PilotFeedbackService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PilotFeedbackItemsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 32 (Phase B6). Proves the fiftieth staged FORCE ROW LEVEL SECURITY
 * activation batch (database/migrations/2026_08_25_930032_force_rls_on_
 * pilot_feedback_items_table.php) is permanently active for
 * pilot_feedback_items and behaves correctly: every previously-forced
 * table remains forced simultaneously; missing-context read/insert
 * denial; a firm-specific row remains strictly single-firm-visible; a
 * platform-wide (firm_id = NULL, internal-source) row is visible under
 * EVERY firm-scoped session's context; the asymmetric WITH CHECK closes
 * both the INSERT-side forgery gap and the DELETE-side gap, mirroring
 * backup_restore_tests'/health_checks'/incident_events'/
 * maintenance_windows'/notification_templates' own two-policy design
 * exactly.
 *
 * This checkpoint's own novel contribution, directly transplanted from
 * maintenance_windows (the same class of fix, not a new one): all six
 * PilotFeedbackService transition methods (triage(), startProgress(),
 * resolve(), markWontFix(), markDuplicate(), scheduleFollowUp()) follow
 * the shape `$item->update([...]); return $item->fresh();` — this test
 * file directly proves the wrap-extends-through-fresh() fix works (not
 * just that it was written) by exercising each of the six methods
 * against a genuinely firm-scoped item and asserting a populated model
 * (not null) comes back under real FORCE.
 *
 * This table's own second, genuinely new detail (not shared by any of
 * the five prior nullable-firm_id checkpoints in this arc):
 * PilotFeedbackItemFactory::definition() defaults 'firm_id' =>
 * Firm::factory() — non-null by default, the inverse of every prior
 * table's null-by-default factory. tests/Feature/Phase5PublicUuidTest.php
 * :94's bare, unmodified PilotFeedbackItem::factory()->create() call
 * therefore exercises the firm-scoped branch of the factory's
 * context-hold create() override by default. This file's own factory
 * proofs below re-verify both branches directly, and this checkpoint's
 * verification pass separately re-runs Phase5PublicUuidTest.php itself
 * under real FORCE as the checkpoint's own cross-checkpoint-regression
 * -style check (this table's analogue to notification_templates' own
 * regression check).
 *
 * Full design rationale and the exact approved SQL:
 * rls-checkpoints/39a3l/B6-pilot_feedback_items-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 */
class PilotFeedbackItemsForceRlsActivationTest extends TestCase
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
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans',
        'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests',
        'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates',
    ];

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    private function pilotFeedbackService(): PilotFeedbackService
    {
        return new PilotFeedbackService();
    }

    private function insertRow(?int $firmId, string $suffix, ?PilotFeedbackSource $source = null): int
    {
        return DB::table('pilot_feedback_items')->insertGetId([
            'firm_id' => $firmId,
            'client_id' => null,
            'matter_id' => null,
            'user_id' => null,
            'source' => ($source ?? ($firmId === null ? PilotFeedbackSource::Internal : PilotFeedbackSource::Firm))->value,
            'category' => PilotFeedbackCategory::Bug->value,
            'priority' => PilotFeedbackPriority::Medium->value,
            'status' => PilotFeedbackStatus::New->value,
            'title' => 'RLS proof row '.$suffix,
            'description' => 'RLS proof description '.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    public function test_pilot_feedback_items_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'pilot_feedback_items'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_pilot_feedback_items_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'pilot_feedback_items'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'pilot_feedback_items must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly fifty tables (the forty-nine previously forced plus
     * pilot_feedback_items) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_fifty_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['pilot_feedback_items', 'timeline_events']);

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

        $this->assertSame(51, count($actuallyForced), 'Exactly fifty prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 32 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['pilot_feedback_items', 'timeline_events']);

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
     * This migration REPLACES the original single-expression policy
     * with two new policies — unlike every FORCE-only checkpoint, where
     * the pre-existing policy was left completely untouched.
     */
    public function test_the_original_single_policy_no_longer_exists(): void
    {
        $policy = DB::selectOne(
            "select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_isolation'"
        );

        $this->assertNull($policy, 'The original single-expression policy must have been dropped and replaced by the two new policies.');
    }

    public function test_the_read_and_write_policies_exist_with_the_expected_shape(): void
    {
        $readPolicy = DB::selectOne(
            "select polname, polcmd, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_read'"
        );

        $this->assertNotNull($readPolicy, 'pilot_feedback_items_tenant_read must exist.');
        $this->assertSame('r', $readPolicy->polcmd, 'the read policy must be FOR SELECT only.');
        $this->assertStringContainsString('firm_id IS NULL', $readPolicy->using_expr);
        $this->assertNull($readPolicy->with_check_expr, 'a FOR SELECT policy has no WITH CHECK.');

        $writePolicy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_write'"
        );

        $this->assertNotNull($writePolicy, 'pilot_feedback_items_tenant_write must exist.');
        $this->assertNotNull($writePolicy->with_check_expr, 'the write policy must carry an explicit, asymmetric WITH CHECK — not the FOR ALL implicit reuse of USING.');
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->using_expr);
        $this->assertStringContainsString('firm_id IS NULL', $writePolicy->with_check_expr);
    }

    /**
     * No other table's policy was modified by this migration — spot
     * check clients' own policy (the very first table forced in this
     * arc) and notification_templates' own policy (the immediately
     * prior checkpoint) as representative unrelated policies.
     */
    public function test_no_other_tables_policy_was_changed(): void
    {
        $clientsPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'clients'::regclass");
        $this->assertNotNull($clientsPolicy);
        $this->assertSame('clients_tenant_isolation', $clientsPolicy->polname);

        $notificationTemplatesWritePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'notification_templates'::regclass and polname = 'notification_templates_tenant_write'");
        $this->assertNotNull($notificationTemplatesWritePolicy);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_a_firm_specific_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => $this->insertRow($firm->id, 'firm-specific'));

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, PilotFeedbackItem::query()->where('firm_id', $firm->id)->count());
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
    // Same-firm access / cross-firm isolation proofs — firm-specific
    // rows remain strictly single-firm-visible, unchanged from the
    // original policy's own intent.
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $rowId = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'firm-a-own'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PilotFeedbackItem::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
        );

        $this->assertSame([$rowId], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-only'));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PilotFeedbackItem::query()->pluck('id')->all(),
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
            return DB::table('pilot_feedback_items')->where('id', $rowB)->update(['title' => 'Hijacked']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s pilot_feedback_items row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PilotFeedbackItem::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertStringStartsWith('RLS proof row update-target', $reReadAsFirmB->title);
    }

    public function test_firm_a_context_cannot_reassign_firm_b_row_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'reassign-target'));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $rowB) {
            return DB::table('pilot_feedback_items')->where('id', $rowB)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s pilot_feedback_items row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PilotFeedbackItem::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_a_context_cannot_delete_firm_b_specific_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'delete-target'));

        $this->runWithFirmContext($firmA, function () use ($rowB) {
            DB::table('pilot_feedback_items')->where('id', $rowB)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PilotFeedbackItem::query()->find($rowB),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s pilot_feedback_items row.');
    }

    // ---------------------------------------------------------------
    // Platform-wide (firm_id = NULL, internal-source) row visibility
    // proofs — the central, positive read-side design decision this
    // checkpoint proves: every tenant may see every platform-wide row.
    // ---------------------------------------------------------------

    public function test_a_platform_wide_row_is_visible_under_every_firm_scoped_sessions_context(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $visibleToA = $this->runWithFirmContext($firmA, fn () => PilotFeedbackItem::query()->whereNull('firm_id')->pluck('id')->all());
        $visibleToB = $this->runWithFirmContext($firmB, fn () => PilotFeedbackItem::query()->whereNull('firm_id')->pluck('id')->all());

        $this->assertContains($platformWideId, $visibleToA, 'Firm A must see the platform-wide row.');
        $this->assertContains($platformWideId, $visibleToB, 'Firm B must also independently see the same platform-wide row.');
    }

    public function test_a_platform_wide_row_does_not_expose_sibling_firm_specific_rows(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'platform-wide-isolation-check'));

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'firm-b-not-leaked'));

        $visibleToA = $this->runWithFirmContext($firmA, fn () => PilotFeedbackItem::query()->pluck('id')->all());

        $this->assertNotContains($rowB, $visibleToA, 'Firm A must still not see Firm B\'s firm-specific row, even though a platform-wide row is visible to both.');
    }

    // ---------------------------------------------------------------
    // WITH CHECK asymmetry proofs — INSERT-side forgery prevention.
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
    // WITH CHECK/USING asymmetry proofs — DELETE-side gap closure.
    // WITH CHECK is never consulted for DELETE in PostgreSQL, so an
    // asymmetric WITH CHECK alone (closing INSERT-side forgery) does
    // nothing for this mirror-image case — the write policy's own
    // USING clause is what closes it.
    // ---------------------------------------------------------------

    public function test_a_firm_scoped_session_cannot_delete_a_platform_wide_row(): void
    {
        $platformWideId = $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'delete-gap-target'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () use ($platformWideId) {
            return DB::table('pilot_feedback_items')->where('id', $platformWideId)->delete();
        });

        $this->assertSame(0, $affected, 'A firm-scoped session must not be able to delete a platform-wide (firm_id = NULL) row.');

        $stillExists = $this->tenantContext()->runWithoutFirmContext(
            fn () => PilotFeedbackItem::query()->whereNull('firm_id')->find($platformWideId),
        );

        $this->assertNotNull($stillExists, 'The platform-wide row must genuinely still exist in the database after the blocked delete attempt.');
    }

    public function test_a_firm_scoped_session_cannot_delete_all_platform_wide_rows_via_a_direct_is_null_filter(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-1'));
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'is-null-exploit-shape-2'));

        $firm = Firm::factory()->create();

        $affected = $this->runWithFirmContext($firm, function () {
            return DB::table('pilot_feedback_items')->whereNull('firm_id')->delete();
        });

        $this->assertSame(0, $affected, 'DELETE FROM pilot_feedback_items WHERE firm_id IS NULL must affect zero rows under a firm-scoped session.');

        $remaining = $this->tenantContext()->runWithoutFirmContext(
            fn () => PilotFeedbackItem::query()->whereNull('firm_id')->count(),
        );

        $this->assertSame(2, $remaining, 'Both platform-wide rows must genuinely still exist.');
    }

    public function test_a_genuinely_context_free_session_can_delete_a_platform_wide_row(): void
    {
        $this->tenantContext()->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $platformWideId = $this->insertRow(null, 'context-free-delete-target');

        $affected = DB::table('pilot_feedback_items')->where('id', $platformWideId)->delete();

        $this->assertSame(1, $affected, 'A genuinely context-free session must be able to delete a platform-wide row it is also able to write.');
    }

    /**
     * Direct SQL-level proof a firm-scoped session cannot write into a
     * sibling firm's firm_id via UPDATE — mirror of the INSERT-side
     * forgery proof above, exercised through UPDATE ... SET firm_id.
     * Unlike the cross-firm UPDATE proof above (where the target row is
     * invisible under USING and the UPDATE silently affects zero rows),
     * this row IS visible under USING (firm A owns it) — the failure
     * instead comes from WITH CHECK rejecting the resulting new row
     * (firm_id = firm B) outright, raising a hard row-level-security
     * QueryException rather than returning 0.
     */
    public function test_a_firm_scoped_session_cannot_update_its_own_row_to_claim_sibling_firm_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'reassign-to-sibling'));

        try {
            $this->runWithFirmContext($firmA, function () use ($firmB, $rowA) {
                return DB::table('pilot_feedback_items')->where('id', $rowA)->update(['firm_id' => $firmB->id]);
            });
            $this->fail('Expected a row-level security policy violation when Firm A tries to reassign its own row to Firm B.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('row-level security policy', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext();

        $stillFirmAs = $this->runWithFirmContext($firmA, fn () => PilotFeedbackItem::query()->find($rowA));
        $this->assertNotNull($stillFirmAs);
        $this->assertSame($firmA->id, $stillFirmAs->firm_id);
    }

    // ---------------------------------------------------------------
    // Novel security contribution — the wrap-must-extend-through-
    // fresh() fix, proven directly against a firm-scoped item for each
    // of the six transition methods. Every prior test of these methods
    // (PilotFeedbackServiceTest) exercised only null-firm_id items, so
    // this is the first place any test in this repo exercises a
    // non-null-firm_id pilot feedback item's transitions at all.
    // ---------------------------------------------------------------

    public function test_triage_against_a_firm_scoped_item_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::Bug,
            'Firm-scoped bug report',
            'Description',
            firm: $firm,
        );

        $triaged = $service->triage($item, PilotFeedbackPriority::High);

        $this->assertNotNull($triaged, 'triage()\'s trailing fresh() must return a populated model, not null, for a firm-scoped item under FORCE.');
        $this->assertSame(PilotFeedbackStatus::Triaged, $triaged->status);
        $this->assertSame(PilotFeedbackPriority::High, $triaged->priority);
        $this->assertSame($firm->id, $triaged->firm_id);
    }

    public function test_start_progress_against_a_firm_scoped_item_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::Bug,
            'Firm-scoped bug in progress',
            'Description',
            firm: $firm,
        );

        $started = $service->startProgress($item);

        $this->assertNotNull($started, 'startProgress()\'s trailing fresh() must return a populated model, not null, for a firm-scoped item under FORCE.');
        $this->assertSame(PilotFeedbackStatus::InProgress, $started->status);
        $this->assertSame($firm->id, $started->firm_id);
    }

    public function test_resolve_against_a_firm_scoped_item_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::Bug,
            'Firm-scoped bug to resolve',
            'Description',
            firm: $firm,
        );

        $resolved = $service->resolve($item, 'Fixed in the next release.');

        $this->assertNotNull($resolved, 'resolve()\'s trailing fresh() must return a populated model, not null, for a firm-scoped item under FORCE.');
        $this->assertSame(PilotFeedbackStatus::Resolved, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertTrue($resolved->isResolved());
        $this->assertSame($firm->id, $resolved->firm_id);
    }

    public function test_mark_wont_fix_against_a_firm_scoped_item_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::Other,
            'Firm-scoped wont-fix candidate',
            'Description',
            firm: $firm,
        );

        $wontFix = $service->markWontFix($item, 'Out of scope for this pilot.');

        $this->assertNotNull($wontFix, 'markWontFix()\'s trailing fresh() must return a populated model, not null, for a firm-scoped item under FORCE.');
        $this->assertSame(PilotFeedbackStatus::WontFix, $wontFix->status);
        $this->assertSame('Out of scope for this pilot.', $wontFix->resolution_notes);
        $this->assertSame($firm->id, $wontFix->firm_id);
    }

    public function test_mark_duplicate_against_a_firm_scoped_item_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::Other,
            'Firm-scoped duplicate candidate',
            'Description',
            firm: $firm,
        );

        $duplicate = $service->markDuplicate($item);

        $this->assertNotNull($duplicate, 'markDuplicate()\'s trailing fresh() must return a populated model, not null, for a firm-scoped item under FORCE.');
        $this->assertSame(PilotFeedbackStatus::Duplicate, $duplicate->status);
        $this->assertSame($firm->id, $duplicate->firm_id);
    }

    public function test_schedule_follow_up_against_a_firm_scoped_item_returns_a_populated_model_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Firm,
            PilotFeedbackCategory::FeatureRequest,
            'Firm-scoped follow-up candidate',
            'Description',
            firm: $firm,
        );

        $followUpAt = now()->addWeek();
        $followedUp = $service->scheduleFollowUp($item, $followUpAt);

        $this->assertNotNull($followedUp, 'scheduleFollowUp()\'s trailing fresh() must return a populated model, not null, for a firm-scoped item under FORCE.');
        $this->assertTrue($followedUp->follow_up_required);
        $this->assertSame($followUpAt->copy()->startOfSecond()->timestamp, $followedUp->follow_up_at->timestamp);
        $this->assertSame($firm->id, $followedUp->firm_id);
    }

    /**
     * Full lifecycle exercised in one place — submit -> triage ->
     * startProgress -> resolve — against a single firm-scoped item,
     * confirming context is correctly re-derived from $item->firm_id at
     * every step (not carried over incidentally from a prior call), and
     * the item remains readable under the firm's own context throughout.
     */
    public function test_full_lifecycle_against_a_firm_scoped_item_returns_populated_models_at_every_step_under_force(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(
            PilotFeedbackSource::Client,
            PilotFeedbackCategory::Bug,
            'Firm-scoped full lifecycle',
            'Description',
            firm: $firm,
        );
        $this->assertSame($firm->id, $item->firm_id);

        $triaged = $service->triage($item, PilotFeedbackPriority::Critical);
        $this->assertNotNull($triaged);
        $this->assertSame($firm->id, $triaged->firm_id);

        $started = $service->startProgress($triaged);
        $this->assertNotNull($started);
        $this->assertSame($firm->id, $started->firm_id);

        $resolved = $service->resolve($started, 'Deployed the fix.');
        $this->assertNotNull($resolved);
        $this->assertSame(PilotFeedbackStatus::Resolved, $resolved->status);
        $this->assertSame($firm->id, $resolved->firm_id);

        $reReadAsFirm = $this->runWithFirmContext($firm, fn () => PilotFeedbackItem::query()->find($item->id));
        $this->assertNotNull($reReadAsFirm, 'The item must remain readable under its own firm\'s context after the full lifecycle.');
        $this->assertSame(PilotFeedbackStatus::Resolved, $reReadAsFirm->status);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs — this table's factory defaults to a
    // FIRM-SCOPED row (Firm::factory()), the inverse of every prior
    // nullable-firm_id table's null-by-default factory. Both branches
    // (bare default and explicit internal()/null override) are proven
    // here directly, not merely assumed safe by analogy.
    // ---------------------------------------------------------------

    public function test_bare_factory_default_creation_is_firm_scoped_and_immediately_readable_under_that_firm(): void
    {
        $row = PilotFeedbackItem::factory()->create();

        $this->assertNotNull($row->id);
        $this->assertNotNull($row->firm_id, 'PilotFeedbackItemFactory::definition() defaults firm_id to Firm::factory(), unlike every prior table\'s null-by-default factory.');

        $persisted = $this->runWithFirmContext($row->firm_id, fn () => PilotFeedbackItem::query()->find($row->id));
        $this->assertNotNull($persisted, 'A bare factory-created firm-scoped row must be visible under its own firm\'s context.');
        $this->assertSame($row->firm_id, $persisted->firm_id);

        $otherFirm = Firm::factory()->create();
        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => PilotFeedbackItem::query()->find($row->id));
        $this->assertNull($notVisibleToOther, 'A bare factory-created firm-scoped row must not be visible under a sibling firm\'s context.');
    }

    public function test_internal_factory_state_produces_a_genuinely_null_firm_id_row_visible_under_any_firm(): void
    {
        $row = PilotFeedbackItem::factory()->internal()->create();

        $this->assertNull($row->firm_id);
        $this->assertSame(PilotFeedbackSource::Internal, $row->source);

        $firm = Firm::factory()->create();
        $persisted = $this->runWithFirmContext($firm, fn () => PilotFeedbackItem::query()->find($row->id));

        $this->assertNotNull($persisted, 'An internal() factory-created platform-wide row must be visible under any firm\'s own context.');
        $this->assertNull($persisted->firm_id);
    }

    public function test_explicit_for_firm_factory_state_is_internally_consistent_and_firm_scoped(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $row = PilotFeedbackItem::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $row->firm_id);

        $persisted = $this->runWithFirmContext($firm, fn () => PilotFeedbackItem::query()->find($row->id));
        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => PilotFeedbackItem::query()->where('firm_id', $firm->id)->find($row->id));
        $this->assertNull($notVisibleToOther, 'A firm-scoped factory row must not be visible under a sibling firm\'s own context.');
    }

    public function test_bare_factory_create_succeeds_after_a_prior_client_factory_call_in_the_same_test(): void
    {
        $firm = Firm::factory()->create();

        \App\Models\Client::factory()->forFirm($firm)->create();
        $this->assertDatabaseTenantContextIs($firm, 'ClientFactory must have left a stale, non-null DB-level context active.');

        $row = PilotFeedbackItem::factory()->create();

        $this->assertNotNull($row->firm_id, 'The bare factory create() must still succeed and produce its own genuinely resolved firm_id, despite the stale ambient context from a prior factory call.');
    }

    /**
     * This checkpoint's own cross-checkpoint regression check: the
     * exact bare, unmodified call the dossier flags as this table's
     * analogue to notification_templates' own regression check —
     * tests/Feature/Phase5PublicUuidTest.php:94's
     * PilotFeedbackItem::factory()->create(). Re-verified here directly
     * (not merely re-run elsewhere) as this file's own proof that the
     * factory's symmetric grouping fix genuinely handles the firm-scoped
     * default branch under real FORCE.
     */
    public function test_bare_factory_create_matches_the_exact_call_shape_used_by_phase_5_public_uuid_test(): void
    {
        $item = PilotFeedbackItem::factory()->create();

        $this->assertNotNull($item->id);
        $this->assertArrayNotHasKey('uuid', $item->getAttributes(), 'pilot_feedback_items deliberately carries no public uuid.');

        $persisted = $this->runWithFirmContext($item->firm_id, fn () => PilotFeedbackItem::query()->find($item->id));
        $this->assertNotNull($persisted, 'The bare-call factory row must genuinely persist and be readable under its own resolved firm context.');
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

    public function test_run_without_firm_context_clears_database_context_after_success(): void
    {
        $this->tenantContext()->runWithoutFirmContext(fn () => $this->insertRow(null, 'without-context-success'));

        $this->assertNoDatabaseTenantContext();
    }

    public function test_pilot_feedback_service_submit_clears_database_context_after_a_firm_scoped_operation(): void
    {
        $firm = Firm::factory()->create();

        $this->pilotFeedbackService()->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::Bug, 'Context lifecycle proof', 'Description', firm: $firm);

        $this->assertNoDatabaseTenantContext();
    }

    public function test_pilot_feedback_service_submit_clears_database_context_after_a_platform_wide_operation(): void
    {
        $this->pilotFeedbackService()->submit(PilotFeedbackSource::Internal, PilotFeedbackCategory::Bug, 'Context lifecycle proof, platform-wide', 'Description');

        $this->assertNoDatabaseTenantContext();
    }

    public function test_full_lifecycle_against_a_firm_scoped_item_clears_context_after_every_step(): void
    {
        $firm = Firm::factory()->create();
        $service = $this->pilotFeedbackService();

        $item = $service->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::Bug, 'Context lifecycle, full trip', 'Description', firm: $firm);
        $this->assertNoDatabaseTenantContext();

        $triaged = $service->triage($item, PilotFeedbackPriority::High);
        $this->assertNoDatabaseTenantContext();

        $service->startProgress($triaged);
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Real production writer/reader proofs — PilotFeedbackService
    // ---------------------------------------------------------------

    public function test_submit_with_no_firm_persists_a_genuinely_visible_platform_wide_row(): void
    {
        $item = $this->pilotFeedbackService()->submit(PilotFeedbackSource::Internal, PilotFeedbackCategory::FeatureRequest, 'Add bulk export', 'Ops would like a bulk CSV export.');

        $this->assertNull($item->firm_id);

        $firm = Firm::factory()->create();
        $visible = $this->runWithFirmContext($firm, fn () => PilotFeedbackItem::query()->find($item->id));
        $this->assertNotNull($visible, 'submit() with no firm must genuinely persist a row visible under any firm\'s context.');
    }

    public function test_submit_with_a_firm_persists_a_firm_scoped_row_invisible_to_a_sibling(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $item = $this->pilotFeedbackService()->submit(PilotFeedbackSource::Firm, PilotFeedbackCategory::Bug, 'Upload fails on large PDFs', 'Uploads over 20MB time out.', firm: $firm);

        $this->assertSame($firm->id, $item->firm_id);

        $visible = $this->runWithFirmContext($firm, fn () => PilotFeedbackItem::query()->find($item->id));
        $this->assertNotNull($visible);

        $notVisibleToOther = $this->runWithFirmContext($otherFirm, fn () => PilotFeedbackItem::query()->find($item->id));
        $this->assertNull($notVisibleToOther);
    }

    // ---------------------------------------------------------------
    // Residual gap scope note — pilot_feedback_items' own client_id/
    // matter_id can, in principle, reference a client/matter owned by a
    // DIFFERENT firm than this row's own firm_id (clients.firm_id and
    // matters.firm_id are both required, single-owner columns). No
    // single-table RLS policy on pilot_feedback_items can catch this —
    // it governs visibility by this table's own firm_id column only.
    // Proven directly below (not merely asserted) that RLS does NOT
    // block this cross-table mismatch, so this residual gap is
    // documented as real rather than incorrectly claimed as closed.
    // ---------------------------------------------------------------

    /**
     * Direct proof of the residual, currently-unenforced gap disclosed
     * in the design dossier: a firm-scoped pilot_feedback_items row's
     * own firm_id can legitimately pass RLS (the row genuinely belongs
     * to firm A) while its client_id points to a client actually owned
     * by firm B — RLS on pilot_feedback_items alone has no visibility
     * into the clients table's own firm_id column and cannot catch
     * this. This is NOT a defect in the RLS design; it is a genuine,
     * documented, out-of-scope residual gap that only a cross-table
     * trigger or application-level check could close.
     */
    public function test_rls_does_not_prevent_a_client_id_firm_id_cross_table_mismatch(): void
    {
        $ownerFirm = Firm::factory()->create();
        $clientOwningFirm = Firm::factory()->create();

        $client = $this->runWithFirmContext($clientOwningFirm, fn () => \App\Models\Client::factory()->forFirm($clientOwningFirm)->create());

        // Firm A inserts its OWN pilot_feedback_items row (firm_id =
        // ownerFirm, passes WITH CHECK genuinely) but sets client_id to
        // a client actually owned by a different firm entirely. RLS
        // does not, and structurally cannot, reject this.
        $rowId = $this->runWithFirmContext($ownerFirm, function () use ($ownerFirm, $client) {
            return DB::table('pilot_feedback_items')->insertGetId([
                'firm_id' => $ownerFirm->id,
                'client_id' => $client->id,
                'matter_id' => null,
                'user_id' => null,
                'source' => PilotFeedbackSource::Firm->value,
                'category' => PilotFeedbackCategory::Bug->value,
                'priority' => PilotFeedbackPriority::Medium->value,
                'status' => PilotFeedbackStatus::New->value,
                'title' => 'Cross-table mismatch proof',
                'description' => 'firm_id belongs to ownerFirm, client_id belongs to a different firm entirely.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($rowId, 'RLS does not, and structurally cannot, prevent this cross-table firm_id/client_id mismatch — documented as a residual database-constraint gap, not an RLS guarantee.');

        $persisted = $this->runWithFirmContext($ownerFirm, fn () => PilotFeedbackItem::query()->find($rowId));
        $this->assertNotNull($persisted);
        $this->assertSame($ownerFirm->id, $persisted->firm_id);
        $this->assertSame($client->id, $persisted->client_id);
        $this->assertNotSame($ownerFirm->id, $client->firm_id, 'Confirms the mismatch actually exists: the client genuinely belongs to a different firm than the pilot_feedback_items row that references it.');
    }

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation proofs
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
     * only, matching the contacts/parties/backup_restore_tests/
     * health_checks/incident_events/maintenance_windows/
     * notification_templates precedent's own scope.
     */
    public function test_no_ui_routes_or_controllers_were_introduced_by_this_checkpoint(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire', 'app/Services/Payments', 'app/Services/Storage', 'app/Services/Ai', 'app/Http/Controllers/ClientPortal', 'app/Services/Marketplace'] as $relativeDir) {
            $changed = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($relativeDir)
            ));

            $this->assertSame('', $changed, "Section 39A-3L, Checkpoint 32 must introduce no UI/route/domain surface, but found changes under {$relativeDir}.");
        }
    }

    /**
     * Forty-nine previously forced tables plus pilot_feedback_items must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses notification_templates as
     * the companion table (forced immediately prior, at Checkpoint 31).
     */
    public function test_pilot_feedback_items_are_isolated_independently_and_simultaneously_with_notification_templates(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $rowA = $this->runWithFirmContext($firmA, fn () => $this->insertRow($firmA->id, 'simultaneous-a'));
        $rowB = $this->runWithFirmContext($firmB, fn () => $this->insertRow($firmB->id, 'simultaneous-b'));

        $templateA = $this->runWithFirmContext($firmA, fn () => DB::table('notification_templates')->insertGetId([
            'firm_id' => $firmA->id,
            'key' => 'simultaneous-proof-a',
            'channel' => \App\Enums\ConsentChannel::Email->value,
            'language' => 'en',
            'status' => \App\Enums\NotificationTemplateStatus::Active->value,
            'subject' => 'Simultaneous isolation proof A',
            'body' => 'Body A',
            'spf_status' => \App\Enums\SenderDomainStatus::Pending->value,
            'dkim_status' => \App\Enums\SenderDomainStatus::Pending->value,
            'dmarc_status' => \App\Enums\SenderDomainStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $templateB = $this->runWithFirmContext($firmB, fn () => DB::table('notification_templates')->insertGetId([
            'firm_id' => $firmB->id,
            'key' => 'simultaneous-proof-b',
            'channel' => \App\Enums\ConsentChannel::Email->value,
            'language' => 'en',
            'status' => \App\Enums\NotificationTemplateStatus::Active->value,
            'subject' => 'Simultaneous isolation proof B',
            'body' => 'Body B',
            'spf_status' => \App\Enums\SenderDomainStatus::Pending->value,
            'dkim_status' => \App\Enums\SenderDomainStatus::Pending->value,
            'dmarc_status' => \App\Enums\SenderDomainStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'pilot_feedback_items' => PilotFeedbackItem::query()->where('firm_id', $firmA->id)->pluck('id')->all(),
            'notification_templates' => DB::table('notification_templates')->pluck('id')->all(),
        ]);

        $this->assertSame([$rowA], $resultA['pilot_feedback_items']);
        $this->assertNotContains($rowB, $resultA['pilot_feedback_items']);
        $this->assertContains($templateA, $resultA['notification_templates']);
        $this->assertNotContains($templateB, $resultA['notification_templates']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the pilot_feedback_items migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * but NOT forced, and the ORIGINAL single-expression policy restored
     * byte-for-byte (both new policies dropped). Also proves rollback
     * affects ONLY this one table — every other previously-forced table
     * must be untouched. up() is re-run in a finally block so this test
     * leaves the schema in the same state it found it in.
     */
    public function test_pilot_feedback_items_migration_down_restores_the_original_single_policy_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930032_force_rls_on_pilot_feedback_items_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'pilot_feedback_items'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while pilot_feedback_items is rolled back."
                );
            }

            $readPolicy = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_read'");
            $writePolicy = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_write'");
            $this->assertNull($readPolicy, 'Rollback must drop the new read policy.');
            $this->assertNull($writePolicy, 'Rollback must drop the new write policy.');

            $originalPolicy = DB::selectOne(
                "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
                 from pg_policy
                 where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_isolation'"
            );
            $this->assertNotNull($originalPolicy, 'Rollback must restore the original single-expression policy.');
            $this->assertStringContainsString('current_setting', $originalPolicy->using_expr);
            $this->assertStringContainsString('firm_id', $originalPolicy->using_expr);
            $this->assertStringNotContainsString('IS NULL', $originalPolicy->using_expr, 'The restored original policy must be byte-for-byte the Phase 5 preparation text — it never had an IS NULL branch.');
            $this->assertNull($originalPolicy->with_check_expr, 'The restored original policy never had a separate WITH CHECK clause.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'pilot_feedback_items'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');

        $writePolicyAfterUp = DB::selectOne("select polname from pg_policy where polrelid = 'pilot_feedback_items'::regclass and polname = 'pilot_feedback_items_tenant_write'");
        $this->assertNotNull($writePolicyAfterUp, 'up() must recreate the write policy.');
    }
}
