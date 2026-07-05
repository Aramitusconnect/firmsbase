<?php

namespace App\Enums;

/**
 * ExpenseStatus — the lifecycle of an operating expense record.
 * Approved requires having gone through ExpenseApprovalService; nothing
 * else may set an expense to Approved/Rejected (project rule: single
 * writer per lifecycle transition, mirrors Phase 11's
 * SignatureWorkflowTransitionService discipline).
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Voided = 'voided';
}
