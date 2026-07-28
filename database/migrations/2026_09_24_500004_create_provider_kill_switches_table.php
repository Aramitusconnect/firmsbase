<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_kill_switches — the incident-response kill-switch surface
 * (checkpoint4-design-cost-control.md §4.1; checkpoint4-combined-design.md
 * §1.7/§10). `scope_type='platform'` (scope_id NULL) rows are checked
 * broad-to-narrow by `level` (`product` -> `endpoint_category` ->
 * `operation`) — see `App\Integrations\Billing\ProviderOperationPolicyResolver`.
 *
 * GLOBAL — no RLS, no FORCE RLS (design §1.7, filled in by direct
 * analogy to `provider_rate_card_entries`'s own stated reasoning, since
 * the source doc left this table's RLS classification unstated): a
 * `scope_type='platform'` row has no owning firm and must be
 * visible/checked on every pipeline run for every firm, and this table
 * is admin-panel-mutated only, never firm-panel-writable.
 *
 * `scope_type='firm'` remains part of this table's schema (as the
 * design doc's own `Schema::create()` block specifies it), but this
 * checkpoint's resolver never writes or reads a `scope_type='firm'` row
 * here for FIRM-INITIATED optional-operation suspension — that
 * mechanism was moved onto the firm-owned, FORCE-RLS'd
 * `provider_firm_operation_policies.optional_operation_suspended`
 * column instead (see that migration's own docblock and
 * `App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException`'s
 * docblock for the full reasoning). Keeping the `scope_type='firm'`
 * column value here reserves the shape for a genuinely different future
 * need (a platform admin suspending one specific firm's operation as an
 * incident-response action, e.g. a firm whose usage pattern looks
 * fraudulent) without conflating it with the firm's own self-service
 * opt-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_kill_switches', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key');
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('level');
            $table->string('target');
            $table->boolean('suspended')->default(false);
            $table->string('reason')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_key', 'scope_type', 'scope_id', 'level', 'target'], 'provider_kill_switches_unique_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_kill_switches');
    }
};
