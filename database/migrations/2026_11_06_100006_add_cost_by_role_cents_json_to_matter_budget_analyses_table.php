<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leverage Ratio Optimizer, item 4. A small, additive extension to the
 * EXISTING matter_budget_analyses table/service rather than a second
 * cost calculator: MatterBudgetAnalysisService already derives a
 * per-user actual labor cost internally (to sum into
 * total_labor_cost_cents) — this persists that same derivation broken
 * out BY ROLE instead of discarding the breakdown, so
 * LeverageAnalysisService can read cost-by-role directly from this
 * canonical row rather than re-querying TimeEntry/EmployeeRate itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_budget_analyses', function (Blueprint $table) {
            $table->json('cost_by_role_cents_json')->nullable()->after('total_labor_cost_cents');
        });
    }

    public function down(): void
    {
        Schema::table('matter_budget_analyses', function (Blueprint $table) {
            $table->dropColumn('cost_by_role_cents_json');
        });
    }
};
