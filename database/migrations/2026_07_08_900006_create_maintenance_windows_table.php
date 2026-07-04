<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_windows — firm_id nullable: null for a platform-wide
 * window, non-null for one affecting a single dedicated/private
 * deployment. Carries a public uuid (approved conservative-uuid-scope
 * pattern) — a status/announcements page needs to reference a
 * specific window without exposing a bigint id. affected_components
 * is a plain-string JSON array (same free-text-component convention
 * as status_page_events.component_affected — components can be named
 * without a migration). rescheduled_from_id is a self-FK: rescheduling
 * creates a NEW row and marks the OLD row Cancelled with
 * rescheduled_from_id pointing back — mirrors Phase 3's PaymentPlan
 * renegotiate()-supersedes pattern exactly, never mutates the
 * original schedule in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();

            $table->string('title');
            $table->string('status')->default('scheduled');

            $table->timestamp('scheduled_starts_at');
            $table->timestamp('scheduled_ends_at');
            $table->timestamp('actual_starts_at')->nullable();
            $table->timestamp('actual_ends_at')->nullable();

            $table->json('affected_components')->nullable();
            $table->text('public_message')->nullable();
            $table->text('private_message')->nullable();
            $table->timestamp('customer_notification_sent_at')->nullable();

            $table->foreignId('rescheduled_from_id')->nullable()->constrained('maintenance_windows')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
            $table->index('scheduled_starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
