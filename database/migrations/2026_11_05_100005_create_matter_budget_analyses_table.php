<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_budget_analyses — Predictive Matter Budget Alerts, item 10.
 * ONE current row per Matter (unique matter_id), recomputed IN PLACE
 * by MatterBudgetAnalysisService — mirrors matter_readiness_scores'
 * own "one current row, recomputed in place" shape exactly, not a
 * history of past computations (matter_budgets already carries the
 * versioned history of what was EXPECTED; this table only ever
 * reflects the latest computed comparison against whichever
 * matter_budgets row is current at computation time).
 *
 * Every *_json breakdown column is keyed by the same closed
 * FirmUserRole/MatterBudgetExpenseCategory vocabularies
 * matter_budgets itself uses — never an open/arbitrary key.
 *
 * All monetary/percentage outputs here are DERIVED, never a source of
 * truth: actual hours still come from time_entries, actual expenses
 * from expenses, revenue from invoices/payments — this table is a
 * queryable cache of that comparison, safely rebuildable at any time
 * from those canonical tables plus the current matter_budgets row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_budget_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('matter_budget_id')->constrained('matter_budgets')->cascadeOnDelete();

            $table->json('hours_by_role_json');
            $table->json('expenses_by_category_json');

            $table->bigInteger('total_labor_cost_cents')->nullable();
            $table->bigInteger('total_expenses_cents');

            $table->bigInteger('revenue_expected_cents')->nullable();
            $table->bigInteger('revenue_invoiced_cents')->nullable();
            $table->bigInteger('revenue_collected_cents')->nullable();
            $table->bigInteger('revenue_outstanding_cents')->nullable();

            $table->integer('estimated_margin_cents')->nullable();
            $table->integer('estimated_margin_percent')->nullable();
            $table->integer('current_margin_cents')->nullable();
            $table->integer('current_margin_percent')->nullable();

            $table->unsignedInteger('work_completion_percent');
            $table->json('work_completion_breakdown_json');
            $table->unsignedInteger('time_elapsed_percent')->nullable();

            $table->json('projected_hours_by_role_json');
            $table->json('projected_overrun_hours_by_role_json');
            $table->bigInteger('projected_final_cost_cents')->nullable();
            $table->integer('projected_margin_cents')->nullable();
            $table->integer('projected_margin_percent')->nullable();

            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique('matter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_budget_analyses');
    }
};
