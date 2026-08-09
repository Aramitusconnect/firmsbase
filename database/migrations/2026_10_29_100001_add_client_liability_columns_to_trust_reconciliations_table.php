<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H — true three-way trust reconciliation. Adds the THIRD
 * independent leg alongside the existing system_balance_cents (ledger
 * cache, already verified against live trust_ledger_entries) and
 * asserted_bank_balance_cents (bank/evidence): the sum of individual
 * client/matter trust liabilities, computed independently from
 * matter_trust_balances + any non-matter-attributed ledger entries
 * (see TrustBalanceService::verifyMatterLiabilitiesReconcileToLedger()).
 * Nullable because pre-existing reconciliation rows never computed
 * this leg — this is purely additive, no historical row is
 * back-filled or reinterpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trust_reconciliations', function (Blueprint $table) {
            $table->bigInteger('client_liability_cents')->nullable()->after('system_balance_cents');
            $table->bigInteger('client_liability_discrepancy_cents')->nullable()->after('discrepancy_cents');
        });
    }

    public function down(): void
    {
        Schema::table('trust_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['client_liability_cents', 'client_liability_discrepancy_cents']);
        });
    }
};
