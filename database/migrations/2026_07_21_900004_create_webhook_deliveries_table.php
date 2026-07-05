<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_deliveries — the one mutable Phase 14 row, and only on the
 * exact fields listed in correction #13: status, attempt_count,
 * next_attempt_at, last_attempted_at. Every other column is set once at
 * creation and never changes. The append-only ledger is
 * webhook_delivery_attempts, not this table.
 *
 * replayed_from_delivery_id/replayed_by_firm_user_id/replayed_at
 * (correction #9) are set ONLY on a delivery created BY
 * WebhookReplayService — they describe "this row is a replay of that
 * row," never the reverse; the original delivery being replayed is
 * never updated with a "this was replayed" marker, so its own history
 * stays exactly as it was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->foreignId('webhook_event_id')->constrained('webhook_events')->cascadeOnDelete();

            $table->string('status');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();

            $table->foreignId('replayed_from_delivery_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->foreignId('replayed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('replayed_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('next_attempt_at');
            $table->index('replayed_from_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
