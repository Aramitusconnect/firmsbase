<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\ConsultationOutcome;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ConsultationOutcomesForceRlsActivationTest — Section 39A-3J (batch 2
 * of 4). Proves the eleventh staged FORCE ROW LEVEL SECURITY
 * activation batch
 * (database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php)
 * is permanently active for consultation_outcomes and behaves
 * correctly: fail-closed with no context, correct cross-firm
 * isolation, correct same-firm access, and that every table forced by
 * a prior section or by this same batch (clients, firm_users,
 * documents, deadlines, tasks, matters, invoices, payments,
 * conflict_check_runs, lead_sources, firm_leads, consultations)
 * remains forced simultaneously.
 *
 * consultation_outcomes is a small firm-scoped lookup table (code/
 * name/is_active) with no nested tenant-owned foreign key of its own —
 * no cross-firm-mismatch/related-model proof is required or claimed
 * here (see ConsultationOutcomeFactory's own doc comment and the
 * migration's own doc comment for why).
 */
class ConsultationOutcomesForceRlsActivationTest extends TestCase
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
     * lead_sources, firm_leads, and consultations are this same
     * batch's other three tables — they land in the same migration
     * run as consultation_outcomes, so this file proves
     * consultation_outcomes' own isolation is correct alongside its
     * three siblings, not in a vacuum.
     */
    public function test_lead_sources_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'lead_sources'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'lead_sources must be FORCE RLS enabled alongside consultation_outcomes in this batch.');
    }

    public function test_firm_leads_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_leads'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_leads must be FORCE RLS enabled alongside consultation_outcomes in this batch.');
    }

    public function test_consultations_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'consultations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'consultations must be FORCE RLS enabled alongside consultation_outcomes in this batch.');
    }

    public function test_consultation_outcomes_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'consultation_outcomes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'consultation_outcomes must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Section 39A-3J ships all four of this batch's migrations
     * together — see LeadSourcesForceRlsActivationTest's own doc
     * comment for why this asserts the real final count (thirteen)
     * rather than a hypothetical intermediate one.
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
        $expectedForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events',
        ];

        $rows = DB::select(
            "select relname from pg_class where relkind = 'r' and relnamespace = 'public'::regnamespace and relforcerowsecurity = true"
        );
        $actuallyForced = array_map(fn ($row) => $row->relname, $rows);

        sort($expectedForced);
        sort($actuallyForced);

        $this->assertSame($expectedForced, $actuallyForced, 'Exactly the eighteen tables introduced by 39A-3A..39A-3K, plus payment_classification_events (39A-3L Checkpoint 1), activation_checklists (39A-3L Checkpoint 2), and firm_activation_events (39A-3L Checkpoint 3), must be FORCE RLS enabled — no more, no fewer.');
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
        $forced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events',
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

    public function test_missing_tenant_context_cannot_read_consultation_outcomes(): void
    {
        $firm = Firm::factory()->create();
        ConsultationOutcome::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, ConsultationOutcome::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_consultation_outcomes(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('consultation_outcomes')->insert([
            'firm_id' => $firm->id,
            'code' => 'converted',
            'name' => 'Converted',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_consultation_outcomes(): void
    {
        $firmA = Firm::factory()->create();
        $outcomeA = ConsultationOutcome::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ConsultationOutcome::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$outcomeA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_consultation_outcomes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        ConsultationOutcome::factory()->forFirm($firmA)->create();
        $outcomeB = ConsultationOutcome::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ConsultationOutcome::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($outcomeB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_consultation_outcome(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('consultation_outcomes')->insertGetId([
                'firm_id' => $firmA->id,
                'code' => 'no_show',
                'name' => 'No Show',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_consultation_outcomes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $outcomeB = ConsultationOutcome::factory()->forFirm($firmB)->create(['name' => 'Original Name']);

        $this->runWithFirmContext($firmA, function () use ($outcomeB) {
            DB::table('consultation_outcomes')->where('id', $outcomeB->id)->update(['name' => 'Hijacked Name']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ConsultationOutcome::withoutGlobalScopes()->find($outcomeB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->name);
    }

    public function test_firm_a_cannot_delete_firm_b_consultation_outcomes(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $outcomeB = ConsultationOutcome::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($outcomeB) {
            DB::table('consultation_outcomes')->where('id', $outcomeB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ConsultationOutcome::withoutGlobalScopes()->find($outcomeB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B consultation outcomes.');
    }

    public function test_firm_a_cannot_insert_a_consultation_outcome_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('consultation_outcomes')->insert([
                'firm_id' => $firmB->id,
                'code' => 'stolen',
                'name' => 'Stolen Outcome',
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
        $outcomeA = ConsultationOutcome::factory()->forFirm($firmA)->create();

        // The consultation_outcomes_tenant_isolation policy has no
        // separate WITH CHECK clause, so its single USING expression
        // governs both which existing rows are visible for update AND
        // what the resulting row must satisfy — from firm A's own
        // context, reassigning one of its own rows' firm_id to firm B
        // produces a row that would no longer match (firm_id = firm
        // A), so PostgreSQL rejects the write outright rather than
        // letting it silently stick.
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($outcomeA, $firmB) {
            DB::table('consultation_outcomes')->where('id', $outcomeA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_factory_default_creation_is_insertable_under_force(): void
    {
        $outcome = ConsultationOutcome::factory()->create();

        $this->assertNotNull($outcome->id);
        $this->assertNotNull($outcome->firm_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => ConsultationOutcome::factory()->forFirm($firm)->create());

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
        $migration = require base_path('database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'consultation_outcomes'");

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
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'consultation_outcomes'::regclass and polname = 'consultation_outcomes_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original consultation_outcomes_tenant_isolation policy must still exist.');
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
