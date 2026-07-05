<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_balances — one cached balance row per trust_ledger, recomputed
 * exclusively by TrustBalanceService as SUM(trust_ledger_entries.amount_cents)
 * for that ledger. Never written to by any other service (project
 * rule: "no silent balance mutation").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_balances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('trust_ledger_id')->unique()->constrained('trust_ledgers')->cascadeOnDelete();

            $table->bigInteger('balance_cents')->default(0);
            $table->timestamp('last_recomputed_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_balances');
    }
};
