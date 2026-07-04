<?php

namespace App\Services;

use App\Enums\PaymentClassification;

/**
 * PlatformBillingClassificationService — reuses Phase 3's
 * PaymentClassification enum AS-IS (never duplicated or forked, per
 * that enum's own docblock). Platform billing money is, by definition,
 * always operating money: it is the firm paying the PLATFORM for its
 * subscription, never client funds and never trust/IOLTA money.
 * classify() therefore always returns OperatingPayment — this is the
 * sole, intentional classification decision for every platform
 * payment, and it happens BEFORE PlatformPaymentService ever calls
 * StripeGateway::createPaymentIntent() (project rule 9: "reuse Phase 3
 * payment classification before any PaymentIntent creation").
 */
class PlatformBillingClassificationService
{
    public function classify(): PaymentClassification
    {
        return PaymentClassification::OperatingPayment;
    }
}
