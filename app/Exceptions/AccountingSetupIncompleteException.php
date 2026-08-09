<?php

namespace App\Exceptions;

use App\Enums\ChartOfAccountPurpose;

/**
 * AccountingSetupIncompleteException — Accounting Integrity Hardening
 * Pass, item 1: the ONLY outcome allowed when a firm that has the
 * accounting module enabled (AccountingEntitlementPolicyService::
 * isExpensesEnabledForFirm() === true) attempts a money-changing event
 * whose required chart-of-accounts purpose is not configured.
 *
 * Thrown from inside ChartOfAccountsService::requireByPurpose(), always
 * from within the SAME runWithFirmContext()/DB::transaction() wrap the
 * calling business event (payment application, expense approval, trust
 * transfer, refund/chargeback) is already running in — so throwing it
 * rolls back the ENTIRE business transaction atomically. There is no
 * partial state: either the business event and its accounting posting
 * both land, or neither does. See OperatingJournalRecorderService's own
 * docblock for the full policy this exception enforces.
 *
 * A firm that has NOT enabled the accounting module at all never
 * reaches this exception — OperatingJournalRecorderService skips
 * posting entirely for such a firm (accounting genuinely does not
 * apply to it), which is a documented "not applicable" case, not a
 * silent failure.
 */
class AccountingSetupIncompleteException extends \RuntimeException
{
    public function __construct(public readonly ChartOfAccountPurpose $purpose, string $message)
    {
        parent::__construct($message);
    }
}
