<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * domain_events — Event-Driven Automation Engine, item 1/12. The
 * internal domain-event log the automation engine's rule matcher reacts
 * to. Deliberately NOT WebhookEventType/webhook_events (closed to
 * exactly 11 outbound-integration-shaped cases, entitlement-gated per
 * firm, minimized-payload-for-third-parties by design — reusing it here
 * would silently disable automation for any firm without the webhook
 * module enabled) and NOT TimelineEventRecorder/timeline_events (a
 * write-only, open-string audit sink with zero subscriber mechanism).
 *
 * This table IS the transactional outbox, not a queue fed by one: every
 * writer (DomainEventRecorderService) inserts a row INSIDE the SAME
 * database transaction as the domain mutation that caused it — so "the
 * business transaction rolls back -> the event never exists" is true by
 * construction, not by any afterCommit-timing convention. This is a
 * deliberate improvement over the existing DB::afterCommit()-then-
 * create-a-fresh-row pattern used for webhook fan-out (13 call sites,
 * audited before this migration was written) — that pattern has a real
 * crash-window gap (process dies between commit and the afterCommit
 * callback running -> the webhook event is silently never recorded);
 * writing in-transaction here closes that gap for automation.
 *
 * A separate sweep (AutomationExecutionEngine, run on a schedule) claims
 * unprocessed rows via the same SKIP LOCKED pattern already proven in
 * app/Integrations/Outbox/ (IntegrationOutboxEventService) — reused in
 * spirit here, not literally repointed: that table is hardwired to
 * App\Integrations\* resource/health-signal semantics, a different
 * bounded context from general domain automation.
 *
 * correlation_id/causation_event_id/causation_depth exist specifically
 * for loop prevention (item 11): an event ORGANICALLY caused by real
 * business activity gets a fresh correlation_id and causation_depth=0;
 * an event caused BY an automation action executing gets the SAME
 * correlation_id as the event that triggered that automation, a
 * causation_event_id pointing at it, and causation_depth = that event's
 * own depth + 1. AutomationExecutionEngine refuses to process any event
 * whose causation_depth exceeds a fixed maximum, breaking any possible
 * automation-triggers-automation cycle deterministically rather than
 * relying on convention alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->uuid('correlation_id');
            $table->foreignId('causation_event_id')->nullable()->constrained('domain_events')->nullOnDelete();
            $table->unsignedTinyInteger('causation_depth')->default(0);

            $table->json('payload_json');

            $table->string('processing_status')->default('pending');
            $table->uuid('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'processing_status', 'next_attempt_at'], 'domain_events_claim_idx');
            $table->index(['firm_id', 'event_type']);
            $table->index('correlation_id');
            $table->index('causation_event_id');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
