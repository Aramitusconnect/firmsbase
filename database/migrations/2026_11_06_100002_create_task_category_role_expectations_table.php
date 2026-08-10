<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_category_role_expectations — Leverage Ratio Optimizer, item 7/
 * 8. A genuinely separate table from matter_budget_templates
 * (confirmed by this pass's own audit: templates are one row per
 * firm/practice-area/matter-type, versioned as a whole budget unit;
 * this is one row per firm/task-category, an entirely different
 * grain that doesn't version with a budget and applies whether or not
 * a Matter even has one). recommended_roles_json is a small array of
 * FirmUserRole values ("Document Follow-Up: recommended Paralegal,
 * Legal Assistant") — never a single hardcoded role, and never a
 * built-in assumption: a category with NO row here has no Firm
 * opinion at all, and TaskRoleMismatch simply cannot fire for it
 * (Confidence/LOW system-wide patterns are the only fallback signal,
 * see LeverageRecommendationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_category_role_expectations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('task_category');
            $table->json('recommended_roles_json');
            $table->text('notes')->nullable();

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('updated_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['firm_id', 'task_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_category_role_expectations');
    }
};
