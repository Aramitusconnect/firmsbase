<?php

namespace App\Enums;

/**
 * ExpenseApprovalStatus — the outcome of one approval decision row.
 * A given expense may accumulate more than one expense_approvals row
 * over time (e.g. resubmission after rejection); Expense::latestApproval()
 * always reads the latest via latestOfMany().
 */
enum ExpenseApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
