<?php

namespace App\Services;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Models\PaymentPlanInstallment;
use App\Models\User;

/**
 * PaymentPlanInstallmentService — installment LIFECYCLE only
 * (missed/waived). Applying a canonical payment to an installment
 * (and the resulting Paid/PartiallyPaid transition) is owned by
 * PaymentApplicationService, not here — keeping "a payment was
 * applied" and "an installment was marked missed/waived" as two
 * separate, single-purpose services.
 */
class PaymentPlanInstallmentService
{
    public function __construct(private TimelineEventRecorder $timeline)
    {
    }

    /**
     * Idempotent: calling this on an installment that is already
     * Missed is a no-op. Actual periodic invocation (a scheduled
     * command checking for installments past due_at) is wired up once
     * Phase 4 provides queue/scheduler infrastructure — this method is
     * the callable capability Phase 3 is responsible for.
     */
    public function markMissed(PaymentPlanInstallment $installment): PaymentPlanInstallment
    {
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

        return (new TenantContextService())->runWithFirmContext($installment->paymentPlan->firm, function () use ($installment) {
            $installment->update(['status' => PaymentPlanInstallmentStatus::Missed]);

            $installment->paymentPlan->events()->create([
                'firm_id' => $installment->paymentPlan->firm_id,
                'event_type' => 'installment_missed',
                'metadata_json' => ['payment_plan_installment_id' => $installment->id, 'sequence' => $installment->sequence],
            ]);

            $this->timeline->record($installment->paymentPlan->firm, 'payment_plan_installment_missed', $installment->paymentPlan);

            return $installment->fresh();
        });
    }

    public function markWaived(PaymentPlanInstallment $installment, User $actor, ?string $reason = null): PaymentPlanInstallment
    {
        if (in_array($installment->status, [PaymentPlanInstallmentStatus::Paid, PaymentPlanInstallmentStatus::Cancelled], true)) {
            throw new \RuntimeException('This installment cannot be waived from its current status.');
        }

        return (new TenantContextService())->runWithFirmContext($installment->paymentPlan->firm, function () use ($installment, $actor, $reason) {
            $installment->update(['status' => PaymentPlanInstallmentStatus::Waived]);

            $installment->paymentPlan->events()->create([
                'firm_id' => $installment->paymentPlan->firm_id,
                'event_type' => 'installment_waived',
                'metadata_json' => ['payment_plan_installment_id' => $installment->id, 'reason' => $reason],
                'actor_user_id' => $actor->id,
            ]);

            $this->timeline->record($installment->paymentPlan->firm, 'payment_plan_installment_waived', $installment->paymentPlan, $actor);

            return $installment->fresh();
        });
    }
}
