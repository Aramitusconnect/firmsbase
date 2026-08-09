<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_reversals — Phase G of the legal accounting foundation.
 * Covers both operating refunds (voluntary, firm-initiated) and
 * operating chargebacks (forced, processor-initiated) with one table
 * distinguished by reversal_type, since the two are structurally
 * identical (a payment, an amount, a reason) and only differ in who
 * initiated them and which downstream accounting source type they
 * post — mirrors how trust_ledger_entries uses one entry_type column
 * rather than a separate table per entry kind. Distinct from
 * trust_refund_requests/trust_chargeback_events (Trust domain,
 * untouched) and platform_refunds (SaaS subscription billing,
 * untouched).
 *
 * Append-only, mirroring every other Phase A-G ledger-shaped table: a
 * reversal is never edited or deleted once recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_plan_installment_id')->nullable()->constrained('payment_plan_installments')->restrictOnDelete();
            $table->string('reversal_type');
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason');
            $table->foreignId('actor_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'payment_id']);
            $table->index('reversal_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
