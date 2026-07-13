<?php

namespace Tests\Feature\PaymentPlans;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use App\Services\PaymentPlanInstallmentService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentPlanInstallmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentPlanInstallmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentPlanInstallmentService(new TimelineEventRecorder());
    }

    private function tenantContext(): TenantContextService
    {
        return new TenantContextService();
    }

    public function test_mark_missed_transitions_status_and_is_idempotent(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create();

        $missed = $this->service->markMissed($plan, $installment);
        $this->assertSame(PaymentPlanInstallmentStatus::Missed, $missed->status);

        // Idempotent: calling again on an already-missed installment
        // is a no-op, not an error.
        $again = $this->service->markMissed($plan, $missed);
        $this->assertSame(PaymentPlanInstallmentStatus::Missed, $again->status);
    }

    public function test_mark_missed_throws_from_an_invalid_status(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Paid)->create();

        $this->expectException(\RuntimeException::class);

        $this->service->markMissed($plan, $installment);
    }

    public function test_mark_waived_requires_an_actor_and_logs_reason(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();
        $actor = User::factory()->create();

        $waived = $this->service->markWaived($plan, $installment, $actor, 'Hardship waiver approved');

        $this->assertSame(PaymentPlanInstallmentStatus::Waived, $waived->status);

        // payment_plan_events has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3L Checkpoint 23) — markWaived() correctly
        // clears its own context on return, so this read-time assertion
        // needs its own context wrap to see the row it just wrote.
        $this->runWithFirmContext($plan->firm, function () use ($plan, $actor) {
            $this->assertDatabaseHas('payment_plan_events', [
                'payment_plan_id' => $plan->id,
                'event_type' => 'installment_waived',
                'actor_user_id' => $actor->id,
            ]);
        });
    }

    /**
     * The whole point of the installment-lifecycle contract fix: both
     * methods must now be genuinely callable from a context-free
     * baseline, with no hidden precondition on the caller having
     * already established (or a factory having accidentally left
     * behind) ambient tenant context. PaymentPlanFactory's own
     * context-hold create() override leaves DB-session context set to
     * $plan->firm_id after the factory calls above return — this test
     * explicitly clears that away first so the baseline is genuine.
     */
    public function test_mark_missed_succeeds_from_a_genuinely_context_free_baseline(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $missed = $this->service->markMissed($plan, $installment);

        $this->assertSame(PaymentPlanInstallmentStatus::Missed, $missed->status);
        $this->assertNoDatabaseTenantContext('markMissed() must leave no database tenant context behind when none existed before it was called.');
        $this->assertNull($this->tenantContext()->currentFirmId(), 'markMissed() must leave PHP-memory tenant context cleared when none existed before it was called.');
    }

    public function test_mark_waived_succeeds_from_a_genuinely_context_free_baseline(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();
        $actor = User::factory()->create();

        $this->tenantContext()->clearDatabaseTenantContext();
        $this->tenantContext()->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $waived = $this->service->markWaived($plan, $installment, $actor, 'Hardship waiver approved');

        $this->assertSame(PaymentPlanInstallmentStatus::Waived, $waived->status);
        $this->assertNoDatabaseTenantContext('markWaived() must leave no database tenant context behind when none existed before it was called.');
        $this->assertNull($this->tenantContext()->currentFirmId(), 'markWaived() must leave PHP-memory tenant context cleared when none existed before it was called.');
    }

    public function test_mark_missed_rejects_an_installment_belonging_to_a_different_plan(): void
    {
        $planA = PaymentPlan::factory()->active()->create();
        $planB = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($planB)->status(PaymentPlanInstallmentStatus::Due)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This installment does not belong to the supplied payment plan.');

        $this->service->markMissed($planA, $installment);
    }

    public function test_mark_waived_rejects_an_installment_belonging_to_a_different_plan(): void
    {
        $planA = PaymentPlan::factory()->active()->create();
        $planB = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($planB)->status(PaymentPlanInstallmentStatus::Missed)->create();
        $actor = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This installment does not belong to the supplied payment plan.');

        $this->service->markWaived($planA, $installment, $actor, 'Hardship waiver approved');
    }

    /**
     * Proves the mismatch guard runs BEFORE any mutation: the
     * installment's own status, payment_plan_events, and
     * timeline_events are all completely untouched after the rejected
     * call — not merely that an exception was thrown.
     */
    public function test_mark_missed_mismatch_performs_no_mutation_and_writes_no_events(): void
    {
        $planA = PaymentPlan::factory()->active()->create();
        $planB = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($planB)->status(PaymentPlanInstallmentStatus::Due)->create();

        try {
            $this->service->markMissed($planA, $installment);
            $this->fail('Expected a RuntimeException for the plan/installment mismatch.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(
            PaymentPlanInstallmentStatus::Due,
            $installment->fresh()->status,
            'The installment\'s status must be completely unchanged after a rejected mismatch call.'
        );

        $this->runWithFirmContext($planB->firm, function () use ($planA, $planB) {
            $this->assertDatabaseMissing('payment_plan_events', ['payment_plan_id' => $planA->id, 'event_type' => 'installment_missed']);
            $this->assertDatabaseMissing('payment_plan_events', ['payment_plan_id' => $planB->id, 'event_type' => 'installment_missed']);
            $this->assertDatabaseMissing('timeline_events', ['event_type' => 'payment_plan_installment_missed']);
        });
    }

    /**
     * Same mismatch proof for markWaived(), also covering the
     * actor/reason write path.
     */
    public function test_mark_waived_mismatch_performs_no_mutation_and_writes_no_events(): void
    {
        $planA = PaymentPlan::factory()->active()->create();
        $planB = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($planB)->status(PaymentPlanInstallmentStatus::Missed)->create();
        $actor = User::factory()->create();

        try {
            $this->service->markWaived($planA, $installment, $actor, 'Should never be recorded');
            $this->fail('Expected a RuntimeException for the plan/installment mismatch.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(
            PaymentPlanInstallmentStatus::Missed,
            $installment->fresh()->status,
            'The installment\'s status must be completely unchanged after a rejected mismatch call.'
        );

        $this->runWithFirmContext($planB->firm, function () use ($planA, $planB) {
            $this->assertDatabaseMissing('payment_plan_events', ['payment_plan_id' => $planA->id, 'event_type' => 'installment_waived']);
            $this->assertDatabaseMissing('payment_plan_events', ['payment_plan_id' => $planB->id, 'event_type' => 'installment_waived']);
            $this->assertDatabaseMissing('timeline_events', ['event_type' => 'payment_plan_installment_waived']);
        });
    }

    /**
     * Proves the fixed nested-wrap snapshot/restore semantics compose
     * correctly through a real production caller: calling markMissed()
     * from inside an ALREADY-active, legitimate outer firm context
     * must restore that exact outer context afterward, not clear it.
     */
    public function test_mark_missed_nested_under_a_legitimate_outer_context_restores_that_context(): void
    {
        $outerFirm = Firm::factory()->create();
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create();

        $this->runWithFirmContext($outerFirm, function () use ($outerFirm, $plan, $installment) {
            $this->service->markMissed($plan, $installment);

            $this->assertSame(
                $outerFirm->id,
                $this->tenantContext()->currentFirmId(),
                'markMissed() must restore the outer firm context, not clear it, once it returns.'
            );

            $restoredDatabaseValue = DB::selectOne(
                'select current_setting(?, true) as value',
                ['app.current_firm_id']
            )->value;

            $this->assertSame(
                (string) $outerFirm->id,
                $restoredDatabaseValue,
                'markMissed() must restore the outer ambient database session setting, not wipe it.'
            );
        });
    }

    /**
     * Same nested-restoration proof for markWaived().
     */
    public function test_mark_waived_nested_under_a_legitimate_outer_context_restores_that_context(): void
    {
        $outerFirm = Firm::factory()->create();
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();
        $actor = User::factory()->create();

        $this->runWithFirmContext($outerFirm, function () use ($outerFirm, $plan, $installment, $actor) {
            $this->service->markWaived($plan, $installment, $actor, 'Hardship waiver approved');

            $this->assertSame(
                $outerFirm->id,
                $this->tenantContext()->currentFirmId(),
                'markWaived() must restore the outer firm context, not clear it, once it returns.'
            );

            $restoredDatabaseValue = DB::selectOne(
                'select current_setting(?, true) as value',
                ['app.current_firm_id']
            )->value;

            $this->assertSame(
                (string) $outerFirm->id,
                $restoredDatabaseValue,
                'markWaived() must restore the outer ambient database session setting, not wipe it.'
            );
        });
    }
}
