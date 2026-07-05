<?php

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustAccount;
use App\Models\TrustApprovalEvent;
use App\Models\TrustBalance;
use App\Models\TrustChargebackEvent;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\Models\TrustReconciliation;
use App\Models\TrustRefundRequest;
use App\Models\TrustTransferRequest;

/**
 * TenantSafeTrustPolicyService — the shared cross-firm guard for every
 * Phase 13 table, mirroring TenantSafeAccountingPolicyService /
 * TenantSafeSignatureAndPdfPolicyService's exact pattern (defense in
 * depth, independent of and in addition to BelongsToTenant's global
 * scope where that trait is applied).
 */
class TenantSafeTrustPolicyService
{
    public function assertTrustAccountBelongsToFirm(TrustAccount $account, Firm $firm): void
    {
        if ($account->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustAccount [id={$account->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustLedgerBelongsToFirm(TrustLedger $ledger, Firm $firm): void
    {
        if ($ledger->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustLedger [id={$ledger->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustBalanceBelongsToFirm(TrustBalance $balance, Firm $firm): void
    {
        if ($balance->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustBalance [id={$balance->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertMatterTrustBalanceBelongsToFirm(MatterTrustBalance $balance, Firm $firm): void
    {
        if ($balance->firm_id !== $firm->id) {
            throw new TenantIsolationException("MatterTrustBalance [id={$balance->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustLedgerEntryBelongsToFirm(TrustLedgerEntry $entry, Firm $firm): void
    {
        if ($entry->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustLedgerEntry [id={$entry->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustTransferRequestBelongsToFirm(TrustTransferRequest $request, Firm $firm): void
    {
        if ($request->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustTransferRequest [id={$request->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustRefundRequestBelongsToFirm(TrustRefundRequest $request, Firm $firm): void
    {
        if ($request->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustRefundRequest [id={$request->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustApprovalEventBelongsToFirm(TrustApprovalEvent $event, Firm $firm): void
    {
        if ($event->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustApprovalEvent [id={$event->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustChargebackEventBelongsToFirm(TrustChargebackEvent $event, Firm $firm): void
    {
        if ($event->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustChargebackEvent [id={$event->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    public function assertTrustReconciliationBelongsToFirm(TrustReconciliation $reconciliation, Firm $firm): void
    {
        if ($reconciliation->firm_id !== $firm->id) {
            throw new TenantIsolationException("TrustReconciliation [id={$reconciliation->id}] does not belong to firm [id={$firm->id}].");
        }
    }

    /**
     * The explicit "matter belongs to the same firm and the same
     * client as the trust ledger" check — the core of "no cross-matter
     * use of trust funds" (project rule / correction #10).
     */
    public function assertMatterMatchesLedger(Matter $matter, TrustLedger $ledger): void
    {
        if ($matter->firm_id !== $ledger->firm_id) {
            throw new TenantIsolationException(
                "Matter [id={$matter->id}] does not belong to the same firm as TrustLedger [id={$ledger->id}]."
            );
        }

        if ($matter->client_id !== $ledger->client_id) {
            throw new TenantIsolationException(
                "Matter [id={$matter->id}] does not belong to the same client as TrustLedger [id={$ledger->id}]."
            );
        }
    }
}
