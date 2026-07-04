<?php

namespace App\Services;

use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PaymentPlanService — the only place a PaymentPlan's status
 * transitions (project rule: "payment plans are schedules only, never
 * parallel ledgers"). total_cents is always the sum of installment
 * amounts, recomputed here, never a hand-set running balance.
 * Renegotiation creates a NEW plan row (supersedes_payment_plan_id)
 * rather than mutating the old plan's installments in place, per the
 * PDF: "New plan version supersedes; prior installments retain
 * history... dunning pauses during renegotiation" — dunning pausing is
 * automatic because PaymentPlanDunningService only ever acts on a plan
 * whose status is Active, and the old plan's status becomes
 * Renegotiated the instant this happens.
 */
class PaymentPlanService
{
    public function __construct(private TimelineEventRecorder $timeline)
    {
    }

    /**
     * @param  array<int, array{amount_cents:int, due_at:\DateTimeInterface}>  $installments
     */
    public function create(
        Firm $firm,
        Client $client,
        array $installments,
        ?Matter $matter = null,
        ?Invoice $invoice = null,
        ?User $createdBy = null,
    ): PaymentPlan {
        if (empty($installments)) {
            throw new \InvalidArgumentException('At least one installment is required.');
        }

        return DB::transaction(function () use ($firm, $client, $matter, $invoice, $installments, $createdBy) {
            $plan = PaymentPlan::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'invoice_id' => $invoice?->id,
                'status' => PaymentPlanStatus::Draft,
                'total_cents' => 0,
                'installment_count' => 0,
                'created_by' => $createdBy?->id,
            ]);

            $this->replaceInstallments($plan, $installments);

            $this->logEvent($plan, 'created', $createdBy);

            return $plan->fresh('installments');
        });
    }

    /**
     * "Allow plan editing before activation" (project rule). Replaces
     * the full installment set; only permitted while status is Draft.
     *
     * @param  array<int, array{amount_cents:int, due_at:\DateTimeInterface}>  $installments
     */
    public function edit(PaymentPlan $plan, array $installments): PaymentPlan
    {
        if (! $plan->isEditable()) {
            throw new \RuntimeException('Only a draft payment plan can be edited.');
        }

        if (empty($installments)) {
            throw new \InvalidArgumentException('At least one installment is required.');
        }

        return DB::transaction(function () use ($plan, $installments) {
            $plan->installments()->delete();
            $this->replaceInstallments($plan, $installments);

            return $plan->fresh('installments');
        });
    }

    public function activate(PaymentPlan $plan): PaymentPlan
    {
        if ($plan->status !== PaymentPlanStatus::Draft) {
            throw new \RuntimeException('Only a draft payment plan can be activated.');
        }

        $plan->update([
            'status' => PaymentPlanStatus::Active,
            'activated_at' => now(),
        ]);

        $this->logEvent($plan, 'activated', null);

        return $plan->fresh();
    }

    /**
     * Creates a new plan version superseding $plan. The old plan
     * transitions to Renegotiated (which is what makes dunning pause
     * on it — PaymentPlanDunningService only acts on Active plans).
     *
     * @param  array<int, array{amount_cents:int, due_at:\DateTimeInterface}>  $newInstallments
     */
    public function renegotiate(PaymentPlan $plan, array $newInstallments, ?User $actor = null): PaymentPlan
    {
        if ($plan->status !== PaymentPlanStatus::Active) {
            throw new \RuntimeException('Only an active payment plan can be renegotiated.');
        }

        return DB::transaction(function () use ($plan, $newInstallments, $actor) {
            $newPlan = PaymentPlan::create([
                'firm_id' => $plan->firm_id,
                'client_id' => $plan->client_id,
                'matter_id' => $plan->matter_id,
                'invoice_id' => $plan->invoice_id,
                'status' => PaymentPlanStatus::Draft,
                'total_cents' => 0,
                'installment_count' => 0,
                'supersedes_payment_plan_id' => $plan->id,
                'created_by' => $actor?->id,
            ]);

            $this->replaceInstallments($newPlan, $newInstallments);

            $newPlan->update([
                'status' => PaymentPlanStatus::Active,
                'activated_at' => now(),
            ]);

            $plan->update([
                'status' => PaymentPlanStatus::Renegotiated,
                'renegotiated_at' => now(),
            ]);

            $this->logEvent($plan, 'renegotiated', $actor, ['superseded_by_payment_plan_id' => $newPlan->id]);
            $this->logEvent($newPlan, 'created_from_renegotiation', $actor, ['supersedes_payment_plan_id' => $plan->id]);

            return $newPlan->fresh('installments');
        });
    }

    public function cancel(PaymentPlan $plan, ?User $actor = null, ?string $reason = null): PaymentPlan
    {
        if (in_array($plan->status, [PaymentPlanStatus::Completed, PaymentPlanStatus::Cancelled], true)) {
            throw new \RuntimeException('This payment plan cannot be cancelled from its current status.');
        }

        $plan->update(['status' => PaymentPlanStatus::Cancelled, 'cancelled_at' => now()]);

        $this->logEvent($plan, 'cancelled', $actor, ['reason' => $reason]);

        return $plan->fresh();
    }

    /**
     * Explicit, human-triggered only — never called automatically
     * purely from a missed-installment count. Per the PDF edge-case
     * catalog: "plan may move to defaulted only under firm-confirmed
     * rules; no automatic legal-data consequences."
     */
    public function markDefaulted(PaymentPlan $plan, User $actor, string $reason): PaymentPlan
    {
        if ($plan->status !== PaymentPlanStatus::Active) {
            throw new \RuntimeException('Only an active payment plan can be marked defaulted.');
        }

        $plan->update(['status' => PaymentPlanStatus::Defaulted, 'defaulted_at' => now()]);

        $this->logEvent($plan, 'defaulted', $actor, ['reason' => $reason]);

        return $plan->fresh();
    }

    /**
     * Called by PaymentApplicationService after applying a payment to
     * an installment — completes the plan only when every installment
     * is fully paid.
     */
    public function markCompletedIfAllInstallmentsPaid(PaymentPlan $plan): void
    {
        if ($plan->status !== PaymentPlanStatus::Active) {
            return;
        }

        $allPaid = $plan->installments()
            ->where('status', '!=', PaymentPlanInstallmentStatus::Paid->value)
            ->where('status', '!=', PaymentPlanInstallmentStatus::Waived->value)
            ->where('status', '!=', PaymentPlanInstallmentStatus::Cancelled->value)
            ->doesntExist();

        if (! $allPaid) {
            return;
        }

        $plan->update(['status' => PaymentPlanStatus::Completed, 'completed_at' => now()]);

        $this->logEvent($plan, 'completed', null);
    }

    /**
     * @param  array<int, array{amount_cents:int, due_at:\DateTimeInterface}>  $installments
     */
    private function replaceInstallments(PaymentPlan $plan, array $installments): void
    {
        $totalCents = 0;
        $sequence = 1;

        foreach ($installments as $installment) {
            PaymentPlanInstallment::create([
                'payment_plan_id' => $plan->id,
                'sequence' => $sequence++,
                'amount_cents' => $installment['amount_cents'],
                'due_at' => $installment['due_at'],
                'status' => PaymentPlanInstallmentStatus::Scheduled,
            ]);

            $totalCents += $installment['amount_cents'];
        }

        $plan->update([
            'total_cents' => $totalCents,
            'installment_count' => count($installments),
        ]);
    }

    private function logEvent(PaymentPlan $plan, string $eventType, ?User $actor, array $metadata = []): void
    {
        $plan->events()->create([
            'firm_id' => $plan->firm_id,
            'event_type' => $eventType,
            'metadata_json' => $metadata,
            'actor_user_id' => $actor?->id,
        ]);

        $this->timeline->record($plan->firm, "payment_plan_{$eventType}", $plan, $actor, $metadata);
    }
}
