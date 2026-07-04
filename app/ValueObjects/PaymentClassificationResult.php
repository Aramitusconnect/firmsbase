<?php

namespace App\ValueObjects;

use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;

/**
 * PaymentClassificationResult — the outcome of
 * PaymentClassificationService::classify(). $accepted is true only
 * when resolvedClassification is OperatingPayment and status is
 * Succeeded; every other outcome (blocked_payment, or an attempted
 * trust_iolta_payment that got resolved to blocked_payment) is
 * $accepted = false, meaning "do not create a successful canonical
 * payment" (project rule).
 */
final readonly class PaymentClassificationResult
{
    public function __construct(
        public PaymentClassification $resolvedClassification,
        public PaymentStatus $status,
        public bool $accepted,
        public ?string $rejectionReason = null,
    ) {
    }
}
