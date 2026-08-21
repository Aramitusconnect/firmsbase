<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trust & Accounting Integrity Hardening, Mission 1.3 — a Postgres CHECK
 * constraint (matching this codebase's existing CHECK-constraint
 * convention, e.g. license_files_exactly_one_owner_path,
 * integration_outbox_events_processing_lock_consistency) as
 * defense-in-depth against trust_balances.balance_cents ever going
 * negative.
 *
 * VERIFIED SAFE for this table specifically (unlike trust_ledger_entries
 * — see the companion Mission 1.3 report for why a CHECK on that table
 * would be unsafe): trust_balances.balance_cents is a single cached
 * aggregate, exclusively written by TrustBalanceService::
 * recomputeForLedger() as one full overwrite (SUM(trust_ledger_entries.
 * amount_cents)), never an incremental debit/credit. Every money-moving
 * service that can reduce this balance (TrustTransferRequestService::
 * apply(), TrustRefundRequestService::complete(),
 * TrustHighRiskAdjustmentService::secondApprove()) already asserts
 * sufficient balance BEFORE writing the debiting entry, under the same
 * row lock this recompute runs in (TrustConcurrencyLockService::
 * withLockedBalances()). This constraint does not change that — it is a
 * database-level backstop against a future bug in that application-level
 * guarantee, not a new business rule. Postgres validates all existing
 * rows against the constraint at ADD time, so this migration fails
 * loudly (not silently) if any row already violates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE trust_balances ADD CONSTRAINT trust_balances_balance_cents_non_negative CHECK (balance_cents >= 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE trust_balances DROP CONSTRAINT trust_balances_balance_cents_non_negative');
    }
};
