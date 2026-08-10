<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Predictive Matter Budget Alerts, item 6: "If cost category mapping
 * is needed: reuse ExpenseCategory." ExpenseCategory has no existing
 * classification field (a Firm's own category names are free text —
 * "Filing Fees" vs "Court Costs" vs "Postage" cannot be reliably
 * pattern-matched into MatterBudgetExpenseCategory's four closed
 * buckets), so this adds one nullable, explicit, Firm-set mapping
 * column rather than guessing from the name. An unmapped category's
 * expenses still count toward a Matter's total actual spend — they are
 * simply excluded from the per-bucket breakdown until a Firm maps
 * them, never silently dropped (see MatterBudgetAnalysisService's own
 * docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('budget_category')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('budget_category');
        });
    }
};
