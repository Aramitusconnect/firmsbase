<?php

namespace App\Enums;

/**
 * TimeEntryStatus — not given an explicit value list by the master
 * plan PDF (unlike Invoice/PaymentPlan/Installment/Payment, which all
 * have an explicit workflow-state row in Section 33). This state list
 * is a recommendation derived from the PDF's requirement that draft
 * invoices are generated "from approved billable time entries" — an
 * approval gate must exist somewhere before time becomes billable.
 */
enum TimeEntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Invoiced = 'invoiced';
}
