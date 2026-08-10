<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * matter_leverage_recommendations — Leverage Ratio Optimizer, item 12/
 * 13/23/24. Deliberately different dedup semantics from
 * matter_budget_alerts' own "never again, ever, for this exact tier"
 * checkpoint model: a leverage recommendation's underlying condition
 * (a Matter's ongoing staffing mix) isn't tied to a versioned budget
 * revision, so item 23's own explicit "resolved then recurs -> new
 * recommendation may be allowed" is the correct behavior here. The
 * partial unique index below enforces "at most one OPEN or
 * ACKNOWLEDGED recommendation per (matter, type, dedup_key) at a
 * time" at the database layer — once a row leaves that pair of
 * statuses (Dismissed/Resolved/Stale), a fresh one may be created for
 * the same key if the condition still (or again) applies.
 *
 * dedup_key narrows scope WITHIN a recommendation_type — e.g. for
 * TaskRoleMismatch it is the task_category value itself, so a mismatch
 * on "Document Follow-Up" and one on "Data Entry" are tracked
 * independently even on the same Matter.
 *
 * evidence_json is the full underlying-metrics snapshot (item 13) —
 * hours, cost-rate-derived figures where authorized, confidence basis
 * — captured at creation time so a later recommendation is never
 * displayed without the data that justified it, even if live figures
 * have since moved on.
 *
 * matter_id is nullable: STAFF_BOTTLENECK/OVER_CAPACITY/
 * UNDERUTILIZED_CAPACITY (item 17/21) are staff-level signals, not
 * tied to any single Matter — user_id identifies the staff member
 * instead for those three types. The partial unique index uses
 * COALESCE(matter_id, 0) rather than the bare column: Postgres never
 * treats two NULLs as equal for uniqueness purposes, which would
 * silently defeat dedup for every staff-level recommendation
 * otherwise (mirrors the exact same NULL-uniqueness gotcha
 * matter_budget_alerts' own migration already documents for
 * threshold_percent_crossed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_leverage_recommendations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('recommendation_type');
            $table->string('dedup_key');
            $table->string('confidence');
            $table->string('status')->default('open');
            $table->json('evidence_json');

            $table->foreignId('domain_event_id')->nullable()->constrained('domain_events')->nullOnDelete();

            $table->foreignId('acknowledged_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('dismissed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id', 'status']);
            $table->index(['firm_id', 'user_id', 'status']);
        });

        // Partial unique index — Laravel's schema builder has no native
        // WHERE-clause unique index support, hence raw SQL.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX matter_leverage_recommendations_open_dedup_unique
            ON matter_leverage_recommendations (COALESCE(matter_id, 0), COALESCE(user_id, 0), recommendation_type, dedup_key)
            WHERE status IN ('open', 'acknowledged')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_leverage_recommendations');
    }
};
