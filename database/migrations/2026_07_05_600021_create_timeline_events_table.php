<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * timeline_events — append-only, generic activity log spanning every
 * future phase (lead created, consultation held, client created,
 * matter opened today; document uploaded, task created, invoice
 * created, payment recorded, AI action in later phases). event_type is
 * a PLAIN STRING (approved decision), not a closed enum — later phases
 * add new event types without ever touching this table's migration or
 * this model. TimelineEventRecorder is the single write path; no other
 * service should insert rows here directly.
 *
 * subject_type/subject_id form a lightweight polymorphic reference to
 * whatever record the event is about (a FirmLead, a Matter, a Client,
 * ...), same pattern as conflict_check_results.matched_type/matched_id.
 *
 * Carries a public uuid (HasPublicUuid) — unlike security_events,
 * individual timeline events are expected to be exposed later in
 * matter activity feeds, client portal activity, notifications, APIs,
 * and admin review screens, so the internal bigint id must never be
 * the identifier used there. UPDATED_AT disabled at the model layer,
 * created_at only, rows never mutated after insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('event_type');

            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
