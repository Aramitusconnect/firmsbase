<?php

namespace App\Enums;

/**
 * PlatformInvoiceStatus — platform_invoices.status. Deliberately
 * separate from Phase 3's InvoiceStatus (firm-client invoices) — never
 * shared or reused, per project rule 1 (platform billing must stay
 * completely separate from firm-client billing). Proposed during
 * Phase 6 planning and approved.
 */
enum PlatformInvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case PastDue = 'past_due';
    case Void = 'void';
}
