<?php

namespace App\Services;

use App\Enums\PlatformPaymentStatus;
use App\Enums\PlatformRefundStatus;
use App\Models\PlatformPayment;
use App\Models\PlatformRefund;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Facades\DB;

/**
 * PlatformRefundService — the only place platform_refunds rows are
 * created. Validates the refund amount against what remains refundable
 * on the payment before ever calling StripeGateway::createRefund().
 *
 * Billing & Commercial Control Plane pass — over-refund hardening. The
 * remaining-refundable ceiling is now computed against a row that is
 * held under `SELECT ... FOR UPDATE` for the life of the transaction,
 * not against the caller's in-memory PlatformPayment. Without the lock
 * two concurrent refunds (a double-submitted admin action, a retried
 * queue job, two operators on the same payment) each read
 * `alreadyRefunded = 0`, each independently pass the ceiling check, and
 * both commit — refunding more than was ever collected. Postgres
 * serializes the second transaction behind the first at the
 * `lockForUpdate()` line instead, so it reads the first refund's
 * committed total and is correctly rejected. This is the domain-level
 * idempotency/serialization guarantee behind the "requested refund <=
 * remaining refundable" invariant; it is NOT a UI concern and must not
 * be re-implemented in Filament.
 *
 * $amountCents is additionally required to be strictly positive. The
 * ceiling check alone accepts 0 and negatives (both are <= the
 * remaining balance), but `platform_refunds.amount_cents` is an
 * unsignedBigInteger — a negative would previously surface as a raw SQL
 * constraint violation, and a zero-amount refund would create
 * meaningless financial evidence plus a real gateway call. Both now
 * fail fast with the same InvalidArgumentException shape the rest of
 * this codebase's commercial services use.
 */
class PlatformRefundService
{
    public function refund(
        PlatformPayment $payment,
        int $amountCents,
        string $reason,
        StripeGateway $gateway,
    ): PlatformRefund {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero.');
        }

        return DB::transaction(function () use ($payment, $amountCents, $reason, $gateway) {
            // Re-read the payment under a row lock. Everything below —
            // the ceiling, the already-refunded sum, and the resulting
            // payment status — is derived from THIS locked row, never
            // from the possibly-stale $payment the caller handed in.
            $lockedPayment = PlatformPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyRefunded = (int) $lockedPayment->refunds()
                ->where('status', PlatformRefundStatus::Completed->value)
                ->sum('amount_cents');

            if ($alreadyRefunded + $amountCents > $lockedPayment->amount_cents) {
                throw new \RuntimeException('Refund amount exceeds the remaining refundable balance on this payment.');
            }

            $result = $gateway->createRefund($lockedPayment->gateway_payment_ref ?? '', $amountCents);

            $refund = PlatformRefund::create([
                'platform_payment_id' => $lockedPayment->id,
                'status' => $result['status'] === 'succeeded' ? PlatformRefundStatus::Completed : PlatformRefundStatus::Failed,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'gateway_refund_ref' => $result['id'] ?? null,
                'requested_at' => now(),
                'processed_at' => $result['status'] === 'succeeded' ? now() : null,
            ]);

            if ($refund->status === PlatformRefundStatus::Completed) {
                $newTotal = $alreadyRefunded + $amountCents;
                $lockedPayment->update([
                    'status' => $newTotal >= $lockedPayment->amount_cents
                        ? PlatformPaymentStatus::Refunded
                        : PlatformPaymentStatus::PartiallyRefunded,
                ]);

                // Keep the caller's instance consistent with what was
                // just committed — callers (and the pre-existing tests)
                // assert against the PlatformPayment they passed in.
                $payment->setAttribute('status', $lockedPayment->status);
                $payment->syncOriginalAttribute('status');
            }

            return $refund;
        });
    }
}
