<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_events — append-only (correction #13). One row per business
 * event, fanned out to N webhook_deliveries by
 * WebhookEventRecorderService/WebhookDeliveryService — never one row
 * per subscription (correction #11). payload_json is already the
 * minimized, allowlisted payload built by WebhookPayloadBuilderService
 * — never a raw model dump. subject_type/subject_id are a plain
 * polymorphic pair (not a real FK, since the subject varies by event
 * type) used only for internal traceability, never exposed in the
 * payload itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload_json');
            $table->timestamp('occurred_at');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'event_type']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
