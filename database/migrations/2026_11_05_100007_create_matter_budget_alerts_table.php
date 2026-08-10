<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_budget_alerts — Predictive Matter Budget Alerts, item 12/13/
 * 15/17. Serves two purposes at once: the deduplication CHECKPOINT
 * ("crossed 75% -> one alert; still at 77% -> no repeat; crosses 90%
 * -> new alert allowed", the spec's own example) AND the queryable
 * "Open Alerts" list the Matter UI surfaces.
 *
 * Dedup key: unique(matter_budget_id, alert_type, metric_key,
 * threshold_percent_crossed). Scoped to matter_budget_id (not
 * matter_id) deliberately — a NEW matter_budgets revision (item 20)
 * always gets a fresh alert slate, since a revised budget legitimately
 * changes what "75% consumed" even means; stale alerts from a
 * superseded budget version are never carried forward or re-triggered
 * by editing the budget. Once a row exists for a given tier, that
 * exact tier is NEVER re-alerted for this budget version, even if
 * resolved and the metric re-crosses it later — the checkpoint model
 * the spec's own item 15 explicitly sanctions.
 *
 * threshold_percent_crossed is NOT NULL (Postgres treats NULL as
 * distinct in a unique index, which would silently defeat dedup for
 * any alert type that left it null) — every alert type stores a real
 * tier number (75/90/100 for percent-based metrics) or the fixed
 * sentinel 100 for a boolean-style comparative alert (e.g.
 * MarginBelowTarget, UsageAheadOfProgress) that has no percent tier of
 * its own, so the unique index always does real work.
 *
 * metric_snapshot_json stores the underlying evidence at alert time
 * (e.g. {"consumed_percent": 72, "progress_percent": 35}) so the
 * "This matter has used 72% of its expected paralegal time while only
 * 35%..." style statement (item 13) is always reconstructable and
 * auditable, never re-derived from possibly-since-changed live data.
 *
 * domain_event_id links to the DomainEvent this alert caused to be
 * emitted (MatterBudgetThresholdCrossed) — nullable only because a row
 * could theoretically be recorded before the event insert commits in
 * the same transaction; in practice both always exist together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_budget_alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('matter_budget_id')->constrained('matter_budgets')->cascadeOnDelete();

            $table->string('alert_type');
            $table->string('metric_key');
            $table->string('severity');
            $table->unsignedInteger('threshold_percent_crossed');
            $table->json('metric_snapshot_json');

            $table->foreignId('domain_event_id')->nullable()->constrained('domain_events')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['matter_budget_id', 'alert_type', 'metric_key', 'threshold_percent_crossed'], 'matter_budget_alerts_dedup_unique');
            $table->index(['firm_id', 'matter_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_budget_alerts');
    }
};
