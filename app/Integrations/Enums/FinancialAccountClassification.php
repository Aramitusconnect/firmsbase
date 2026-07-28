<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * FinancialAccountClassification — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §5;
 * checkpoint4-combined-design.md §9.5). The closed classification
 * vocabulary for `financial_evidence_bank_accounts.classification`.
 *
 * A transition INTO or OUT OF `TrustIolta`, a `TrustIolta` account
 * replacement, a second concurrent trust-account connection, or a
 * `SettlementDestination` change are all "sensitive reclassification"
 * per the design's own binding list — these transitions must go
 * through `FinancialAccountReclassificationService`'s two-person
 * approval flow, never a direct, single-actor write. Every other
 * transition is "ordinary reclassification" — single-actor, direct
 * write, still audited.
 */
enum FinancialAccountClassification: string
{
    case Operating = 'operating';
    case TrustIolta = 'trust_iolta';
    case SettlementDestination = 'settlement';
    case ClientOwnedEvidence = 'client_owned_evidence';
    case Investment = 'investment';
    case CreditLiability = 'credit_liability';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating',
            self::TrustIolta => 'Trust / IOLTA',
            self::SettlementDestination => 'Settlement destination',
            self::ClientOwnedEvidence => 'Client-owned (evidence only)',
            self::Investment => 'Investment',
            self::CreditLiability => 'Credit / liability',
            self::Other => 'Other',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Operating => 'gray',
            self::TrustIolta => 'danger',
            self::SettlementDestination => 'warning',
            self::ClientOwnedEvidence => 'info',
            self::Investment => 'primary',
            self::CreditLiability => 'warning',
            self::Other => 'gray',
        };
    }

    /**
     * Sensitive transitions require `FinancialAccountReclassificationService`'s
     * two-person approval — never a direct write. A transition is
     * sensitive if EITHER side of it is `TrustIolta` or
     * `SettlementDestination` (covers operating<->trust, a trust-account
     * replacement — old and new are both TrustIolta but still routed
     * through approval since it is a *replacement*, not a no-op — and a
     * settlement-destination change).
     */
    public static function isSensitiveTransition(?self $from, self $to): bool
    {
        $sensitive = [self::TrustIolta, self::SettlementDestination];

        return in_array($to, $sensitive, true) || ($from !== null && in_array($from, $sensitive, true));
    }
}
