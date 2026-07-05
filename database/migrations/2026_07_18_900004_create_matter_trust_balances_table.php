<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_trust_balances — the per-matter attribution of a client's
 * trust_ledger balance. No own uuid (accessed only through its parent
 * matter/ledger, mirrors Phase 12's matter_expenses). firm_id is a
 * direct column for defense-in-depth (mirrors signature_events'
 * precedent) but this table's model does NOT use BelongsToTenant.
 * Enforcing this row can never go negative is the core mechanism
 * behind "no cross-matter use of trust funds" (project rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_trust_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('trust_ledger_id')->constrained('trust_ledgers')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();

            $table->bigInteger('balance_cents')->default(0);
            $table->timestamp('last_recomputed_at')->nullable();

            $table->timestamps();

            $table->unique(['trust_ledger_id', 'matter_id']);
            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_trust_balances');
    }
};
