<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\PaymentPlanStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\DocumentUploadPolicyService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\PaymentPlanService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaymentPlansForceRlsActivationTest — Section 39A-3L, Checkpoint 22.
 * Proves the fortieth staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php)
 * is permanently active for payment_plans and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table (including
 * time_entries, forced one checkpoint earlier) remains forced
 * simultaneously, and — the central finding of this checkpoint — that
 * PaymentPlanService's five status-transition methods (create, edit,
 * activate, renegotiate, cancel, markDefaulted) genuinely persist their
 * writes to the database even when called with no ambient tenant
 * context, and that a bare PaymentPlan::factory()->create() no longer
 * risks producing a plan whose client belongs to a completely
 * unrelated firm (the specific bug the factory fix in this checkpoint
 * addresses — client_id is NOT NULL on this table, so this bug would
 * have affected even the bare default factory path).
 *
 * payment_plans carries THREE other tenant-owned relations of its own
 * — client_id (NOT NULL), matter_id, and invoice_id (both nullable) —
 * the same "second, independently-resolved tenant-owned relation"
 * shape as document_chase_events' document_request_item_id (Checkpoint
 * 17), time_tracking_sessions' matter_id/client_id (Checkpoint 20), and
 * time_entries' matter_id/client_id/time_tracking_session_id
 * (Checkpoint 21). This file proves the same honest boundary: RLS only
 * ever validates a row's OWN firm_id, never a related row's owning
 * firm, so a raw insert whose firm_id matches the active context but
 * whose client_id points at a CLIENT belonging to a different firm is
 * NOT blocked by RLS. This is documented here as a residual
 * DATABASE-CONSTRAINT gap, never asserted as something RLS itself
 * closes.
 *
 * PaymentPlanService::markCompletedIfAllInstallmentsPaid() is
 * deliberately NOT self-wrapped in this checkpoint's production fix —
 * see the method's own docblock and the migration's own docblock for
 * why (its only production caller, PaymentApplicationService::
 * applyToInstallment(), is itself only ever invoked from inside
 * ManualPaymentService::submit()'s own whole-method wrap, established
 * at Checkpoint 39A-3H). This file does not re-prove that call chain
 * (already covered by ManualPaymentServiceTest and
 * PaymentApplicationServiceTest, both of which pass unmodified — see
 * this checkpoint's own report) but does prove every OTHER
 * status-transition method in PaymentPlanService self-wraps correctly.
 *
 * PaymentPlanDunningService::checkAndLog() is a second, separate,
 * already-documented, deliberately-unfixed gap (see
 * PaymentPlanDunningServiceTest, unchanged since Checkpoint 11) — it
 * receives a PaymentPlanInstallment with no firm_id and no trusted firm
 * parameter, so it cannot safely self-wrap without first reading the
 * now-forced payment_plans table with no context (a genuine circular
 * dependency). Not re-proven here; that test file's own docblocks
 * already carry the record.
 */
class PaymentPlansForceRlsActivationTest extends TestCase
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
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries',
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

    public function test_payment_plans_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'payment_plans'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_payment_plans_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payment_plans'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'payment_plans must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty tables (the thirty-nine previously forced plus
     * payment_plans) must be FORCE-enabled among ALL prepared tables —
     * no more, no less.
     */
    public function test_exactly_forty_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items']);
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.

        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(50, count($actuallyForced), 'Exactly forty prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 22 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items']);
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
             where polrelid = 'payment_plans'::regclass"
        );

        $this->assertNotNull($policy, 'The payment_plans tenant isolation policy must still exist.');
        $this->assertSame('payment_plans_tenant_isolation', $policy->polname);
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_payment_plans(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, PaymentPlan::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_plans(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_plans')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'status' => PaymentPlanStatus::Draft->value,
            'total_cents' => 0,
            'currency' => 'usd',
            'installment_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_payment_plan(): void
    {
        $firmA = Firm::factory()->create();
        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentPlan::query()->pluck('id')->all(),
        );

        $this->assertSame([$planA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_plan(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentPlan::query()->pluck('id')->all(),
        );

        $this->assertNotContains($planB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $client) {
            return DB::table('payment_plans')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'status' => PaymentPlanStatus::Draft->value,
                'total_cents' => 0,
                'currency' => 'usd',
                'installment_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_payment_plan_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientB) {
            DB::table('payment_plans')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'client_id' => $clientB->id,
                'status' => PaymentPlanStatus::Draft->value,
                'total_cents' => 0,
                'currency' => 'usd',
                'installment_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_payment_plan(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create(['status' => PaymentPlanStatus::Draft]));

        $affected = $this->runWithFirmContext($firmA, function () use ($planB) {
            return DB::table('payment_plans')->where('id', $planB->id)->update(['status' => PaymentPlanStatus::Active->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s payment_plans row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentPlan::query()->find($planB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(PaymentPlanStatus::Draft, $reReadAsFirmB->status);
    }

    public function test_firm_a_context_cannot_delete_firm_b_payment_plan(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($planB) {
            DB::table('payment_plans')->where('id', $planB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentPlan::query()->find($planB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s payment_plans row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_payment_plan_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $planB) {
            return DB::table('payment_plans')->where('id', $planB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s payment_plans row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentPlan::query()->find($planB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates payment_plans.firm_id, never client_id's OWN owning
     * firm — a raw insert whose firm_id matches the active context
     * still succeeds even when client_id points at a Client belonging
     * to a COMPLETELY DIFFERENT firm. This is a documented residual
     * DATABASE-CONSTRAINT gap, never to be described as blocked by RLS.
     */
    public function test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignClient) {
            return DB::table('payment_plans')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'client_id' => $foreignClient->id,
                'status' => PaymentPlanStatus::Draft->value,
                'total_cents' => 0,
                'currency' => 'usd',
                'installment_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a client_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare PaymentPlan::factory()->create() must
     * succeed even from outside any already-active tenant context (the
     * factory's context-hold create() override), AND — the specific bug
     * this checkpoint's factory fix addresses — the resulting client
     * must belong to the SAME firm as the plan, never an independently
     * resolved, unrelated firm. client_id is NOT NULL on this table, so
     * this matters even for the bare default path.
     */
    public function test_payment_plan_factory_default_creation_produces_a_client_belonging_to_the_same_firm(): void
    {
        $plan = PaymentPlan::factory()->create();

        $this->assertNotNull($plan->id);
        $this->assertNotNull($plan->firm_id);
        $this->assertNotNull($plan->client_id);

        $client = $this->runWithFirmContext(
            $plan->firm_id,
            fn () => Client::query()->find($plan->client_id),
        );

        $this->assertNotNull($client, 'The bare-factory-created client must be visible under the plan\'s own firm context.');
        $this->assertSame(
            $plan->firm_id,
            $client->firm_id,
            'A bare PaymentPlan::factory()->create() must never produce a plan whose client belongs to a different, independently-resolved firm — the exact bug this checkpoint\'s factory fix addresses.'
        );

        $persisted = $this->runWithFirmContext(
            $plan->firm_id,
            fn () => PaymentPlan::query()->find($plan->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($plan->firm_id, $persisted->firm_id);
    }

    public function test_payment_plan_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $plan->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlan::query()->find($plan->id),
        );

        $this->assertNotNull($persisted);

        $client = $this->runWithFirmContext($firm, fn () => Client::query()->find($persisted->client_id));
        $this->assertNotNull($client, 'forFirm() must produce a client that is genuinely visible under the same firm\'s context.');
        $this->assertSame($firm->id, $client->firm_id);
    }

    /**
     * Explicit related-model factory state correctness: the active()
     * state must correctly persist a coherent Active plan under FORCE
     * RLS — status and activated_at both agree once genuinely read back
     * from the database.
     */
    public function test_payment_plan_factory_active_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->active()->create());

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlan::query()->find($plan->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(PaymentPlanStatus::Active, $persisted->status);
        $this->assertNotNull($persisted->activated_at);
    }

    /**
     * forClient() state correctness: the plan must be tied to the
     * given client's own firm, not an independently resolved one.
     */
    public function test_payment_plan_factory_for_client_state_ties_firm_id_to_the_clients_own_firm(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forClient($client)->create());

        $this->assertSame($firm->id, $plan->firm_id);
        $this->assertSame($client->id, $plan->client_id);
    }

    /**
     * Multiple plans per firm is a supported state — a second bare
     * create() for the same firm must succeed, not throw.
     */
    public function test_a_firm_can_have_multiple_payment_plans_simultaneously(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $count = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->count());

        $this->assertSame(2, $count, 'payment_plans has no unique-per-firm constraint — a second plan for the same firm must be a supported state.');
    }

    // ---------------------------------------------------------------
    // (a) PaymentPlanService self-wrap regression proofs — the central
    // finding of this checkpoint. Each proves the corresponding method
    // genuinely persists to the database even when called with no
    // ambient tenant context established beforehand.
    // ---------------------------------------------------------------

    public function test_create_genuinely_persists_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentPlanService(new TimelineEventRecorder());
        $plan = $service->create($firm, $client, [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
        ]);

        $this->assertNotNull($plan->id);
        $this->assertNoDatabaseTenantContext('create() must clear its own internal context wrap before returning.');

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlan::query()->find($plan->id),
        );

        $this->assertNotNull($persisted, 'create() must genuinely persist to the database, not just return an in-memory object.');
        $this->assertSame($firm->id, $persisted->firm_id);
        $this->assertSame(10000, $persisted->total_cents);
    }

    public function test_edit_activate_renegotiate_and_cancel_genuinely_persist_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $service = new PaymentPlanService(new TimelineEventRecorder());

        // --- create() (context established, then cleared by the
        // service itself) ---
        $plan = $this->runWithFirmContext($firm, fn () => $service->create($firm, $client, [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
        ]));
        $this->assertNoDatabaseTenantContext();

        // --- edit() with no ambient context ---
        $edited = $service->edit($plan, [
            ['amount_cents' => 15000, 'due_at' => now()->addMonth()],
        ]);
        $this->assertSame(15000, $edited->total_cents);
        $this->assertNoDatabaseTenantContext('edit() must clear its own internal context wrap before returning.');

        $persistedEdited = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(15000, $persistedEdited->total_cents, 'edit() must genuinely persist to the database, not just return an in-memory object.');

        // --- activate() with no ambient context ---
        $activated = $service->activate($edited);
        $this->assertSame(PaymentPlanStatus::Active, $activated->status);
        $this->assertNoDatabaseTenantContext('activate() must clear its own internal context wrap before returning.');

        $persistedActivated = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(PaymentPlanStatus::Active, $persistedActivated->status, 'activate() must genuinely persist to the database, not just return an in-memory object.');

        // --- renegotiate() with no ambient context ---
        $newPlan = $service->renegotiate($persistedActivated, [
            ['amount_cents' => 7500, 'due_at' => now()->addMonth()],
        ]);
        $this->assertSame(PaymentPlanStatus::Active, $newPlan->status);
        $this->assertNoDatabaseTenantContext('renegotiate() must clear its own internal context wrap before returning.');

        $persistedOldPlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $persistedNewPlan = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($newPlan->id));
        $this->assertSame(PaymentPlanStatus::Renegotiated, $persistedOldPlan->status, 'renegotiate() must genuinely mark the old plan Renegotiated in the database.');
        $this->assertSame(PaymentPlanStatus::Active, $persistedNewPlan->status, 'renegotiate() must genuinely persist the new plan as Active in the database.');
        $this->assertSame($plan->id, $persistedNewPlan->supersedes_payment_plan_id);

        // --- cancel() with no ambient context (against a fresh,
        // independent Draft plan) ---
        $planToCancel = $this->runWithFirmContext($firm, fn () => $service->create($firm, $client, [
            ['amount_cents' => 5000, 'due_at' => now()->addMonth()],
        ]));
        $this->assertNoDatabaseTenantContext();

        $cancelled = $service->cancel($planToCancel, reason: 'Client requested cancellation');
        $this->assertSame(PaymentPlanStatus::Cancelled, $cancelled->status);
        $this->assertNoDatabaseTenantContext('cancel() must clear its own internal context wrap before returning.');

        $persistedCancelled = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($planToCancel->id));
        $this->assertSame(PaymentPlanStatus::Cancelled, $persistedCancelled->status, 'cancel() must genuinely persist to the database, not just return an in-memory object.');
    }

    public function test_mark_defaulted_genuinely_persists_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $actor = User::factory()->create();
        $service = new PaymentPlanService(new TimelineEventRecorder());

        $plan = $this->runWithFirmContext($firm, function () use ($service, $firm, $client) {
            $created = $service->create($firm, $client, [
                ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
            ]);

            return $service->activate($created);
        });
        $this->assertNoDatabaseTenantContext();

        $defaulted = $service->markDefaulted($plan, $actor, 'Client unresponsive after repeated misses');
        $this->assertSame(PaymentPlanStatus::Defaulted, $defaulted->status);
        $this->assertNoDatabaseTenantContext('markDefaulted() must clear its own internal context wrap before returning.');

        $persisted = $this->runWithFirmContext($firm, fn () => PaymentPlan::query()->find($plan->id));
        $this->assertSame(PaymentPlanStatus::Defaulted, $persisted->status, 'markDefaulted() must genuinely persist to the database, not just return an in-memory object.');
    }

    // ---------------------------------------------------------------
    // (b) Import-apply regression proof
    // ---------------------------------------------------------------

    public function test_import_apply_creates_a_correctly_owned_payment_plan_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $auditService = new ImportAuditService();
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $applyService = new ImportApplyService($documentSafetyService, $auditService);

        $batch = $batchService->create($firm, ImportEntityType::PaymentPlan, ImportSourceType::CsvUpload);
        $batchService->stageRows($batch, [[
            'client_id' => $client->id,
            'total_cents' => 30000,
            'installment_count' => 3,
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $applyService->confirmBatch($batch->fresh());

        // No ambient context established before apply() — the whole
        // point is proving ImportApplyService's own internal wrap (the
        // one-line fix around the PaymentPlan match arm) makes the
        // write succeed transparently.
        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $applied = $applyService->apply($batch->fresh());

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);

        $row = $batch->rows()->first();
        $this->assertSame(
            ImportRowStatus::Applied,
            $row->status,
            'The row must genuinely apply, not silently Fail, now that payment_plans is FORCE RLS protected.'
        );
        $this->assertNotNull($row->applied_record_id);

        $persistedPlan = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlan::query()->find($row->applied_record_id),
        );

        $this->assertNotNull($persistedPlan, 'A real, correctly-owned payment_plans row must be visible under the firm\'s own context afterward.');
        $this->assertSame($firm->id, $persistedPlan->firm_id);
        $this->assertSame(30000, $persistedPlan->total_cents);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

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
     * Thirty-nine previously forced tables plus payment_plans must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses clients as the companion
     * table.
     */
    public function test_payment_plans_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'payment_plans' => PaymentPlan::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$planA->id], $resultA['payment_plans']);
        $this->assertNotContains($planB->id, $resultA['payment_plans']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the payment_plans migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * policy still present, but NOT forced — never drop the policy or
     * disable RLS itself. Also proves rollback affects ONLY this one
     * table — every other previously-forced table must be untouched.
     */
    public function test_payment_plans_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_plans'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while payment_plans is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'payment_plans'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payment_plans'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
