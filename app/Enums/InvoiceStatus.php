<?php

namespace App\Enums;

/**
 * InvoiceStatus — values taken verbatim from the master plan PDF,
 * Section 33 "Workflow State Machines", Invoice row: "draft;
 * pending_review; approved; sent; partially_paid; paid; void;
 * written_off; refunded". Transitions are enforced exclusively by
 * InvoiceDraftingService; "Payments cannot apply to invoices unless
 * payment classification and permissions pass" (same PDF row).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';
    case WrittenOff = 'written_off';
    case Refunded = 'refunded';
}
