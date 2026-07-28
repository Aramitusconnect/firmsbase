<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_operation_default_policies — the PLATFORM-DEFAULT half of the
 * coordinator-resolved two-table split
 * (checkpoint4-combined-design.md §1.8): "split into two tables — a
 * `provider_operation_default_policies` table (Global, no RLS,
 * admin-authored, one row per product/environment, the platform-default
 * fallback only) and a `provider_firm_operation_policies` table (Direct
 * `BelongsToTenant`, FORCE RLS, firm-editable, one row per firm/product
 * override)." `ProviderOperationPolicyResolver::resolve()` queries the
 * firm-scoped table first, falls back to this global table on miss.
 *
 * SCHEMA JUDGMENT CALL: the source design (`checkpoint4-design-cost-control.md`
 * §2 step 7) describes this table's CONTENT only in prose — "per-firm
 * connection-restriction/soft-limit/hard-limit/cooldown/cache-TTL row"
 * — with no `Schema::create()` block given anywhere (the pre-split,
 * singular `provider_operation_policies` table has no schema in the
 * source doc at all). The column shapes below are this implementation's
 * own fill-in, chosen to mirror the vocabulary the design's prose
 * already names one-to-one: `soft_limit_quantity`/`hard_limit_quantity`
 * (step 11's enforcement inputs), `limit_window_seconds` (the period
 * those limits are evaluated over — reuses `PerConnectionRateLimiter`'s
 * own window-based shape rather than inventing a "daily/monthly period"
 * enum), `cooldown_seconds` (step 10), `cache_ttl_seconds` (step 8).
 * GLOBAL — no RLS, no FORCE RLS: admin-authored reference data, same
 * structural shape and justification as `provider_rate_card_entries`/
 * `provider_kill_switches`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_operation_default_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('provider_key');
            $table->string('product');
            $table->string('environment');

            $table->unsignedInteger('soft_limit_quantity')->nullable();
            $table->unsignedInteger('hard_limit_quantity')->nullable();
            $table->unsignedInteger('limit_window_seconds')->default(86400);
            $table->unsignedInteger('cooldown_seconds')->nullable();
            $table->unsignedInteger('cache_ttl_seconds')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['provider_key', 'product', 'environment'], 'provider_operation_default_policies_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_operation_default_policies');
    }
};
