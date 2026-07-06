<?php

namespace App\Services;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\PaymentBlockedException;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\ManualPaymentRecord;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ManualPaymentService — the ONLY path that creates a manual payment.
 * A canonical Payment row is always created first (status=Initiated)
 * within one transaction; PaymentClassificationService then decides
 * and records the classification in place. A ManualPaymentRecord is
 * created ONLY when the payment is accepted — it links to the
 * canonical Payment and is never a second ledger (project rule 3).
 *
 * Idempotency (project rule 7): submit() is idempotent on
 * (firm_id, idempotencyKey) — a resubmission with the same key returns
 * (or replays the exception of) the original result rather than
 * creating a second row. The partial unique index on
 * payments(firm_id, idempotency_key) is the concurrency-safe backstop
 * if two requests race past the initial check simultaneously.
 *
 * Phase 14b addition: fires payment.recorded exactly once, ONLY inside
 * the $result->accepted branch (never for a blocked payment, and never
 * on the idempotent-replay early return above — that return happens
 * before this branch is ever reached, so a repeated idempotency key
 * cannot double-fire), registered via DB::afterCommit() from inside
 * the existing DB::transaction().
 */
class ManualPaymentService
{
    public function __construct(
        private PaymentClassificationService $classification,
        private PaymentApplicationService $application,
        private TimelineEventRecorder $timeline,
    ) {
    }

    public function submit(
        Firm $firm,
        Client $client,
        int $amountCents,
        ManualPaymentMethod $method,
        PaymentClassification $requestedClassification,
        string $idempotencyKey,
        ?Matter $matter = null,
        ?Invoice $invoice = null,
        ?PaymentPlanInstallment $installment = null,
        ?User $recordedBy = null,
        ?string $externalReference = null,
        ?string $methodReference = null,
        ?string $notes = null,
    ): Payment {
        $payment = DB::transaction(function () use (
            $firm, $client, $matter, $invoice, $installment, $amountCents, $method,
            $requestedClassification, $idempotencyKey, $recordedBy, $externalReference,
            $methodReference, $notes,
        ) {
            $existing = Payment::query()
                ->where('firm_id', $firm->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                // Idempotent replay: same key always returns the
                // original outcome, never a second row, and never
                // fires a second payment.recorded webhook event.
                return $existing;
            }

            $payment = Payment::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'invoice_id' => $invoice?->id,
                'payment_plan_installment_id' => $installment?->id,
                'amount_cents' => $amountCents,
                'payment_method' => $method,
                'payment_classification' => $requestedClassification,
                'status' => PaymentStatus::Initiated,
                'external_reference' => $externalReference,
                'idempotency_key' => $idempotencyKey,
                'recorded_by' => $recordedBy?->id,
            ]);

            $result = $this->classification->classify($firm, $requestedClassification);
            $this->classification->recordDecision($payment, $requestedClassification, $result, $recordedBy);
            $payment = $payment->fresh();

            if ($result->accepted) {
                ManualPaymentRecord::create([
                    'payment_id' => $payment->id,
                    'received_by' => $recordedBy?->id,
                    'received_at' => now(),
                    'method_reference' => $methodReference,
                    'notes' => $notes,
                ]);

                if ($installment) {
                    $this->application->applyToInstallment($payment, $installment);
                } elseif ($invoice) {
                    $this->application->applyToInvoice($payment, $invoice->fresh());
                }

                $this->timeline->record($firm, 'payment_recorded', $payment, $recordedBy, [
                    'payment_id' => $payment->id,
                    'amount_cents' => $amountCents,
                ]);

                $payment = $payment->fresh();

                DB::afterCommit(function () use ($firm, $payment) {
                    try {
                        app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::PaymentRecorded, $payment);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            } else {
                $this->timeline->record($firm, 'payment_blocked', $payment, $recordedBy, [
                    'payment_id' => $payment->id,
                    'reason' => $result->rejectionReason,
                ]);
            }

            return $payment->fresh();
        });

        if (! $payment->isAcceptedOperatingPayment()) {
            throw new PaymentBlockedException($payment, $payment->rejection_reason ?? 'Payment was blocked.');
        }

        return $payment;
    }
}
