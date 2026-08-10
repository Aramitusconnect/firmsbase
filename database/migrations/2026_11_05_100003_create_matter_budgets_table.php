<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_budgets — Predictive Matter Budget Alerts, item 4/20. A
 * Matter's own budget, APPEND-ONLY per matter (one row per revision,
 * unique(matter_id, version), never updated in place) — the explicit
 * "preserve history, do not overwrite silently" requirement. The
 * current budget for a Matter is simply the highest-version row for
 * that matter_id; MatterBudgetService is the only writer.
 *
 * source_template_id/source_template_version record which
 * matter_budget_templates row (and which VERSION of it) this snapshot
 * was drawn from, if any — nullable, since a Firm may build a
 * Matter-specific budget with no template at all. Editing the source
 * template afterward never mutates this row.
 *
 * change_reason is required (service-enforced, not a DB constraint —
 * the very first revision naturally has none) whenever a NEW version
 * is created for a matter that already had one, per the spec's own
 * explicit "record: old budget, new budget, changed_by, changed_at,
 * reason."
 *
 * Column shapes mirror matter_budget_templates exactly (closed
 * FirmUserRole-keyed expected_hours_json, closed
 * MatterBudgetExpenseCategory-keyed expected_expenses_json).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('source_template_id')->nullable()->constrained('matter_budget_templates')->nullOnDelete();
            $table->unsignedInteger('source_template_version')->nullable();

            $table->unsignedInteger('expected_duration_days')->nullable();
            $table->json('expected_hours_json');
            $table->json('expected_expenses_json');
            $table->bigInteger('expected_revenue_cents')->nullable();
            $table->unsignedInteger('target_gross_margin_percent')->nullable();

            $table->unsignedInteger('warning_threshold_percent')->default(75);
            $table->unsignedInteger('high_threshold_percent')->default(90);

            $table->text('change_reason')->nullable();
            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['matter_id', 'version']);
            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_budgets');
    }
};
