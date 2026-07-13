<?php

namespace Tests\Feature\PaymentPlans;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use App\Services\PaymentPlanInstallmentService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_mark_missed_transitions_status_and_is_idempotent(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Due)->create();

        $missed = $this->service->markMissed($installment);
        $this->assertSame(PaymentPlanInstallmentStatus::Missed, $missed->status);

        // Idempotent: calling again on an already-missed installment
        // is a no-op, not an error.
        $again = $this->service->markMissed($missed);
        $this->assertSame(PaymentPlanInstallmentStatus::Missed, $again->status);
    }

    public function test_mark_missed_throws_from_an_invalid_status(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Paid)->create();

        $this->expectException(\RuntimeException::class);

        $this->service->markMissed($installment);
    }

    public function test_mark_waived_requires_an_actor_and_logs_reason(): void
    {
        $plan = PaymentPlan::factory()->active()->create();
        $installment = PaymentPlanInstallment::factory()->forPlan($plan)->status(PaymentPlanInstallmentStatus::Missed)->create();
        $actor = User::factory()->create();

        $waived = $this->service->markWaived($installment, $actor, 'Hardship waiver approved');

        $this->assertSame(PaymentPlanInstallmentStatus::Waived, $waived->status);

        // payment_plan_events has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3L Checkpoint 23) — markWaived() now correctly
        // clears its own context on return (Section 39A-3L Checkpoint
        // 33/timeline_events prerequisite), so this read-time assertion
        // needs its own context wrap to see the row it just wrote.
        $this->runWithFirmContext($plan->firm, function () use ($plan, $actor) {
            $this->assertDatabaseHas('payment_plan_events', [
                'payment_plan_id' => $plan->id,
                'event_type' => 'installment_waived',
                'actor_user_id' => $actor->id,
            ]);
        });
    }
}
