<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_requests — Payment Link / QR Routing phase. An ENTRY CHANNEL
 * only: this table never becomes a parallel ledger, payment system,
 * trust classification system, invoice system, or accounting system.
 * It records the smallest domain necessary to make a secure, purpose-
 * scoped, amount-constrained payment request shareable via a signed
 * URL/QR code, and to trace what happened as a result — the actual
 * money movement, classification, and posting are always decided by
 * the existing canonical services (PaymentClassificationService,
 * PaymentApplicationService, ManualPaymentService, TrustDepositService,
 * OperatingJournalRecorderService), never by this table's own columns.
 *
 * `uuid` (via the HasPublicUuid trait, UUIDv7) is the ONLY identifier
 * ever exposed in a public URL/QR code — `id` is never exposed
 * externally, matching every other public-uuid model in this codebase.
 * PaymentPlanInstallment's own migration already anticipated exactly
 * this use ("future portal installment-level links"); this table
 * reuses that same established mechanism rather than inventing a
 * second opaque-token scheme.
 *
 * Exactly one of invoice_id/payment_plan_installment_id is populated
 * depending on purpose (matter_id may additionally be set for
 * trust_deposit requests, mirroring TrustDepositService::post()'s own
 * optional matter attribution) — enforced by PaymentRequestService,
 * never by a database constraint, matching this codebase's existing
 * convention for source-type-driven optional FKs (e.g.
 * accounting_journal_entries).
 *
 * requested_amount_cents is required when amount_rule=fixed and
 * optional (a suggested/default figure) otherwise — PaymentRequestService
 * is the sole place that validates a payer-submitted amount against
 * amount_rule; the browser never gets to choose an amount the server
 * doesn't independently re-validate.
 *
 * provider_transaction_id carries a partial unique index so the SAME
 * provider transaction can never be attributed to two different
 * payment requests — the first, cheapest line of defense against a
 * duplicate/replayed provider confirmation, independent of and in
 * addition to ManualPaymentService's own idempotency-key mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_plan_installment_id')->nullable()->constrained('payment_plan_installments')->restrictOnDelete();

            $table->string('purpose');
            $table->string('amount_rule');
            $table->unsignedInteger('requested_amount_cents')->nullable();
            $table->string('currency', 3)->default('usd');

            $table->string('status')->default('draft');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();

            $table->string('provider_transaction_id')->nullable();
            $table->unsignedInteger('paid_amount_cents')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->text('failure_reason')->nullable();

            $table->foreignId('created_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'client_id']);
            $table->unique(['firm_id', 'provider_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
