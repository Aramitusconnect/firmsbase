<?php

namespace App\Enums;

/**
 * AccountingExportTarget — a strict, closed enum naming the export
 * destination LABEL only. There is no QuickBooks SDK, OAuth client, or
 * HTTP call anywhere in this codebase (project rule: fake/simulated
 * one-way export only). Adding a second real or simulated target later
 * is an additive enum change, not a redesign.
 */
enum AccountingExportTarget: string
{
    case QuickbooksOnline = 'quickbooks_online';
}
