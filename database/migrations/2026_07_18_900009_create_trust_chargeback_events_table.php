<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_chargeback_events — records the externally-reported fact of a
 * chargeback against a previously-posted trust deposit entry.
 * reversal_trust_ledger_entry_id is populated once
 * TrustChargebackService posts the offsetting entry; the ORIGINAL
 * deposit entry (original_trust_ledger_entry_id) is never mutated —
 * only referenced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_chargeback_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('original_trust_ledger_entry_id')->constrained('trust_ledger_entries')->restrictOnDelete();
            $table->foreignId('reversal_trust_ledger_entry_id')->nullable()->constrained('trust_ledger_entries')->restrictOnDelete();

            $table->bigInteger('amount_cents');
            $table->text('reason');
            $table->string('status')->default('reported');

            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('original_trust_ledger_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_chargeback_events');
    }
};
