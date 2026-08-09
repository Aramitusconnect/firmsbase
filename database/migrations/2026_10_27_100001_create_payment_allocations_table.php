<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_allocations — Phase F of the legal accounting foundation.
 * Audited first (per the master prompt's own gate): no existing entry
 * point creates a multi-target payment today — ManualPaymentService,
 * TrustTransferRequestService::apply(), and PaymentApplicationService
 * itself are all explicitly single-target by design ("A single
 * payment applies to exactly one target... never both, to avoid
 * double-counting" — PaymentApplicationService's own docblock). This
 * table/capability is built and tested as an available extension of
 * PaymentApplicationService (never a second payment-application
 * service) for a payment that genuinely needs to fund MULTIPLE
 * invoices/installments at once — no existing call site is changed to
 * use it.
 *
 * Append-only, mirroring trust_ledger_entries/accounting_journal_entries:
 * an allocation is never edited or deleted once posted (a
 * misallocation is corrected by refunding/rebalancing through the
 * normal refund path, never by mutating history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_plan_installment_id')->nullable()->constrained('payment_plan_installments')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'payment_id']);
            $table->index('invoice_id');
            $table->index('payment_plan_installment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
