<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_large_deposit_thresholds — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7;
 * checkpoint4-combined-design.md §1.6, found-and-fixed RLS
 * misclassification). Scope shape IDENTICAL to
 * `provider_rate_card_entries`'s own `platform_default` ->
 * `firm_override` precedence pattern (no "package" tier needed here
 * since this is a detection threshold, not a price).
 *
 * GLOBAL — no RLS, no FORCE RLS, exactly per checkpoint4-combined-design.md
 * §1.6's binding reconciliation: this table's `platform_default` row
 * (no owning firm — `scope_id IS NULL`) must be visible to every firm,
 * and Direct `BelongsToTenant` + ordinary symmetric FORCE RLS
 * structurally cannot represent a firm-agnostic default row at all —
 * the SAME reasoning `provider_rate_card_entries` already establishes
 * for its own `platform_default` row. Mutation gated by an admin-only
 * Filament action (`FinancialEvidenceLargeDepositThresholdResource`,
 * out of this checkpoint's page list — the platform-default row is
 * seeded via config fallback, `config('financial_evidence.large_deposit_default_threshold_cents')`,
 * only a `firm_override` row requires an actual table write, which
 * `FinancialEvidenceLargeDepositDetectionService::resolveThresholdCents()`
 * is the sole reader of), never firm-panel-writable directly (a firm
 * requests an override; only FirmOwner/Attorney/BillingStaff via
 * `FinancialIntegrationAccessPolicyService::canRequest()` may write a
 * `firm_override` row for their OWN firm — this is the one exception
 * to "admin-only" this table allows, since a detection threshold
 * override is a firm's own tuning preference, not commercial/pricing
 * data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_large_deposit_thresholds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('scope_type'); // platform_default|firm_override
            $table->unsignedBigInteger('scope_id')->nullable(); // firm_id for firm_override, null for platform_default

            $table->unsignedBigInteger('threshold_cents');

            $table->timestamps();

            $table->unique(['scope_type', 'scope_id'], 'financial_evidence_large_deposit_thresholds_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_large_deposit_thresholds');
    }
};
