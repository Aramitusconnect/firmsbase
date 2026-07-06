<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_ai_settings — one row per firm, the detailed AI controls table
 * (approved decision #2). Does NOT duplicate ai_mode — firm_settings.
 * ai_mode (Phase 1) remains the single source of truth for AI mode;
 * this table holds only the controls listed in the Master Plan Phase
 * 15 scope: allowed providers/models, token/budget limits, usage
 * markup, document/client-data context toggles, high-risk approval
 * requirement, and full-content logging policy.
 *
 * high_risk_requires_approval defaults to true and is retained for
 * audit/UI visibility, but AiApprovalWorkflowService does NOT treat it
 * as a real bypass switch for the six named high-risk categories —
 * approval before use for those categories is mandatory per project
 * rules 15/19/20, not admin-configurable. See
 * AiApprovalWorkflowService's own docblock.
 *
 * full_content_logging_enabled defaults to false (project rule 11/12:
 * no training/full logging unless the firm explicitly opts in under
 * documented policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete();

            $table->json('allowed_providers_json')->nullable();
            $table->json('allowed_models_json')->nullable();

            $table->unsignedBigInteger('token_limit_per_period')->nullable();
            $table->unsignedBigInteger('budget_limit_cents_per_period')->nullable();
            $table->unsignedInteger('usage_markup_basis_points')->default(0);

            $table->boolean('document_context_enabled')->default(false);
            $table->boolean('client_data_context_enabled')->default(false);
            $table->boolean('high_risk_requires_approval')->default(true);
            $table->boolean('full_content_logging_enabled')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_ai_settings');
    }
};
