<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_payments — PLATFORM billing only, keyed to
 * billing_account_id. Deliberately separate from Phase 3's payments
 * table (project rule 1/8). classification reuses the EXISTING
 * PaymentClassification enum column type (string) but is ALWAYS
 * written as 'operating_payment' by PlatformBillingClassificationService
 * — platform billing can never be trust/IOLTA money and is never
 * blocked-classification money (no trust deposits are ever collected
 * this way). No FK/CHECK constraint enforces this in the database;
 * PlatformBillingClassificationService is the sole writer, matching how
 * PaymentClassificationService is the sole writer for Phase 3's
 * payments.classification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->string('classification')->default('operating_payment');
            $table->unsignedBigInteger('amount_cents');

            $table->string('gateway_payment_ref')->nullable();

            $table->timestamp('attempted_at');
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index('billing_account_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payments');
    }
};
