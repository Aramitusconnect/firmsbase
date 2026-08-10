<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_budget_templates — Predictive Matter Budget Alerts, item 3.
 * Firm-scoped, reusable budget definitions keyed by an optional
 * practice_area_id/matter_type_id pair (matter_type_id alone already
 * implies its parent practice area — both are stored so a
 * practice-area-wide template with no specific matter type is also
 * expressible). A template is mutable Firm configuration; applying one
 * to a Matter always SNAPSHOTS it into a matter_budgets row rather than
 * having the Matter depend on this mutable row directly (see that
 * table's own docblock) — editing a template never silently rewrites
 * an already-open Matter's own budget.
 *
 * expected_hours_json is a closed map keyed by FirmUserRole values
 * (validated server-side against the enum, never an arbitrary key) to
 * an expected-hours number, e.g. {"attorney": 8, "paralegal": 15}. A
 * role absent from the map simply has no budget for that role — the
 * master spec's own "do not assume every firm uses every field."
 *
 * expected_expenses_json is a closed map keyed by
 * MatterBudgetExpenseCategory values (filing_court_costs,
 * vendor_expert_costs, reimbursable_costs, other_expenses) to an
 * expected-cents number — the exact four named categories the spec
 * lists, not an open/arbitrary category set (that closed vocabulary is
 * the "no arbitrary executable formulas" discipline applied to this
 * feature's own numeric inputs, mirroring the Automation Engine's
 * closed condition/action vocabularies).
 *
 * version increments on every edit to the numeric/hours/expense fields
 * (never on name/description/active alone) — matter_budgets snapshots
 * both source_template_id and source_template_version at apply time,
 * so it is always possible to tell exactly which template definition a
 * given Matter's budget was drawn from, even after the template is
 * later revised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_budget_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->foreignId('practice_area_id')->nullable()->constrained('practice_areas')->nullOnDelete();
            $table->foreignId('matter_type_id')->nullable()->constrained('matter_types')->nullOnDelete();

            $table->unsignedInteger('expected_duration_days')->nullable();
            $table->json('expected_hours_json');
            $table->json('expected_expenses_json');
            $table->bigInteger('expected_revenue_cents')->nullable();
            $table->unsignedInteger('target_gross_margin_percent')->nullable();

            $table->unsignedInteger('warning_threshold_percent')->default(75);
            $table->unsignedInteger('high_threshold_percent')->default(90);

            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('updated_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'active']);
            $table->index(['firm_id', 'practice_area_id']);
            $table->index(['firm_id', 'matter_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_budget_templates');
    }
};
