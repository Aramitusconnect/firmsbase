<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_invoice_reconciliations — monthly provider invoice
 * reconciliation, modeled directly on `TrustReconciliationService`'s own
 * human-entered, comparison-only, never-auto-correcting pattern
 * (checkpoint4-design-cost-control.md §6). PLATFORM-SCOPED, not
 * per-firm: a real provider invoice covers all firms' aggregated usage
 * — the same relationship `BillingAccount`/`PlatformBillingEvent` already
 * have to the platform as a whole.
 *
 * GLOBAL — no RLS, platform-scoped like `Plan` (checkpoint4-combined-design.md
 * §10). `system_recorded_total_cents` is a SUM over
 * `finalized_billable` reservations with a non-null
 * `estimated_customer_price_cents` — null-cost rows are excluded, never
 * zeroed (design §1.3). `discrepancy_cents` is `system - asserted`,
 * signed. Never auto-corrected — a discrepancy is recorded as-is;
 * fixing a mispriced rate-card entry going forward is a separate,
 * deliberate `provider_rate_card_entries` edit, never a side effect of
 * running a reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_invoice_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider_key');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('asserted_invoice_total_cents');
            $table->unsignedInteger('system_recorded_total_cents');
            $table->integer('discrepancy_cents');
            $table->string('status');
            $table->foreignId('performed_by')->constrained('platform_admins');
            $table->timestamp('completed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['provider_key', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_invoice_reconciliations');
    }
};
