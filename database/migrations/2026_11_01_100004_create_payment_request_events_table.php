<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_request_events — Payment Link / QR Routing phase. Append-
 * only audit trail, mirroring TrustApprovalEvent/AccountingPeriodEvent
 * exactly: one immutable row per lifecycle event (created, activated,
 * revoked, link accessed, payment attempted, provider confirmed/
 * failed, classification decided, trust deposit requested, posted to
 * accounting, failed). actor_firm_user_id is nullable — a payer
 * accessing the public link is not a FirmUser, so link-access/payment-
 * attempt events from the public side carry no actor.
 *
 * provider_response_json is where any provider evidence is recorded —
 * callers MUST redact secrets before writing here (see
 * PaymentRequestService's own docblock); this table itself enforces
 * nothing about content, only immutability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('provider_transaction_id')->nullable();
            $table->json('provider_response_json')->nullable();
            $table->text('note')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'payment_request_id']);
            $table->index(['firm_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_events');
    }
};
