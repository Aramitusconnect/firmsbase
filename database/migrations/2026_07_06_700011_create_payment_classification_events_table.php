<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_classification_events — append-only audit log of every
 * classification decision, both accepted and blocked. payment_id is
 * required (NOT nullable): a payments row is always created first
 * (status = Initiated) within the same transaction before
 * classification runs, so there is never a classification decision
 * without a backing canonical payment to point at.
 *
 * requested_classification / resolved_classification are the strict
 * PaymentClassification enum (never plain strings) — these are actual
 * classification values, not narrative event descriptions. event_type
 * is a plain string (approved decision) for the narrative ("what
 * happened"), e.g. classification_accepted / classification_blocked_
 * trust_iolta / classification_blocked_payments_disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_classification_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->string('event_type');
            $table->string('requested_classification');
            $table->string('resolved_classification');
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'payment_id']);
            $table->index('resolved_classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_classification_events');
    }
};
