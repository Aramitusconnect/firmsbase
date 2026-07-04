<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * status_page_events — platform-level, deliberately NO firm_id (same
 * pattern as Phase 4's readiness_scorecard_components global catalog)
 * — a public status page belongs to FirmsBase the platform, not to
 * any one tenant, so it is excluded from the Phase 5 RLS extension
 * migration. Carries a public uuid (approved conservative-uuid-scope
 * pattern) because a real status page must link to a specific update
 * without ever exposing an internal bigint id.
 *
 * correlation_id ties one post's own timeline together (published ->
 * updated -> resolved), independent from any incident's own
 * correlation_id. incident_correlation_id is a plain uuid column, not
 * a foreign key — it links to incident_events.correlation_id, which
 * is itself not a unique/primary key, exactly mirroring how
 * notification_events.correlation_id is never an FK target anywhere
 * either. event_type is a plain string (approved clarification) — the
 * incident-progress category (e.g. "investigating", "identified",
 * "monitoring", "resolved", "maintenance_scheduled"); status
 * (StatusPageEventStatus) is the separate visibility/publication
 * state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('correlation_id');
            $table->uuid('incident_correlation_id')->nullable();

            $table->string('event_type');
            $table->string('status')->default('draft');
            $table->string('component_affected');
            $table->text('public_message');

            $table->timestamp('starts_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('correlation_id');
            $table->index('incident_correlation_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_events');
    }
};
