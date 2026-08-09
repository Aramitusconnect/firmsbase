<?php

namespace App\Enums;

/**
 * PaymentRequestAmountRule — Payment Link / QR Routing phase. Governs
 * exactly what amount a payer may submit; enforced server-side only
 * (PaymentRequestService::validatePayableAmount()) — the public page
 * never lets the browser silently choose the accounting destination or
 * an amount outside what the firm configured on the request itself.
 */
enum PaymentRequestAmountRule: string
{
    /** The payer must pay requested_amount_cents exactly. */
    case Fixed = 'fixed';

    /** The payer may pay any positive amount up to the remaining balance of the request's target. */
    case UpTo = 'up_to';

    /**
     * The payer may enter any positive amount, with no upper bound tied
     * to a target balance — only usable when the firm explicitly chose
     * this rule while creating THIS payment request (the per-request
     * choice itself is the required explicit configuration; there is no
     * separate firm-wide toggle to duplicate).
     */
    case CustomAllowed = 'custom_allowed';
}
