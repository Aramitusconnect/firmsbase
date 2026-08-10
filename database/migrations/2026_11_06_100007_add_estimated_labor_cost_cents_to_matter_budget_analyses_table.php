<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leverage Ratio Optimizer, item 4/19. Another small, additive
 * extension (same rationale as cost_by_role_cents_json): estimatedMargin()
 * already derives an expected total labor cost internally to compute
 * estimated_margin_cents, then discards it. Persisting it lets
 * LeverageRecommendationService compare actual labor cost consumption
 * against the SAME expected-cost baseline the Matter Budget feature
 * already trusts, rather than re-deriving a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_budget_analyses', function (Blueprint $table) {
            $table->bigInteger('estimated_labor_cost_cents')->nullable()->after('estimated_margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('matter_budget_analyses', function (Blueprint $table) {
            $table->dropColumn('estimated_labor_cost_cents');
        });
    }
};
