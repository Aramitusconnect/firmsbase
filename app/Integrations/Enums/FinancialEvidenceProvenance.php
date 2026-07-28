<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * FinancialEvidenceProvenance — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.3;
 * checkpoint4-combined-design.md §9.2). The one cross-cutting
 * data-provenance classification every value the Financial Evidence
 * Workspace displays must carry — no value is ever displayed without
 * one, per the design's explicit provenance-visibility requirement.
 *
 * Following this codebase's own confirmed "no shared Blade component
 * library — the convention to reuse is the repeated inline closure
 * shape, not a shared component" finding, `badgeColor()`/`label()`
 * below are the one place a shared "component" is idiomatic (enums are
 * already reused this way elsewhere, e.g. `ConnectionStatus`) —
 * consumed identically by every panel's provenance column.
 */
enum FinancialEvidenceProvenance: string
{
    case ProviderSuppliedFact = 'provider_supplied_fact';
    case FirmsVaultObservation = 'firmsvault_observation';
    case AttorneyConfirmedClassification = 'attorney_confirmed';
    case ClientProvidedExplanation = 'client_provided_explanation';
    case UploadedSourceRecord = 'uploaded_source_record';
    case ReconciliationCandidate = 'reconciliation_candidate';
    case ConfirmedLedgerMatch = 'confirmed_ledger_match';

    public function label(): string
    {
        return match ($this) {
            self::ProviderSuppliedFact => 'Bank-verified',
            self::FirmsVaultObservation => 'System-detected',
            self::AttorneyConfirmedClassification => 'Attorney-confirmed',
            self::ClientProvidedExplanation => 'Client-provided explanation',
            self::UploadedSourceRecord => 'Client-uploaded — not bank-verified',
            self::ReconciliationCandidate => 'Reconciliation candidate (unconfirmed)',
            self::ConfirmedLedgerMatch => 'Confirmed ledger match',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::ProviderSuppliedFact => 'success',
            self::FirmsVaultObservation => 'info',
            self::AttorneyConfirmedClassification => 'primary',
            self::ClientProvidedExplanation => 'gray',
            self::UploadedSourceRecord => 'warning',
            self::ReconciliationCandidate => 'warning',
            self::ConfirmedLedgerMatch => 'success',
        };
    }
}
