<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\LeadSource;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LeadSourcesForceRlsActivationTest — Section 39A-3J (batch 1 of 4).
 * Proves the tenth staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php)
 * is permanently active for lead_sources — the first of this batch's
 * four approved prepared tables — and behaves correctly: fail-closed
 * with no context, correct cross-firm isolation, correct same-firm
 * access, and that every table forced by a prior section (clients,
 * firm_users, documents, deadlines, tasks, matters, invoices,
 * payments, conflict_check_runs) remains forced simultaneously.
 *
 * lead_sources is a small firm-scoped lookup table (code/name/
 * is_active) with no nested tenant-owned foreign key of its own — no
 * cross-firm-mismatch/related-model proof is required or claimed here
 * (see LeadSourceFactory's own doc comment and the migration's own
 * doc comment for why).
 */
class LeadSourcesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'clients'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'clients must remain FORCE RLS enabled after this branch.');
    }

    public function test_firm_users_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_users'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_users must remain FORCE RLS enabled after this branch.');
    }

    public function test_documents_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'documents'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'documents must remain FORCE RLS enabled after this branch.');
    }

    public function test_deadlines_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deadlines'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'deadlines must remain FORCE RLS enabled after this branch.');
    }

    public function test_tasks_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'tasks'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'tasks must remain FORCE RLS enabled after this branch.');
    }

    public function test_matters_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matters'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'matters must remain FORCE RLS enabled after this branch.');
    }

    public function test_invoices_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'invoices'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'invoices must remain FORCE RLS enabled after this branch.');
    }

    public function test_payments_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payments'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'payments must remain FORCE RLS enabled after this branch.');
    }

    public function test_conflict_check_runs_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'conflict_check_runs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'conflict_check_runs must remain FORCE RLS enabled after this branch.');
    }

    /**
     * consultation_outcomes, firm_leads, and consultations are this
     * same batch's other three tables — they land in the same
     * migration run as lead_sources (see the class doc comment), so
     * this file proves lead_sources' own isolation is correct
     * alongside its three siblings, not in a vacuum.
     */
    public function test_consultation_outcomes_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'consultation_outcomes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'consultation_outcomes must be FORCE RLS enabled alongside lead_sources in this batch.');
    }

    public function test_firm_leads_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_leads'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_leads must be FORCE RLS enabled alongside lead_sources in this batch.');
    }

    public function test_consultations_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'consultations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'consultations must be FORCE RLS enabled alongside lead_sources in this batch.');
    }

    public function test_lead_sources_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'lead_sources'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'lead_sources must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Section 39A-3J ships all four of this batch's migrations
     * (lead_sources, consultation_outcomes, firm_leads, consultations)
     * together in one branch/PR — unlike the earlier single-table
     * sections, there is no intermediate commit where only lead_sources
     * is forced and the other three are not. This test (and its
     * sibling in each of the other three activation-proof files)
     * therefore honestly asserts the real, final state: all thirteen
     * tables forced, no more, no fewer — it does not claim a
     * intermediate count that this working tree can never actually
     * produce.
     */
    public function test_exactly_eighteen_intended_tables_are_force_enabled(): void
    {
        // Section 39A-3L, Checkpoint 1, Table Phase C (a later, distinct
        // staged-FORCE-activation branch) legitimately activated FORCE
        // for payment_classification_events too — this test's own scope
        // only introduced eighteen, but the exact-count assertion below
        // must still account for that later, legitimate addition rather
        // than falsely reporting it as unexpected.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 2, Table
        // Phase C (this repo's twentieth staged FORCE activation batch,
        // covering activation_checklists) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 3, Table
        // Phase C (this repo's twenty-first staged FORCE activation
        // batch, covering firm_activation_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 4, Table
        // Phase C (this repo's twenty-second staged FORCE activation
        // batch, covering firm_entitlements) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 5, Table
        // Phase C (this repo's twenty-third staged FORCE activation
        // batch, covering firm_entitlement_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 6, Table
        // Phase C (this repo's twenty-fourth staged FORCE activation
        // batch, covering installed_template_packs) for the same reason
        // — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (this repo's twenty-seventh staged FORCE activation
        // batch, covering seat_allocations) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table
        // Phase C (this repo's twenty-eighth staged FORCE activation
        // batch, covering document_requests) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (this repo's twenty-ninth staged FORCE activation
        // batch, covering communication_consents) for the same reason —
        // additive only, no existing assertion removed or weakened.
        $expectedForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13,
            // Table Phase C (this repo's thirty-first staged FORCE
            // activation batch, covering intake_submissions) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            'communication_consents', 'communication_consent_events', 'intake_submissions',
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
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22,
            // Table Phase C (this repo's fortieth staged FORCE
            // activation batch, covering payment_plans) for the same
            // reason — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
            // Table Phase C (this repo's forty-first staged FORCE
            // activation batch, covering payment_plan_events) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
            // repo's forty-second staged FORCE activation batch,
            // covering notification_events) for the same reason as
            // above — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 27 (this repo's forty-fifth staged FORCE activation batch, covering backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events',             // Narrowly updated by Section 39A-3L, Checkpoint 28 (this repo's forty-sixth staged FORCE activation batch, covering health_checks) for the same reason — additive only, no existing assertion removed or weakened.
'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks',
        ];

        $rows = DB::select(
            "select relname from pg_class where relkind = 'r' and relnamespace = 'public'::regnamespace and relforcerowsecurity = true"
        );
        $actuallyForced = array_map(fn ($row) => $row->relname, $rows);

        sort($expectedForced);
        sort($actuallyForced);

        $this->assertSame($expectedForced, $actuallyForced, 'Exactly the eighteen tables introduced by 39A-3A..39A-3K, plus payment_classification_events (39A-3L Checkpoint 1), activation_checklists (39A-3L Checkpoint 2), and firm_activation_events (39A-3L Checkpoint 3), and firm_entitlements (39A-3L Checkpoint 4), and firm_entitlement_events (39A-3L Checkpoint 5), and installed_template_packs (39A-3L Checkpoint 6), and template_upgrade_logs (39A-3L Checkpoint 7), and template_upgrade_previews (39A-3L Checkpoint 8), and seat_allocations (39A-3L Checkpoint 9), and document_requests (39A-3L Checkpoint 10), and communication_consents (39A-3L Checkpoint 11), and communication_consent_events (39A-3L Checkpoint 12), and intake_submissions (39A-3L Checkpoint 13), must be FORCE RLS enabled — no more, no fewer.');
    }

    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new \App\Services\RowLevelSecurityCoverageMappingService();
        // Section 39A-3L, Checkpoint 1, Table Phase C (a later, distinct
        // staged-FORCE-activation branch) legitimately activated FORCE
        // for payment_classification_events too. Narrowly updated AGAIN
        // by Section 39A-3L, Checkpoint 2, Table Phase C for
        // activation_checklists, for the same reason. Narrowly updated
        // AGAIN by Section 39A-3L, Checkpoint 3, Table Phase C for
        // firm_activation_events, for the same reason.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (this repo's twenty-seventh staged FORCE activation
        // batch, covering seat_allocations) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table
        // Phase C (this repo's twenty-eighth staged FORCE activation
        // batch, covering document_requests) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (this repo's twenty-ninth staged FORCE activation
        // batch, covering communication_consents) for the same reason —
        // additive only, no existing assertion removed or weakened.
        $forced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13,
            // Table Phase C (this repo's thirty-first staged FORCE
            // activation batch, covering intake_submissions) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            'communication_consents', 'communication_consent_events', 'intake_submissions',
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
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22,
            // Table Phase C (this repo's fortieth staged FORCE
            // activation batch, covering payment_plans) for the same
            // reason — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
            // Table Phase C (this repo's forty-first staged FORCE
            // activation batch, covering payment_plan_events) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
            // repo's forty-second staged FORCE activation batch,
            // covering notification_events) for the same reason as
            // above — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 27 (this repo's forty-fifth staged FORCE activation batch, covering backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events',             // Narrowly updated by Section 39A-3L, Checkpoint 28 (this repo's forty-sixth staged FORCE activation batch, covering health_checks) for the same reason — additive only, no existing assertion removed or weakened.
'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks',
        ];

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    public function test_missing_tenant_context_cannot_read_lead_sources(): void
    {
        $firm = Firm::factory()->create();
        LeadSource::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, LeadSource::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_lead_sources(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('lead_sources')->insert([
            'firm_id' => $firm->id,
            'code' => 'referral',
            'name' => 'Referral',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_lead_sources(): void
    {
        $firmA = Firm::factory()->create();
        $sourceA = LeadSource::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => LeadSource::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$sourceA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_lead_sources(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        LeadSource::factory()->forFirm($firmA)->create();
        $sourceB = LeadSource::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => LeadSource::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($sourceB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_lead_source(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('lead_sources')->insertGetId([
                'firm_id' => $firmA->id,
                'code' => 'walk_in',
                'name' => 'Walk-in',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_lead_sources(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sourceB = LeadSource::factory()->forFirm($firmB)->create(['name' => 'Original Name']);

        $this->runWithFirmContext($firmA, function () use ($sourceB) {
            DB::table('lead_sources')->where('id', $sourceB->id)->update(['name' => 'Hijacked Name']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => LeadSource::withoutGlobalScopes()->find($sourceB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->name);
    }

    public function test_firm_a_cannot_delete_firm_b_lead_sources(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sourceB = LeadSource::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($sourceB) {
            DB::table('lead_sources')->where('id', $sourceB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => LeadSource::withoutGlobalScopes()->find($sourceB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B lead sources.');
    }

    public function test_firm_a_cannot_insert_a_lead_source_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('lead_sources')->insert([
                'firm_id' => $firmB->id,
                'code' => 'stolen',
                'name' => 'Stolen Source',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sourceA = LeadSource::factory()->forFirm($firmA)->create();

        // The lead_sources_tenant_isolation policy has no separate
        // WITH CHECK clause, so its single USING expression governs
        // both which existing rows are visible for update AND what
        // the resulting row must satisfy — from firm A's own context,
        // reassigning one of its own rows' firm_id to firm B produces
        // a row that would no longer match (firm_id = firm A), so
        // PostgreSQL rejects the write outright rather than letting it
        // silently stick.
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($sourceA, $firmB) {
            DB::table('lead_sources')->where('id', $sourceA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_factory_default_creation_is_insertable_under_force(): void
    {
        $source = LeadSource::factory()->create();

        $this->assertNotNull($source->id);
        $this->assertNotNull($source->firm_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => LeadSource::factory()->forFirm($firm)->create());

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
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'lead_sources'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new \App\Services\RowLevelSecurityCoverageMappingService();

        foreach ($coverage->missingPreparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this batch must not add new policies for uncovered tables."
            );
        }
    }

    public function test_no_other_policy_was_changed(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'lead_sources'::regclass and polname = 'lead_sources_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original lead_sources_tenant_isolation policy must still exist.');
        $this->assertSame(
            "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)",
            $row->using_expr,
            'The existing policy USING expression must be unchanged by this batch.'
        );
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this batch.');
    }

    public function test_rls_prepared_not_enforced_remains_tracked(): void
    {
        $registry = new \App\Services\ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
