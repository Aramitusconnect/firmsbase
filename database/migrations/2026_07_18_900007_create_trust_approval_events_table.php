<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_approval_events — append-only. Because there is no separate
 * trust_deposit_requests table, this table must itself carry enough
 * STRUCTURED data to prove exactly what was approved (correction #3) —
 * amount_cents, matter_id, and approved_entry_type are real columns,
 * never buried in metadata_json. actor_firm_user_id is non-nullable:
 * every row is exactly one action by exactly one person.
 * high_risk_change_request_id is a read-only reference into the
 * EXISTING Phase 7 high_risk_change_requests table — this table never
 * writes to it. correlation_uuid links a *Requested row to its later
 * *Approved/*Denied row without relying on timing/ordering alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_approval_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->bigInteger('amount_cents')->nullable();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->string('approved_entry_type')->nullable();
            $table->uuid('correlation_uuid')->nullable();

            $table->foreignId('trust_ledger_id')->nullable()->constrained('trust_ledgers')->nullOnDelete();
            $table->foreignId('trust_transfer_request_id')->nullable()->constrained('trust_transfer_requests')->nullOnDelete();
            $table->foreignId('trust_refund_request_id')->nullable()->constrained('trust_refund_requests')->nullOnDelete();
            $table->foreignId('high_risk_change_request_id')->nullable()->constrained('high_risk_change_requests')->nullOnDelete();

            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'event_type']);
            $table->index('correlation_uuid');
            $table->index('trust_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_approval_events');
    }
};
