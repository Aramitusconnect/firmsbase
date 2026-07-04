<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_payment_attempts — one row per attempt to collect a platform
 * invoice, including failed attempts, via FakeStripeGateway. Distinct
 * from platform_payments: an invoice can accrue several attempts before
 * (or without ever) producing a succeeded platform_payments row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();

            $table->string('status')->default('attempted');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('gateway_response_code')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('attempted_at');

            $table->timestamps();

            $table->index('billing_account_id');
            $table->index('platform_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_attempts');
    }
};
