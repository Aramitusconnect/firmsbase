<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mixed-Invoice Revenue Allocation pass, item 4. Extends the existing
 * payment_allocations table (never a second allocation table) with a
 * nullable revenue_purpose column — the ChartOfAccountPurpose a given
 * allocation row's amount was actually recognized against
 * (legal_fee_revenue or cost_reimbursement_revenue).
 *
 * NULL for the pre-existing multi-target-split use case
 * (PaymentApplicationService::applySplit(), which allocates a single
 * payment ACROSS MULTIPLE invoices/installments — a different axis
 * entirely from "which revenue bucket within one invoice") — that use
 * case is completely unaffected by this column.
 *
 * Populated by the NEW use case
 * (PaymentApplicationService::recordRevenueAllocation(), called from
 * ManualPaymentService::submit() once an invoice-targeted payment's
 * fee/cost split has been resolved) — one row per non-zero bucket, so
 * a single $500 payment split $300 fee / $200 cost produces two rows:
 * (invoice_id: X, revenue_purpose: legal_fee_revenue, amount_cents:
 * 30000) and (invoice_id: X, revenue_purpose: cost_reimbursement_revenue,
 * amount_cents: 20000) — both referencing the SAME payment_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->string('revenue_purpose')->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn('revenue_purpose');
        });
    }
};
