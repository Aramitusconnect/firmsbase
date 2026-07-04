<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_plans — a schedule, never a parallel ledger (project rule).
 * total_cents is the sum of installment amounts at creation/
 * renegotiation time, not a running balance. supersedes_payment_plan_id
 * implements the PDF's renegotiation rule: "New plan version supersedes;
 * prior installments retain history" — renegotiation creates a NEW row
 * pointing back at the old one, rather than mutating the old plan's
 * installments in place. Carries a public uuid — the client portal
 * needs to show/link a specific plan (PDF: "Mobile payment-plan
 * visibility... in the portal").
 *
 * No dunning_policy_id column: the PDF's own appendix references this
 * column, but no dunning_policies table is defined anywhere in the
 * plan's data contract or table-family list. PaymentPlanDunningService
 * applies one fixed default policy in code instead of a speculative
 * table (flagged to the user; proceeding this way per their approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('status')->default('draft');
            $table->unsignedInteger('total_cents');
            $table->string('currency', 3)->default('usd');
            $table->unsignedInteger('installment_count');

            $table->foreignId('supersedes_payment_plan_id')->nullable()->constrained('payment_plans')->nullOnDelete();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('renegotiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('defaulted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'client_id']);
            $table->index('matter_id');
            $table->index('invoice_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
