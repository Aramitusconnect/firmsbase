<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payments — the ONE canonical payment table (project rule: "must be
 * reusable later by Phase 6 Stripe flows and Phase 13 trust
 * accounting"). A row is created for EVERY attempt, including blocked
 * ones — this is deliberate: the canonical table is the durable,
 * queryable record that a trust/IOLTA deposit attempt was made and
 * rejected, not just an entry in the classification-event log. A
 * blocked row's status can never become Succeeded.
 *
 * payment_classification is the strict PaymentClassification enum
 * (never a plain string) — set only by PaymentClassificationService.
 * client_id is required (every canonical payment belongs to a client);
 * matter_id and invoice_id are nullable, deviating from the PDF
 * appendix's unmarked-as-nullable formatting, because a payment can
 * exist against a payment-plan installment with no direct invoice, or
 * before a matter is opened (flagged to the user).
 *
 * idempotency_key + the partial unique index below is the database-
 * level backstop for double-submission protection (project rule 7);
 * ManualPaymentService's check-then-create idempotent-replay logic is
 * the primary mechanism, this index is defense-in-depth for a genuine
 * concurrent race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_plan_installment_id')->nullable()->constrained('payment_plan_installments')->nullOnDelete();

            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('usd');
            $table->string('payment_method');
            $table->string('payment_classification');
            $table->string('status')->default('initiated');

            $table->string('external_reference')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'client_id']);
            $table->index('matter_id');
            $table->index('invoice_id');
            $table->index('payment_plan_installment_id');
            $table->index('status');
            $table->index('payment_classification');
        });

        DB::statement(
            'CREATE UNIQUE INDEX payments_one_per_firm_idempotency_key '.
            'ON payments (firm_id, idempotency_key) WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
