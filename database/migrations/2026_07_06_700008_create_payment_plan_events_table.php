<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_plan_events — append-only audit log for plan/installment
 * lifecycle transitions, including dunning attempts. event_type is a
 * plain string (approved decision), matching CommunicationConsentEvent
 * .action and TimelineEvent.event_type exactly — new event kinds never
 * require a migration. Carries firm_id directly (not just via
 * payment_plan_id) for the same reason every other "_events" table in
 * this codebase does: direct firm-scoped queries and RLS without a
 * join. No uuid — internal audit trail only, same reasoning as
 * SecurityEvent/CommunicationConsentEvent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plan_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();

            $table->string('event_type');
            $table->json('metadata_json')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'payment_plan_id']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plan_events');
    }
};
