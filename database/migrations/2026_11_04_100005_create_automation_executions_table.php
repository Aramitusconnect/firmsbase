<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * automation_executions — Event-Driven Automation Engine, item 9. One
 * row per (automation_rule, domain_event) match attempt — the audit
 * record of "did this rule apply to this event, and what happened."
 * The unique index on (automation_rule_id, domain_event_id) IS the
 * execution-level idempotency guarantee: the claim/match step is itself
 * idempotent by database constraint, not merely by convention — a
 * redelivered/re-swept domain_events row can never produce a second
 * execution row for the same rule.
 *
 * rule_version snapshots automation_rules.version at match time, so
 * editing a rule later never rewrites what an already-recorded
 * execution says decided its outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->foreignId('domain_event_id')->constrained('domain_events')->restrictOnDelete();

            $table->unsignedInteger('rule_version');
            $table->json('conditions_evaluated_json');
            $table->boolean('matched');
            $table->string('status')->default('pending');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->unique(['automation_rule_id', 'domain_event_id']);
            $table->index(['firm_id', 'status']);
            $table->index('domain_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
    }
};
