<?php

namespace App\Enums;

/**
 * TrustReconciliationStatus — Discrepancy is a durable, visible fact
 * requiring human investigation; TrustReconciliationService never
 * auto-corrects a discrepancy into Balanced (project rule).
 */
enum TrustReconciliationStatus: string
{
    case InProgress = 'in_progress';
    case Balanced = 'balanced';
    case Discrepancy = 'discrepancy';
}
