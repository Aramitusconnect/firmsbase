<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trust_accounts — first of a ten-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the trust accounting domain (Wave 10):
 * trust_accounts (this migration), trust_ledgers (2026_08_30_980002),
 * trust_balances (2026_08_30_980003), matter_trust_balances
 * (2026_08_30_980004), trust_ledger_entries (2026_08_30_980005),
 * trust_approval_events (2026_08_30_980006), trust_chargeback_events
 * (2026_08_30_980007), trust_reconciliations (2026_08_30_980008),
 * trust_refund_requests (2026_08_30_980009), trust_transfer_requests
 * (2026_08_30_980010).
 *
 * This is the largest, most tightly-coupled table group forced so far:
 * TrustConcurrencyLockService::withLockedBalances() treats
 * trust_balances + matter_trust_balances + trust_ledger_entries as one
 * atomic write unit inside a single DB::transaction(), invoked from 5
 * different services, and every one of the 10 tables participates in
 * at least one shared, atomic, cross-table write path (trust_accounts
 * -> trust_ledgers created together, trust_ledgers -> trust_balances
 * created together, the 3-table lock unit, the FK-linked authorization
 * chain between trust_ledger_entries and trust_approval_events/
 * trust_transfer_requests/trust_refund_requests, trust_chargeback_
 * events -> trust_ledger_entries via TrustLedgerEntryReversalService,
 * and trust_reconciliations reading across every trust_ledgers/
 * trust_balances row under a trust_accounts row). All 10 must be
 * forced together in this one release; forcing a subset would create a
 * window where the shared lock primitive's read/write behavior is
 * inconsistent across tables.
 *
 * trust_accounts has NO pre-existing policy to flip FORCE on for — no
 * ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it anywhere
 * yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: trust_accounts carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_18_900001_create_trust_accounts_table.php). The TrustAccount
 * model uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned
 * row, the root of the IOLTA trust foundation. No real bank
 * integration exists in this phase (bank_name_reference is free text).
 *
 * Command shape: combined, symmetric, FOR ALL — trust_accounts is
 * fully mutable via TrustAccountService::suspend()/close() (Active ->
 * Suspended/Closed), matching every other table in this batch and the
 * canonical template used throughout this rollout.
 *
 * REQUIRED co-landed service changes (this wave touches ~10 services;
 * see each service's own docblock/inline comments for its exact wrap
 * inventory):
 *   - TrustReconciliationService::run() (§0 of the approved Wave 10
 *     design) — the single highest-priority fix in this wave. The
 *     entire method body is now wrapped in one runWithFirmContext()
 *     call, a new TrustEligibilityService::assertEligible($firm)
 *     pre-flight check was added, and a defensive check now refuses to
 *     record a reconciliation result if trust_ledgers rows exist for
 *     the account but none were visible under the active context —
 *     without this fix, $account->ledgers would silently return empty
 *     once trust_accounts/trust_ledgers are forced, making every
 *     reconciliation misreport a real discrepancy as Balanced.
 *   - TrustEligibilityService::evaluate() — a second, separate, narrow
 *     wrap was added around the hasApprovedTrustSetup($firm) call
 *     (reads trust_approval_events), sibling to the pre-existing
 *     firm_settings wrap. This is the universal pre-flight gate for
 *     essentially every method across all Trust services (~25 call
 *     sites) — left unfixed, this batch would break the entire domain
 *     on deploy.
 *   - TrustTransferRequestService::apply(),
 *     TrustRefundRequestService::complete(),
 *     TrustHighRiskAdjustmentService::secondApprove(),
 *     TrustLedgerEntryReversalService::reverse() — each collapsed from
 *     a decoy-wrap pattern (isolated narrow wraps around unrelated
 *     reads while the actual trust-table writes in between ran
 *     unwrapped) into one outer whole-method wrap, with the
 *     pre-existing narrow wraps surviving unchanged as nested children
 *     (structurally safe: TenantContextService::runWithFirmContext()
 *     snapshots/restores context regardless of nesting depth).
 *   - TrustChargebackService::reverse() — previously had ZERO wrap of
 *     any kind and no null-safety on a lazy-loaded relation chain; now
 *     has a whole-method wrap plus a defensive null-check.
 *   - Every remaining method across TrustAccountService,
 *     TrustLedgerService, TrustDepositService,
 *     TrustTransferRequestService, TrustRefundRequestService,
 *     TrustChargebackService, TrustHighRiskAdjustmentService, and
 *     TrustModeActivationService received its own whole-method wrap.
 *   - TrustBalanceService (recomputeForLedger()/recomputeForMatter()/
 *     reconcileCacheAgainstLedger()) was deliberately left untouched:
 *     it takes no $firm parameter, and every one of its 6 call sites is
 *     inside a method this batch already wraps — a redundant wrap
 *     there would be a no-op.
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * documented per this rollout's established precedent):
 *   1. Every table's nullable-FK cross-firm-mismatch risk (see each
 *      table's own migration for its specific FKs) — no composite
 *      foreign key or trigger ties a nullable FK's target row's own
 *      firm_id to this row's firm_id; only the caller-supplied objects
 *      each Trust service receives are trusted. Compensated by the
 *      existing, unmodified TenantSafeTrustPolicyService and
 *      TrustCrossMatterProtectionService app-layer checks. Same
 *      posture as every other single-hop-FK gap accepted throughout
 *      this rollout (e.g. legal_holds, export_jobs).
 *   2. matter_trust_balances, trust_ledger_entries, and
 *      trust_approval_events do NOT use BelongsToTenant despite
 *      carrying a direct firm_id column — a deliberate design choice
 *      mirroring the existing SignatureEvent/DocumentHash precedent,
 *      not a new gap introduced by this migration. Informational only.
 *   3. PostgreSQL's documented row-security semantics exempt
 *      foreign-key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent trust_accounts rows regardless of
 *      which tenant's context is currently active. Expected, identical
 *      behavior to every other cascade-on-firms table already forced
 *      in this repository.
 *   4. The pre-existing, already-tracked (in
 *      ComplianceGapRegistryService)
 *      trust_ledger_entry_posting_actor_not_guaranteed gap is
 *      unrelated to this wave's scope and is not touched here.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'trust_accounts';

    private const POLICY = 'trust_accounts_tenant_isolation';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$policy}
            ON {$table}
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced the policy itself (there
     * was no pre-existing policy to merely un-FORCE), so down() must
     * remove all three effects up() added: FORCE, the policy, and row-
     * level security being enabled at all — restoring the table to its
     * true pre-this-migration (MISSING_PREPARED_TABLES) state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
