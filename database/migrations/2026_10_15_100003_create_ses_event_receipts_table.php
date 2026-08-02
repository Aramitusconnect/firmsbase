<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ses_event_receipts — SES event consumer (feature/ses-event-consumer).
 * The durable idempotency ledger for inbound SES/SNS events. SQS and
 * SNS both provide only at-least-once delivery, so the exact same
 * bounce/complaint can arrive as two entirely different SQS messages
 * (different sqs_message_id, e.g. after an SNS-level retry) carrying
 * the identical underlying SES event. idempotency_key is therefore
 * derived from the SES-level event content itself, never from the SQS
 * message ID: `{eventType}:{feedbackId}` for Bounce/Complaint (SES's
 * own feedbackId is globally unique per notification) or
 * `{eventType}:{mail.messageId}` for Reject/RenderingFailure/
 * DeliveryDelay (which carry no feedbackId). The unique constraint on
 * idempotency_key is the actual enforcement mechanism — a second
 * INSERT for the same key fails with a unique-violation, which
 * SesEventConsumerService treats as "already durably processed, safe
 * to delete this SQS message without repeating any business logic".
 *
 * No firm_id column, no RLS: this table records only "this exact
 * provider event was processed", never any notification content or
 * tenant business data. sqs_message_id is retained purely for
 * operator diagnostics (correlating a receipt back to a specific SQS
 * delivery in CloudWatch logs), never used for the idempotency
 * decision itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_event_receipts', function (Blueprint $table) {
            $table->id();

            $table->string('idempotency_key')->unique();
            $table->string('event_type');
            $table->string('ses_message_id')->nullable();
            $table->string('sqs_message_id')->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->index('ses_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_event_receipts');
    }
};
