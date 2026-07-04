<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_subscriptions — PLATFORM billing only, keyed to
 * billing_account_id. Never keyed to firm_id directly and never
 * confused with Phase 3's firm-client payment_plans (project rule 1/8).
 * gateway_subscription_ref is a nullable string placeholder for a real
 * Stripe subscription id — never populated by a real API call in this
 * phase (FakeStripeGateway only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');

            $table->string('status')->default('trialing');
            $table->string('billing_interval')->default('monthly');

            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();

            $table->string('gateway_subscription_ref')->nullable();

            $table->timestamps();

            $table->index('billing_account_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscriptions');
    }
};
