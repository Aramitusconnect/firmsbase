<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_firm_operation_policies — the FIRM-EDITABLE half of the
 * coordinator-resolved two-table split (checkpoint4-combined-design.md
 * §1.8). One row per firm/product/environment override. Direct
 * `BelongsToTenant`, FORCE RLS (companion migration) — a firm genuinely
 * owns and edits its own row here
 * (checkpoint4-combined-design.md §9.4: "`PlaidUsagePolicyPage`... the
 * one legitimate Create/Edit-shaped surface in this whole design —
 * edits the firm's own row in `provider_firm_operation_policies`").
 *
 * `optional_operation_suspended` is the firm's own self-service
 * per-product opt-out (see
 * `App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException`'s
 * docblock for why this lives here rather than as a firm-scope
 * `provider_kill_switches` row — a coordinator-decision-consistent
 * judgment call made by this implementation, since the pre-split source
 * design left this specific mechanical detail ambiguous between the two
 * tables). Every limit/cooldown/cache-TTL column is nullable — a null
 * value here means "no firm override for this field," and
 * `ProviderOperationPolicyResolver::resolve()` falls back to
 * `provider_operation_default_policies`' value, per-field, not
 * per-row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_firm_operation_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('provider_key');
            $table->string('product');
            $table->string('environment');

            $table->boolean('optional_operation_suspended')->default(false);

            $table->unsignedInteger('soft_limit_quantity')->nullable();
            $table->unsignedInteger('hard_limit_quantity')->nullable();
            $table->unsignedInteger('limit_window_seconds')->nullable();
            $table->unsignedInteger('cooldown_seconds')->nullable();
            $table->unsignedInteger('cache_ttl_seconds')->nullable();

            $table->foreignId('updated_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'provider_key', 'product', 'environment'], 'provider_firm_operation_policies_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_firm_operation_policies');
    }
};
