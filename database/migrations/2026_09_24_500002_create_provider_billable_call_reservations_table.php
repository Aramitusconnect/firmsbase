<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_billable_call_reservations — the two-phase reserve/finalize
 * state machine (checkpoint4-design-cost-control.md §3.1;
 * checkpoint4-combined-design.md §8.4). `status`:
 * `reserved` -> `finalized_billable` | `finalized_non_billable` |
 * `finalized_uncertain` | `expired`. Direct `BelongsToTenant` + FORCE
 * RLS — standard shape, same composite-FK-to-firm_integrations pattern
 * `integration_usage_records` already establishes.
 *
 * `rate_card_entry_id`/`estimated_customer_price_cents` are SNAPSHOTTED
 * at reservation time (step 12), never re-resolved at finalize time
 * (step 15) — the same historical-correctness reasoning
 * `employee_rates`'s own docblock argues for (design §3.4).
 *
 * Append-then-one-transition, not literally append-only like
 * `integration_usage_records` — no `booted()` immutability guard on the
 * model; `App\Integrations\Billing\ProviderUsageReservationService` is
 * the sole writer/transitioner, mirroring `TrustLedgerEntry`'s "exactly
 * one authorized service per lifecycle stage" discipline rather than
 * its stricter never-update guard (design §3.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_billable_call_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('firm_integration_id');

            $table->string('provider_key');
            $table->string('product');
            $table->string('billing_operation');
            $table->string('environment');

            $table->foreignId('rate_card_entry_id')->nullable()->constrained('provider_rate_card_entries')->nullOnDelete();
            $table->unsignedInteger('estimated_customer_price_cents')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit')->default('request');

            $table->string('status');

            $table->string('idempotency_key');
            $table->string('correlation_id')->nullable();

            $table->foreignId('usage_record_id')->nullable()->constrained('integration_usage_records')->nullOnDelete();

            $table->timestamp('reserved_at');
            $table->timestamp('expires_at');
            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('reserved_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->string('reservation_reason')->nullable();

            $table->timestamps();

            $table->unique(['firm_integration_id', 'idempotency_key']);
            $table->index(['firm_id', 'status', 'reserved_at']);
            $table->index('expires_at');

            $table->foreign(['firm_id', 'firm_integration_id'], 'provider_billable_call_reservations_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_billable_call_reservations');
    }
};
