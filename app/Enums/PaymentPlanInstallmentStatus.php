<?php

namespace App\Enums;

/**
 * PaymentPlanInstallmentStatus — values taken verbatim from the master
 * plan PDF, Section 33, Installment row: "scheduled; due; paid;
 * partially_paid; missed; waived; cancelled". "Missed installments
 * trigger consent-respecting dunning per policy" (same PDF row).
 */
enum PaymentPlanInstallmentStatus: string
{
    case Scheduled = 'scheduled';
    case Due = 'due';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Missed = 'missed';
    case Waived = 'waived';
    case Cancelled = 'cancelled';
}
