<?php

namespace App\Enums;

/**
 * PaymentMode — firm_settings.payment_mode. Governs which client-payment
 * classifications a firm is permitted to use at all. Trust/IOLTA
 * deposits must remain blocked until the full trust accounting
 * foundation is complete and accepted (project rule 6) — this enum
 * only records the firm's configured mode; it does not itself unblock
 * trust processing.
 */
enum PaymentMode: string
{
    case OperatingPaymentsOnly = 'operating_payments_only';
    case OperatingAndTrust = 'operating_and_trust';
    case Blocked = 'blocked';
}
