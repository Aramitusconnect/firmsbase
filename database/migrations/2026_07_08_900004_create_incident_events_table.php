<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * incident_events — append-only. correlation_id ties every event
 * belonging to the same incident together (opened, severity changed,
 * status updated, root cause recorded, resolved) — the exact same
 * pattern Phase 4's notification_events.correlation_id already
 * established. Deliberately NO separate "incidents" parent table:
 * the current state of an incident is always "the latest row for this
 * correlation_id," and its timeline is "every row for this
 * correlation_id, in order" — this keeps Phase 5 inside its approved
 * 7-table data contract instead of adding an 8th speculative table.
 * event_type is a plain string (approved clarification) — e.g.
 * "opened", "severity_changed", "status_changed", "root_cause_added",
 * "resolved". severity/status are strict enums carried on every row
 * (each row states the then-current value). firm_id is nullable: null
 * for a platform-wide incident, non-null for a firm-specific one (e.g.
 * a tenant isolation anomaly escalated to an incident).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->uuid('correlation_id');

            $table->string('event_type');
            $table->string('severity');
            $table->string('status');
            $table->boolean('customer_impact')->default(false);
            $table->boolean('notification_needed')->default(false);
            $table->text('root_cause')->nullable();
            $table->text('resolution')->nullable();
            $table->text('message')->nullable();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index('correlation_id');
            $table->index(['firm_id', 'status']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
