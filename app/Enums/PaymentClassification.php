<?php

namespace App\Enums;

/**
 * PaymentClassification — the canonical classification of a client
 * payment, taken verbatim from the master plan PDF's Section 5
 * Canonical Enums table: "operating_payment; trust_iolta_payment;
 * blocked_payment". This is a strict, closed PHP enum — it is NOT a
 * plain string, unlike payment_plan_events.event_type and
 * payment_classification_events.event_type (both deliberately plain
 * strings per project decision).
 *
 * Every payment or payment adjustment must be classified with one of
 * these three values before saving (project rule 5 / PDF Controls and
 * Rules). PaymentClassificationService is the only place this decision
 * is made and logged; no other service may set payments.classification
 * directly. This enum must be reused as-is by Phase 6 Stripe flows and
 * Phase 13 trust accounting — it must never be duplicated or forked.
 */
enum PaymentClassification: string
{
    case OperatingPayment = 'operating_payment';
    case TrustIoltaPayment = 'trust_iolta_payment';
    case BlockedPayment = 'blocked_payment';
}
