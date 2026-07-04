<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_invoices — PLATFORM billing only, keyed to billing_account_id.
 * Deliberately separate from Phase 3's invoices table (firm-client
 * billing) — never mixed, never reused (project rule 1/8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('platform_subscription_id')->nullable()->constrained('platform_subscriptions')->nullOnDelete();

            $table->string('status')->default('draft');

            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');

            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);

            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();

            $table->index('billing_account_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
