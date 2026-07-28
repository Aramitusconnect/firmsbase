<?php

declare(strict_types=1);

/**
 * config/financial_evidence.php — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7/§6;
 * checkpoint4-combined-design.md §9.4/§11). Non-monetary-adjacent
 * operational constants for the Financial Evidence Workspace's
 * FirmsVault-generated review queues — the same config-vs-table line
 * `config/integrations.php`'s cost-control section already draws
 * between "operational constants" (config) and "priced/scoped reference
 * data" (a table, e.g. `financial_evidence_large_deposit_thresholds`
 * for the FIRM-OVERRIDABLE half of the large-deposit threshold).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Potential Duplicate Transfers detection window
    |--------------------------------------------------------------------------
    |
    | FinancialEvidenceDuplicateTransferDetectionService flags two
    | transactions across a matter's connected accounts with a matching
    | absolute amount and opposite sign within this many hours of each
    | other as a potential duplicate/internal transfer.
    */
    'duplicate_transfer_window_hours' => env('FINANCIAL_EVIDENCE_DUPLICATE_TRANSFER_WINDOW_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Unexplained Large Deposits — platform-default threshold
    |--------------------------------------------------------------------------
    |
    | The platform_default fallback row FinancialEvidenceLargeDepositDetectionService
    | resolves when no firm_override row exists in
    | financial_evidence_large_deposit_thresholds (Global, no RLS, same
    | platform_default -> firm_override scope-precedence pattern
    | provider_rate_card_entries already established).
    */
    'large_deposit_default_threshold_cents' => env('FINANCIAL_EVIDENCE_LARGE_DEPOSIT_DEFAULT_THRESHOLD_CENTS', 1_000_000),

];
