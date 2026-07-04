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
 */
class PlatformRefundService
{
    public function refund(
        PlatformPayment $payment,
        int $amountCents,
        string $reason,
        StripeGateway $gateway,
    ): PlatformRefund {
        return DB::transaction(function () use ($payment, $amountCents, $reason, $gateway) {
            $alreadyRefunded = (int) $payment->refunds()
                ->where('status', PlatformRefundStatus::Completed->value)
                ->sum('amount_cents');

            if ($alreadyRefunded + $amountCents > $payment->amount_cents) {
                throw new \RuntimeException('Refund amount exceeds the remaining refundable balance on this payment.');
            }

            $result = $gateway->createRefund($payment->gateway_payment_ref ?? '', $amountCents);

            $refund = PlatformRefund::create([
                'platform_payment_id' => $payment->id,
                'status' => $result['status'] === 'succeeded' ? PlatformRefundStatus::Completed : PlatformRefundStatus::Failed,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'gateway_refund_ref' => $result['id'] ?? null,
                'requested_at' => now(),
                'processed_at' => $result['status'] === 'succeeded' ? now() : null,
            ]);

            if ($refund->status === PlatformRefundStatus::Completed) {
                $newTotal = $alreadyRefunded + $amountCents;
                $payment->update([
                    'status' => $newTotal >= $payment->amount_cents
                        ? PlatformPaymentStatus::Refunded
                        : PlatformPaymentStatus::PartiallyRefunded,
                ]);
            }

            return $refund;
        });
    }
}
