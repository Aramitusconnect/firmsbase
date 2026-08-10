<?php

namespace App\Services;

use App\Enums\DomainEventType;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use App\Services\Automation\DomainEventRecorderService;

/**
 * PaymentPlanInstallmentService — installment LIFECYCLE only
 * (missed/waived). Applying a canonical payment to an installment
 * (and the resulting Paid/PartiallyPaid transition) is owned by
 * PaymentApplicationService, not here — keeping "a payment was
 * applied" and "an installment was marked missed/waived" as two
 * separate, single-purpose services.
 *
 * Both methods require an explicit PaymentPlan tenant anchor
 * (Section 39A-3L installment-lifecycle contract fix). Earlier, both
 * methods resolved their firm via $installment->paymentPlan->firm —
 * a lazy relation load evaluated as runWithFirmContext()'s own
 * argument, BEFORE that wrap's context existed. Since payment_plans
 * carries FORCE ROW LEVEL SECURITY, that read only ever worked by
 * accident, whenever some caller or factory happened to leave an
 * ambient firm context lying around — an undocumented, fragile
 * precondition, not a real contract. Requiring $plan directly and
 * anchoring on $plan->firm_id (a plain scalar already in memory, not
 * a fresh RLS-protected query) makes both methods genuinely
 * context-free-callable, with no hidden precondition on the caller.
 */
class PaymentPlanInstallmentService
{
    public function __construct(
        private TimelineEventRecorder $timeline,
        private DomainEventRecorderService $domainEvents,
    ) {}

    /**
     * Idempotent: calling this on an installment that is already
     * Missed is a no-op. Actual periodic invocation (a scheduled
     * command checking for installments past due_at) is wired up once
     * Phase 4 provides queue/scheduler infrastructure — this method is
     * the callable capability Phase 3 is responsible for.
     */
    public function markMissed(PaymentPlan $plan, PaymentPlanInstallment $installment): PaymentPlanInstallment
    {
        if ($installment->payment_plan_id !== $plan->id) {
            throw new \RuntimeException('This installment does not belong to the supplied payment plan.');
        }

        if ($installment->status === PaymentPlanInstallmentStatus::Missed) {
            return $installment;
        }

        if (! in_array($installment->status, [
            PaymentPlanInstallmentStatus::Scheduled,
            PaymentPlanInstallmentStatus::Due,
            PaymentPlanInstallmentStatus::PartiallyPaid,
        ], true)) {
            throw new \RuntimeException('This installment cannot be marked missed from its current status.');
        }

        return (new TenantContextService)->runWithFirmContext($plan->firm_id, function () use ($plan, $installment) {
            $installment->update(['status' => PaymentPlanInstallmentStatus::Missed]);

            $plan->events()->create([
                'firm_id' => $plan->firm_id,
                'event_type' => 'installment_missed',
                'metadata_json' => ['payment_plan_installment_id' => $installment->id, 'sequence' => $installment->sequence],
            ]);

            $this->timeline->record($plan->firm, 'payment_plan_installment_missed', $plan);

            $this->domainEvents->record($plan->firm, DomainEventType::PaymentPlanInstallmentMissed, [
                'installment' => [
                    'id' => $installment->id,
                    'amount_cents' => $installment->amount_cents,
                    'due_at' => optional($installment->due_at)->toIso8601String(),
                    'sequence' => $installment->sequence,
                ],
                'payment_plan' => [
                    'id' => $plan->id,
                    'client_id' => $plan->client_id,
                    'matter_id' => $plan->matter_id,
                ],
            ], subject: $installment);

            return $installment->fresh();
        });
    }

    public function markWaived(PaymentPlan $plan, PaymentPlanInstallment $installment, User $actor, ?string $reason = null): PaymentPlanInstallment
    {
        if ($installment->payment_plan_id !== $plan->id) {
            throw new \RuntimeException('This installment does not belong to the supplied payment plan.');
        }

        if (in_array($installment->status, [PaymentPlanInstallmentStatus::Paid, PaymentPlanInstallmentStatus::Cancelled], true)) {
            throw new \RuntimeException('This installment cannot be waived from its current status.');
        }

        return (new TenantContextService)->runWithFirmContext($plan->firm_id, function () use ($plan, $installment, $actor, $reason) {
            $installment->update(['status' => PaymentPlanInstallmentStatus::Waived]);

            $plan->events()->create([
                'firm_id' => $plan->firm_id,
                'event_type' => 'installment_waived',
                'metadata_json' => ['payment_plan_installment_id' => $installment->id, 'reason' => $reason],
                'actor_user_id' => $actor->id,
            ]);

            $this->timeline->record($plan->firm, 'payment_plan_installment_waived', $plan, $actor);

            return $installment->fresh();
        });
    }
}
