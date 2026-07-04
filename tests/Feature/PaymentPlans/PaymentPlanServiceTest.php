<?php

namespace Tests\Feature\PaymentPlans;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\User;
use App\Services\PaymentPlanService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentPlanService(new TimelineEventRecorder());
    }

    private function threeInstallments(): array
    {
        return [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
            ['amount_cents' => 10000, 'due_at' => now()->addMonths(2)],
            ['amount_cents' => 10000, 'due_at' => now()->addMonths(3)],
        ];
    }

    public function test_create_builds_the_installment_schedule_and_totals(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();

        $plan = $this->service->create($firm, $client, $this->threeInstallments());

        $this->assertSame(PaymentPlanStatus::Draft, $plan->status);
        $this->assertSame(30000, $plan->total_cents);
        $this->assertSame(3, $plan->installment_count);
        $this->assertSame(3, $plan->installments()->count());
        $this->assertDatabaseHas('payment_plan_events', ['payment_plan_id' => $plan->id, 'event_type' => 'created']);
    }

    public function test_edit_is_only_allowed_before_activation(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());

        $edited = $this->service->edit($plan, [['amount_cents' => 15000, 'due_at' => now()->addMonth()]]);
        $this->assertSame(15000, $edited->total_cents);
        $this->assertSame(1, $edited->installments()->count());

        $this->service->activate($edited);

        $this->expectException(\RuntimeException::class);
        $this->service->edit($edited->fresh(), $this->threeInstallments());
    }

    public function test_activate_locks_the_schedule(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());

        $activated = $this->service->activate($plan);

        $this->assertSame(PaymentPlanStatus::Active, $activated->status);
        $this->assertNotNull($activated->activated_at);
    }

    public function test_renegotiate_creates_a_new_plan_and_marks_the_old_one_renegotiated(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());
        $this->service->activate($plan);

        $newPlan = $this->service->renegotiate($plan->fresh(), [
            ['amount_cents' => 5000, 'due_at' => now()->addMonth()],
            ['amount_cents' => 5000, 'due_at' => now()->addMonths(2)],
        ]);

        $this->assertSame(PaymentPlanStatus::Renegotiated, $plan->fresh()->status);
        $this->assertSame(PaymentPlanStatus::Active, $newPlan->status);
        $this->assertSame($plan->id, $newPlan->supersedes_payment_plan_id);
        $this->assertSame(10000, $newPlan->total_cents);

        // Prior installments retain history — the old plan's
        // installments are untouched, not deleted or rewritten.
        $this->assertSame(3, $plan->fresh()->installments()->count());
    }

    public function test_renegotiate_throws_unless_plan_is_active(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments()); // still draft

        $this->expectException(\RuntimeException::class);

        $this->service->renegotiate($plan, $this->threeInstallments());
    }

    public function test_cancel_transitions_status(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());

        $cancelled = $this->service->cancel($plan, reason: 'Client requested cancellation');

        $this->assertSame(PaymentPlanStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_mark_defaulted_requires_explicit_actor_and_reason_and_active_status(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $actor = User::factory()->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());

        $this->expectException(\RuntimeException::class);
        $this->service->markDefaulted($plan, $actor, 'Client unresponsive'); // still draft, not active
    }

    public function test_mark_defaulted_succeeds_on_an_active_plan(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $actor = User::factory()->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());
        $this->service->activate($plan);

        $defaulted = $this->service->markDefaulted($plan->fresh(), $actor, 'Client unresponsive after repeated misses');

        $this->assertSame(PaymentPlanStatus::Defaulted, $defaulted->status);
        $this->assertDatabaseHas('payment_plan_events', [
            'payment_plan_id' => $plan->id,
            'event_type' => 'defaulted',
            'actor_user_id' => $actor->id,
        ]);
    }

    public function test_mark_completed_if_all_installments_paid_completes_the_plan(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
        ]);
        $this->service->activate($plan);

        $plan->installments()->first()->update([
            'status' => PaymentPlanInstallmentStatus::Paid,
            'paid_amount_cents' => 10000,
        ]);

        $this->service->markCompletedIfAllInstallmentsPaid($plan->fresh());

        $this->assertSame(PaymentPlanStatus::Completed, $plan->fresh()->status);
    }

    public function test_mark_completed_if_all_installments_paid_is_a_no_op_when_one_is_still_open(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        $plan = $this->service->create($firm, $client, $this->threeInstallments());
        $this->service->activate($plan);

        $plan->installments()->first()->update([
            'status' => PaymentPlanInstallmentStatus::Paid,
            'paid_amount_cents' => 10000,
        ]);

        $this->service->markCompletedIfAllInstallmentsPaid($plan->fresh());

        $this->assertSame(PaymentPlanStatus::Active, $plan->fresh()->status);
    }
}
