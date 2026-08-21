<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trust & Accounting Integrity Hardening, Mission 1.3 — the
 * matter-level counterpart of 2026_11_21_100001's trust_balances
 * constraint. Same rationale: matter_trust_balances.balance_cents is
 * exclusively written by TrustBalanceService::recomputeForMatter() as
 * one full overwrite, and every debiting money-moving service already
 * calls TrustCrossMatterProtectionService::assertDebitKeepsMatterBalanceNonNegative()
 * under the same row lock before writing. This is a database-level
 * backstop against a future bug in that guarantee, not a new rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE matter_trust_balances ADD CONSTRAINT matter_trust_balances_balance_cents_non_negative CHECK (balance_cents >= 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE matter_trust_balances DROP CONSTRAINT matter_trust_balances_balance_cents_non_negative');
    }
};
