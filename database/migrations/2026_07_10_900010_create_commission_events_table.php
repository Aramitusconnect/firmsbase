<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * commission_events — keyed to billing_account_id and Phase 6 platform
 * billing records ONLY. Deliberately carries no foreign key to
 * invoices/payments/payment_plans/manual_payment_records (Phase 3
 * firm-client billing) — project rule: commission must never reference
 * firm-client invoices/payments. attributable_type/attributable_id is a
 * polymorphic reference to whatever expansion action generated this
 * commission (e.g. OrgLicense, SeatAllocation, PlatformSubscriptionItem).
 * The unique constraint below is what makes "organization expansion
 * attributes once to the billing account" true at the database level,
 * not just by service-layer discipline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('commission_plan_id')->constrained('commission_plans')->restrictOnDelete();
            $table->foreignId('billing_account_id')->constrained('billing_accounts')->cascadeOnDelete();
            $table->foreignId('platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->foreignId('platform_invoice_id')->nullable()->constrained('platform_invoices')->nullOnDelete();
            $table->foreignId('platform_payment_id')->nullable()->constrained('platform_payments')->nullOnDelete();

            $table->string('attributable_type')->nullable();
            $table->unsignedBigInteger('attributable_id')->nullable();

            $table->string('event_type');
            $table->string('status')->default('pending');
            $table->unsignedInteger('amount_cents');

            $table->timestamp('holding_period_ends_at')->nullable();
            $table->string('blocked_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['billing_account_id', 'attributable_type', 'attributable_id', 'event_type'],
                'commission_events_attribution_unique'
            );
            $table->index('billing_account_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_events');
    }
};
