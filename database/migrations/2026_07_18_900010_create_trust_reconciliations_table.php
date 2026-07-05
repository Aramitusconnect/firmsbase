<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_reconciliations — a periodic, firm-initiated snapshot comparing
 * the summed trust_balances for a trust_account against a manually
 * asserted bank statement balance (no real bank integration).
 * TrustReconciliationService never auto-corrects a discrepancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('trust_account_id')->constrained('trust_accounts')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('system_balance_cents');
            $table->bigInteger('asserted_bank_balance_cents');
            $table->bigInteger('discrepancy_cents');
            $table->string('status')->default('in_progress');

            $table->foreignId('performed_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('trust_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_reconciliations');
    }
};
