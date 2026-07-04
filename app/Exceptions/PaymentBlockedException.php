<?php

namespace App\Exceptions;

use App\Models\Payment;

/**
 * PaymentBlockedException — thrown by ManualPaymentService AFTER its
 * transaction has already committed. The blocked Payment row (status
 * = Blocked) and its PaymentClassificationEvent both persist; this
 * exception only signals "not accepted" to the caller. No
 * ManualPaymentRecord is ever created for a blocked attempt.
 */
class PaymentBlockedException extends \RuntimeException
{
    public function __construct(public readonly Payment $payment, string $reason)
    {
        parent::__construct($reason);
    }
}
