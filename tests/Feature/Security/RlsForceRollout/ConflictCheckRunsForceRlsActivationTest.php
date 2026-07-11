<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\ConflictCheckRun;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Task;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ConflictCheckRunsForceRlsActivationTest — Section 39A-3I. Proves the
 * ninth staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php)
 * is permanently active for conflict_check_runs — the first HIGH-risk-
 * tier prepared table addressed after the seven originally
 * pilot-critical tables plus payments — and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that clients (39A-3A), firm_users (39A-3B),
 * documents (39A-3C), deadlines (39A-3D), tasks (39A-3E), matters
 * (39A-3F), invoices (39A-3G), payments (39A-3H), and conflict_check_runs
 * all remain forced simultaneously.
 */
class ConflictCheckRunsForceRlsActivationTest extends TestCase
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

    public function test_conflict_check_runs_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'conflict_check_runs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'conflict_check_runs must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Narrowly updated by Section 39A-3J (the next staged batch after
     * this file's own 39A-3I, forcing lead_sources,
     * consultation_outcomes, firm_leads, and consultations together):
     * this was the newest activation-proof file's own "exactly N
     * forced" boundary check before that batch landed, and it is the
     * one place in THIS file that must track the real count going
     * forward — same reasoning as RlsForceRolloutFirewallTest's own
     * narrowly-updated expected-forced list. No other assertion in
     * this file was touched.
     *
     * Narrowly updated AGAIN by Section 39A-3K (forcing
     * firm_practice_areas, document_chase_rules, employee_rates,
     * calendar_events, and client_communication_preferences together):
     * same reasoning, count now 18. Still the one place in THIS file
     * that tracks the real total.
     */
    public function test_exactly_eighteen_intended_tables_are_force_enabled(): void
    {
        // Section 39A-3L, Checkpoint 1, Table Phase C (a later, distinct
        // staged-FORCE-activation branch) legitimately activated FORCE
        // for payment_classification_events too — this test's own scope
        // only introduced eighteen, but the exact-count assertion below
        // must still account for that later, legitimate addition rather
        // than falsely reporting it as unexpected.
        $expectedForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events',
        ];

        $rows = DB::select(
            "select relname from pg_class where relkind = 'r' and relnamespace = 'public'::regnamespace and relforcerowsecurity = true"
        );
        $actuallyForced = array_map(fn ($row) => $row->relname, $rows);

        sort($expectedForced);
        sort($actuallyForced);

        $this->assertSame($expectedForced, $actuallyForced, 'Exactly the eighteen tables introduced by 39A-3A..39A-3K, plus payment_classification_events (39A-3L), must be FORCE RLS enabled — no more, no fewer.');
    }

    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new \App\Services\RowLevelSecurityCoverageMappingService();
        // Narrowly updated alongside the count test above for the same
        // Section 39A-3J/39A-3K reasons, and again by Section 39A-3L,
        // Checkpoint 1, Table Phase C for payment_classification_events.
        $forced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'payment_classification_events',
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

    public function test_missing_tenant_context_cannot_read_conflict_check_runs(): void
    {
        $firm = Firm::factory()->create();
        ConflictCheckRun::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, ConflictCheckRun::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_conflict_check_runs(): void
    {
        $firm = Firm::factory()->create();
        $matterId = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create())->id;

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('conflict_check_runs')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'matter_id' => $matterId,
            'status' => 'pending',
            'scope' => 'firm',
            'result_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_conflict_check_runs(): void
    {
        $firmA = Firm::factory()->create();
        $runA = ConflictCheckRun::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ConflictCheckRun::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$runA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_conflict_check_runs(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        ConflictCheckRun::factory()->forFirm($firmA)->create();
        $runB = ConflictCheckRun::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ConflictCheckRun::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($runB->id, $visibleIds);
    }

    public function test_firm_a_cannot_update_firm_b_conflict_check_runs(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $runB = ConflictCheckRun::factory()->forFirm($firmB)->create(['result_count' => 5]);

        $this->runWithFirmContext($firmA, function () use ($runB) {
            DB::table('conflict_check_runs')->where('id', $runB->id)->update(['result_count' => 999]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ConflictCheckRun::withoutGlobalScopes()->find($runB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(5, $reReadAsFirmB->result_count);
    }

    public function test_firm_a_cannot_delete_firm_b_conflict_check_runs(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $runB = ConflictCheckRun::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($runB) {
            DB::table('conflict_check_runs')->where('id', $runB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ConflictCheckRun::withoutGlobalScopes()->find($runB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B conflict-check runs.');
    }

    public function test_firm_a_cannot_insert_a_conflict_check_run_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterBId = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create())->id;

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $matterBId) {
            DB::table('conflict_check_runs')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'matter_id' => $matterBId,
                'status' => 'pending',
                'scope' => 'firm',
                'result_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Known, documented residual gap (same as Matter/Client,
     * Invoice/Client/Matter, and Payment/Client/Matter/Invoice's own
     * equivalent mismatch proofs): RLS's single-column policy only
     * validates conflict_check_runs' own firm_id against session
     * context, never that matter_id transitively belongs to the same
     * firm. The insert succeeds because firm_id = firmA matches the
     * active context — this is why ConflictCheckRunFactory's own
     * root-cause fix (tying the nested matter to the same firm) matters,
     * and why a future composite/trigger-based DB constraint is
     * recommended.
     */
    public function test_firm_a_can_still_create_a_conflict_check_run_using_a_firm_b_matter_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $mismatchedRunId = $this->runWithFirmContext($firmA, function () use ($firmA, $matterB) {
            return DB::table('conflict_check_runs')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'matter_id' => $matterB->id,
                'status' => 'pending',
                'scope' => 'firm',
                'result_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedRunId);
    }

    public function test_factory_default_creation_cannot_silently_create_cross_firm_inconsistency(): void
    {
        $run = ConflictCheckRun::factory()->create();

        $matterFirmId = $this->runWithFirmContext($run->firm_id, fn () => $run->matter)->firm_id;

        $this->assertSame($run->firm_id, $matterFirmId, 'A bare ConflictCheckRun::factory()->create() must never produce a firm_id/matter_id mismatch.');
    }

    public function test_for_matter_state_preserves_ownership_consistency(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $run = $this->runWithFirmContext($firm, fn () => ConflictCheckRun::factory()->forMatter($matter)->create());

        $this->assertSame($firm->id, $run->firm_id);
        $this->assertSame($matter->id, $run->matter_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => ConflictCheckRun::factory()->forFirm($firm)->create());

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
        $migration = require base_path('database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'conflict_check_runs'");

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
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'conflict_check_runs'::regclass and polname = 'conflict_check_runs_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original conflict_check_runs_tenant_isolation policy must still exist.');
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
     * All nine forced tables must be isolated independently and
     * simultaneously — proof this batch did not weaken or interfere
     * with any of the prior eight tables' own enforcement.
     */
    public function test_all_nine_forced_tables_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = Client::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();
        $documentA = Document::factory()->create(['firm_id' => $firmA->id]);
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id]);
        $taskA = Task::factory()->create(['firm_id' => $firmA->id]);
        $taskB = Task::factory()->create(['firm_id' => $firmB->id]);
        $matterA = Matter::factory()->forFirm($firmA)->create();
        $matterB = Matter::factory()->forFirm($firmB)->create();
        $invoiceA = Invoice::factory()->forFirm($firmA)->create();
        $invoiceB = Invoice::factory()->forFirm($firmB)->create();
        $paymentA = Payment::factory()->forFirm($firmA)->create();
        $paymentB = Payment::factory()->forFirm($firmB)->create();
        $runA = ConflictCheckRun::factory()->forFirm($firmA)->create();
        $runB = ConflictCheckRun::factory()->forFirm($firmB)->create();

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
            'deadlines' => Deadline::withoutGlobalScopes()->pluck('id')->all(),
            'tasks' => Task::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
            'invoices' => Invoice::withoutGlobalScopes()->pluck('id')->all(),
            'payments' => Payment::withoutGlobalScopes()->pluck('id')->all(),
            'conflict_check_runs' => ConflictCheckRun::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
        $this->assertSame([$documentA->id], $resultA['documents']);
        $this->assertSame([], $resultA['deadlines']);
        $this->assertNotContains($deadlineB->id, $resultA['deadlines']);
        $this->assertSame([$taskA->id], $resultA['tasks']);
        $this->assertNotContains($taskB->id, $resultA['tasks']);
        $this->assertContains($matterA->id, $resultA['matters']);
        $this->assertNotContains($matterB->id, $resultA['matters']);
        $this->assertContains($invoiceA->id, $resultA['invoices']);
        $this->assertNotContains($invoiceB->id, $resultA['invoices']);
        $this->assertContains($paymentA->id, $resultA['payments']);
        $this->assertNotContains($paymentB->id, $resultA['payments']);
        $this->assertContains($runA->id, $resultA['conflict_check_runs']);
        $this->assertNotContains($runB->id, $resultA['conflict_check_runs']);
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
