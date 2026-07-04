<?php

namespace App\Enums;

/**
 * InvoiceLineType — "Create invoice lines from time entries and
 * approved charges" (PDF Scope). TimeEntry and FlatFee are required by
 * that sentence; ManualCharge covers "approved charges" that are not a
 * time entry; Adjustment is a recommendation (not explicitly named by
 * the PDF) for correcting an already-sent invoice without editing a
 * historical line in place.
 */
enum InvoiceLineType: string
{
    case TimeEntry = 'time_entry';
    case FlatFee = 'flat_fee';
    case ManualCharge = 'manual_charge';
    case Adjustment = 'adjustment';
}
