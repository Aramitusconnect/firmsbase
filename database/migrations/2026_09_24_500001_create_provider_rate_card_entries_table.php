<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * provider_rate_card_entries — FirmsVault Live Integrations, Checkpoint
 * 4 (checkpoint4-design-cost-control.md §1.1; checkpoint4-combined-design.md
 * §8.2/§10). Effective-dated (`employee_rates`'s own open/close-row
 * pattern, see database/migrations/2026_07_06_700001_create_employee_rates_table.php),
 * three-tier precedence (`platform_default` < `package_default` <
 * `firm_override`), byte-for-byte mirroring
 * `App\Enums\EntitlementSource`'s own precedence shape rather than a
 * fourth, new one.
 *
 * `provider_key`/`product`/`billing_operation`/`environment` are plain,
 * governed strings, never DB enums — matches this domain's own
 * established "new products/operations arrive without a migration"
 * precedent (e.g. `integration_usage_records.provider_key`/`capability`).
 *
 * `provider_cost_cents`/`customer_price_cents` are NULLABLE and NEVER
 * coalesced to 0 by any consumer — "unknown," not "free," is the honest
 * state for an unpriced-yet product (design §1.3). `included_allowance_quantity`
 * NULL means "no included allowance modeled at this scope" (falls
 * through to a lower-precedence scope, or "no allowance" if none exists
 * anywhere).
 *
 * GLOBAL — no RLS, no FORCE RLS (design §1.1). Even a `firm_override`
 * row's `scope_id` merely POINTS AT a firm; the row itself is
 * platform-admin-authored reference data, mirroring `Plan`/`PlanModule`'s
 * own "platform reference/commercial data, not owned by one firm"
 * framing, never `firm_entitlements`' tenant-owned shape. Mutation is
 * gated by an admin-only Filament action (a later checkpoint's UI
 * concern), never firm-panel-writable.
 *
 * The two partial unique indexes below enforce exactly one OPEN-ENDED
 * row per (provider_key, product, billing_operation, environment,
 * scope_type[, scope_id]) — split in two because Postgres never treats
 * `NULL = NULL` as a match for a plain unique index, matching
 * `provider_rate_card_one_open_entry_scoped`/`_platform`'s own naming
 * from the design doc verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_rate_card_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('provider_key');
            $table->string('product');
            $table->string('billing_operation');
            $table->string('environment');

            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->unsignedInteger('provider_cost_cents')->nullable();
            $table->unsignedInteger('customer_price_cents')->nullable();
            $table->string('currency', 3)->default('usd');

            $table->unsignedInteger('included_allowance_quantity')->nullable();
            $table->unsignedInteger('overage_price_cents')->nullable();
            $table->string('unit')->default('request');

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['provider_key', 'product', 'billing_operation', 'environment', 'scope_type'], 'provider_rate_card_entries_lookup_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX provider_rate_card_one_open_entry_scoped '.
            'ON provider_rate_card_entries (provider_key, product, billing_operation, environment, scope_type, scope_id) '.
            'WHERE effective_to IS NULL AND scope_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX provider_rate_card_one_open_entry_platform '.
            'ON provider_rate_card_entries (provider_key, product, billing_operation, environment, scope_type) '.
            'WHERE effective_to IS NULL AND scope_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_rate_card_entries');
    }
};
