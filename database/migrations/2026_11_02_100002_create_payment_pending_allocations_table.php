<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_pending_allocations — Mixed-Invoice Revenue Allocation pass,
 * item 3. The governed review state for a payment applied against a
 * mixed invoice (fee lines + ReimbursableExpense lines) whose fee/cost
 * split cannot be determined safely — no PaymentRequestPurpose
 * constraint, or a purpose that itself doesn't resolve the ambiguity
 * (PaymentRequestPurpose::PaymentPlanInstallment with no defined
 * fee/cost mapping). Never a second Payment system: the underlying
 * Payment row already exists and is already Succeeded (real money was
 * genuinely received) — this table only tracks that its APPLICATION
 * to the invoice/installment and its ACCOUNTING consequence are
 * deliberately deferred until an authorized human resolves the split.
 *
 * Exactly one of invoice_id/payment_plan_installment_id is the
 * payment's own direct target (mirrors payment_allocations' own
 * nullable-either-or shape); invoice_id is ALSO always populated even
 * when the direct target is an installment (denormalized from
 * installment.paymentPlan.invoice_id) so resolution never needs a
 * second query to find the invoice whose lines it must reconcile
 * against.
 *
 * Not fully append-only like payment_allocations/accounting_journal_
 * entries — it has exactly one legitimate transition (pending ->
 * resolved), enforced by PaymentAllocationResolutionService and the
 * model's own guard against a second resolution of an already-resolved
 * row. FORCE RLS from creation, like every other table added this
 * mission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_pending_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_plan_installment_id')->nullable()->constrained('payment_plan_installments')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('status')->default('pending');
            $table->string('reason');

            $table->foreignId('resolved_by_firm_user_id')->nullable()->constrained('firm_users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_fee_cents')->nullable();
            $table->unsignedBigInteger('resolved_cost_cents')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('invoice_id');
            $table->index('payment_plan_installment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_pending_allocations');
    }
};
