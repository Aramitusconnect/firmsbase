<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\PaymentDestinationClass;
use App\Enums\PaymentIntentStatus;
use App\Exceptions\Pay\PaymentIntentNotExecutableException;
use App\Models\Firm;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentAllocation;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * PaymentIntentService — FirmsVault Pay Gate A2 (v1.4 §16-§19). The
 * only writer of payment_intents and payment_intent_allocations.
 *
 * ============================================================
 * THE §18 SEPARATION THIS CLASS EXISTS TO ENFORCE
 * ============================================================
 * Two different questions, deliberately never conflated:
 *
 *   ALLOCATION COMPLETENESS
 *       SUM(all allocations) == intent.amount_cents
 *       Asserted at freeze(). An intent whose allocations do not add up
 *       is malformed and can never be frozen.
 *
 *   EXECUTION ELIGIBILITY
 *       every executable cent is OPERATING, and the operating total
 *       equals the intent amount, and nothing trust-destined needs
 *       provider execution.
 *       Asserted separately by executionEligibility().
 *
 * The canonical example from §18: a $10,000 intent split $3,000
 * Operating / $7,000 Trust is COMPLETE (allocation passes) and NOT
 * EXECUTABLE (trust execution disabled). Both facts are true at once,
 * and the system must be able to say so — that is the contradiction
 * §18 orders fixed.
 * ============================================================
 */
class PaymentIntentService
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Create a Draft intent. Material fields remain freely editable
     * until freeze().
     *
     * @param  array{client_id?: int|null, matter_id?: int|null, invoice_id?: int|null, created_by?: int|null}  $links
     */
    public function createDraft(
        Firm $firm,
        int $amountCents,
        string $purpose,
        array $links = [],
        string $currency = 'USD',
    ): PaymentIntent {
        if ($amountCents <= 0) {
            // Mirrors the database CHECK; raised here so the caller gets
            // a domain error rather than a driver-level constraint
            // violation for an obviously invalid instruction.
            throw new \InvalidArgumentException(
                'A PaymentIntent amount must be greater than zero; got '.$amountCents.'.'
            );
        }

        if ($currency !== 'USD') {
            throw new \InvalidArgumentException(
                'FirmsVault Pay POC #1 is USD only; got ['.$currency.'].'
            );
        }

        return $this->tenantContext->runWithFirmContext($firm, fn (): PaymentIntent => PaymentIntent::query()->create([
            'firm_id' => $firm->id,
            'client_id' => $links['client_id'] ?? null,
            'matter_id' => $links['matter_id'] ?? null,
            'invoice_id' => $links['invoice_id'] ?? null,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'purpose' => $purpose,
            'status' => PaymentIntentStatus::Draft,
            'created_by' => $links['created_by'] ?? null,
        ]));
    }

    /**
     * Add one allocation to a DRAFT intent. Allocations are append-only,
     * so they may only be added while the instruction is still mutable.
     */
    public function addAllocation(
        PaymentIntent $intent,
        PaymentDestinationClass $destinationClass,
        int $amountCents,
        ?int $invoiceId = null,
        ?int $matterId = null,
    ): PaymentIntentAllocation {
        if ($intent->status !== PaymentIntentStatus::Draft) {
            throw new PaymentIntentNotExecutableException(
                'Allocations may only be added to a draft PaymentIntent; intent ['.$intent->id
                .'] is ['.$intent->status->value.'].'
            );
        }

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException(
                'A PaymentIntent allocation must be greater than zero; got '.$amountCents.'.'
            );
        }

        return $this->tenantContext->runWithFirmContext(
            (int) $intent->firm_id,
            fn (): PaymentIntentAllocation => PaymentIntentAllocation::query()->create([
                'firm_id' => $intent->firm_id,
                'payment_intent_id' => $intent->id,
                'destination_class' => $destinationClass,
                'amount_cents' => $amountCents,
                'invoice_id' => $invoiceId,
                'matter_id' => $matterId,
                'created_at' => now(),
            ]),
        );
    }

    /**
     * Freeze the instruction (v1.4 §17). From here on the material
     * fields are immutable, and the allocation completeness invariant is
     * true and stays true.
     *
     * The completeness check runs INSIDE a transaction that holds the
     * intent row FOR UPDATE, so two concurrent freezes cannot both
     * observe a satisfying sum. That, plus the append-only allocations
     * and the frozen-intent guard, is why the invariant needs no
     * (impossible) cross-row CHECK constraint.
     */
    public function freeze(PaymentIntent $intent): PaymentIntent
    {
        return $this->tenantContext->runWithFirmContext(
            (int) $intent->firm_id,
            function () use ($intent): PaymentIntent {
                return DB::transaction(function () use ($intent): PaymentIntent {
                    /** @var PaymentIntent $locked */
                    $locked = PaymentIntent::query()
                        ->whereKey($intent->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($locked->status !== PaymentIntentStatus::Draft) {
                        throw new PaymentIntentNotExecutableException(
                            'Only a draft PaymentIntent can be frozen; intent ['.$locked->id
                            .'] is already ['.$locked->status->value.'].'
                        );
                    }

                    $allocated = (int) PaymentIntentAllocation::query()
                        ->where('payment_intent_id', $locked->id)
                        ->sum('amount_cents');

                    if ($allocated !== (int) $locked->amount_cents) {
                        throw new PaymentIntentNotExecutableException(
                            'Allocation completeness failed for PaymentIntent ['.$locked->id.']: allocations '
                            .'total '.$allocated.' cents but the intent is '.$locked->amount_cents.' cents. '
                            .'SUM(allocations) must equal the intent amount exactly.'
                        );
                    }

                    $locked->status = PaymentIntentStatus::Frozen;
                    $locked->frozen_at = now();
                    $locked->material_fingerprint = $locked->computeMaterialFingerprint();
                    $locked->save();

                    $this->audit->record(PayAuditRecorder::INTENT_FROZEN, (int) $locked->firm_id, [
                        'payment_intent_id' => $locked->id,
                        'amount_cents' => (int) $locked->amount_cents,
                        'currency' => $locked->currency,
                    ]);

                    return $locked->refresh();
                });
            },
        );
    }

    /**
     * Supersede a frozen intent with a new one (v1.4 §17: "supersede
     * rather than rewrite history").
     *
     * The original row keeps every material value it was frozen with,
     * forever, and gains only a forward pointer. The replacement is
     * returned as a DRAFT so its own allocations can be composed and
     * independently frozen — a superseding instruction is a new
     * instruction, not an edit.
     */
    public function supersede(PaymentIntent $original, int $newAmountCents, ?string $newPurpose = null): PaymentIntent
    {
        return $this->tenantContext->runWithFirmContext(
            (int) $original->firm_id,
            function () use ($original, $newAmountCents, $newPurpose): PaymentIntent {
                return DB::transaction(function () use ($original, $newAmountCents, $newPurpose): PaymentIntent {
                    /** @var PaymentIntent $locked */
                    $locked = PaymentIntent::query()
                        ->whereKey($original->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($locked->status !== PaymentIntentStatus::Frozen) {
                        throw new PaymentIntentNotExecutableException(
                            'Only a frozen PaymentIntent can be superseded; intent ['.$locked->id
                            .'] is ['.$locked->status->value.'].'
                        );
                    }

                    $replacement = PaymentIntent::query()->create([
                        'firm_id' => $locked->firm_id,
                        'client_id' => $locked->client_id,
                        'matter_id' => $locked->matter_id,
                        'invoice_id' => $locked->invoice_id,
                        'amount_cents' => $newAmountCents,
                        'currency' => $locked->currency,
                        'purpose' => $newPurpose ?? $locked->purpose,
                        'status' => PaymentIntentStatus::Draft,
                        'supersedes_payment_intent_id' => $locked->id,
                        'created_by' => $locked->created_by,
                    ]);

                    // Only lifecycle metadata changes on the original —
                    // the model's own guard would reject anything else.
                    $locked->status = PaymentIntentStatus::Superseded;
                    $locked->superseded_by_payment_intent_id = $replacement->id;
                    $locked->superseded_at = now();
                    $locked->save();

                    $this->audit->record(PayAuditRecorder::INTENT_SUPERSEDED, (int) $locked->firm_id, [
                        'payment_intent_id' => $locked->id,
                        'superseded_by_payment_intent_id' => $replacement->id,
                    ]);

                    return $replacement;
                });
            },
        );
    }

    /**
     * The SEPARATE eligibility question (§18). Never called by freeze();
     * a complete instruction may be perfectly well-formed and still
     * ineligible for provider execution.
     *
     * @return array{eligible: bool, reason: string|null, operating_cents: int, trust_cents: int}
     */
    public function executionEligibility(PaymentIntent $intent): array
    {
        $totals = $this->tenantContext->runWithFirmContext(
            (int) $intent->firm_id,
            fn (): array => PaymentIntentAllocation::query()
                ->where('payment_intent_id', $intent->id)
                ->selectRaw('destination_class, SUM(amount_cents) AS total')
                ->groupBy('destination_class')
                ->pluck('total', 'destination_class')
                ->all(),
        );

        $operating = (int) ($totals[PaymentDestinationClass::Operating->value] ?? 0);
        $trust = (int) ($totals[PaymentDestinationClass::Trust->value] ?? 0);

        $result = fn (bool $eligible, ?string $reason): array => [
            'eligible' => $eligible,
            'reason' => $reason,
            'operating_cents' => $operating,
            'trust_cents' => $trust,
        ];

        if ($intent->status !== PaymentIntentStatus::Frozen) {
            return $result(false, 'payment_intent_not_frozen');
        }

        // Trust execution is DISABLED for POC #1 (v1.4 §19). Any
        // trust-destined value at all makes the instruction
        // non-executable — it is never silently executed "for the
        // operating part only", because that would half-execute an
        // instruction the firm composed as a whole.
        if ($trust > 0) {
            return $result(false, 'trust_execution_disabled');
        }

        if ($operating !== (int) $intent->amount_cents) {
            return $result(false, 'operating_allocations_do_not_cover_intent');
        }

        return $result(true, null);
    }

    /**
     * Allocation completeness as a standalone, side-effect-free query —
     * the half of §18 that is TRUE for the mixed operating/trust example
     * even while execution is blocked.
     */
    public function allocationsAreComplete(PaymentIntent $intent): bool
    {
        $allocated = (int) $this->tenantContext->runWithFirmContext(
            (int) $intent->firm_id,
            fn () => PaymentIntentAllocation::query()
                ->where('payment_intent_id', $intent->id)
                ->sum('amount_cents'),
        );

        return $allocated === (int) $intent->amount_cents;
    }
}
