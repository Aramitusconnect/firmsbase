<?php

namespace App\Enums;

/**
 * InvoiceType — distinguishes an invoice drafted from approved time
 * entries versus a flat-fee invoice created directly, per the PDF's
 * requirement that flat-fee invoices are "a first-class type for the
 * immigration pilot," not a special case bolted onto time billing.
 */
enum InvoiceType: string
{
    case TimeAndExpense = 'time_and_expense';
    case FlatFee = 'flat_fee';
}
