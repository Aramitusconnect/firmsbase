<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_activation_events — always firm-scoped (project rule:
 * "Activation events must be firm-scoped"). Append-only audit trail of
 * checklist checks, completions, waivers, blocking reasons, and
 * production-readiness transitions. event_type is a plain string
 * (approved clarification) — e.g. "checklist_item_completed",
 * "checklist_item_waived", "production_readiness_evaluated",
 * "production_ready", "production_blocked". status is the strict
 * FirmActivationEventStatus outcome of this specific event.
 *
 * Production-readiness itself is event-derived only (approved
 * decision) — no new column is added to firms, and FirmActivationStatus
 * (Phase 1's draft/onboarding/activated enum) is untouched.
 * FirmProductionActivationService reads the latest relevant row here
 * (plus existing Phase 1 ActivationChecklist/FirmLicense records) to
 * answer "is this firm production-ready" at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_activation_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('event_type');
            $table->string('status');
            $table->string('checklist_item_key')->nullable();
            $table->text('blocking_reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'event_type']);
            $table->index('checklist_item_key');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_activation_events');
    }
};
