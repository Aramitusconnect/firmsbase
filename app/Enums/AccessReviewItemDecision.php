<?php

namespace App\Enums;

/**
 * AccessReviewItemDecision — approved decision #10: Revoke/Modify are
 * RECORD-ONLY in Phase 17. Recording a decision here never itself
 * revokes an admin, key, webhook, AI tool grant, or role — that stays a
 * manual (or future, separately-scoped automated) action against the
 * relevant existing model's own service.
 */
enum AccessReviewItemDecision: string
{
    case Pending = 'pending';
    case Retain = 'retain';
    case Revoke = 'revoke';
    case Modify = 'modify';
}
