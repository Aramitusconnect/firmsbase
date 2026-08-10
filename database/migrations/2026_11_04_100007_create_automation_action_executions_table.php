<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * automation_action_executions — Event-Driven Automation Engine, item
 * 8/9. One row per action within a matched rule's actions_json array.
 * idempotency_key is exactly "{automation_rule_id}:{domain_event_id}:
 * {action_index}:{rule_version}" (item 8's own required identity —
 * rule_id + event_id + action_index/version), enforced UNIQUE — the
 * same event delivered twice, or a retried claim, can never execute the
 * same action twice by database constraint.
 *
 * risk_level snapshots ActionTypeRegistry's hardcoded classification for
 * this action_type at execution time, for audit/UI display — the
 * REQUIRES_APPROVAL/PROHIBITED gate itself is always re-checked live
 * against the registry when the row transitions toward Running, never
 * trusted from this stored snapshot alone.
 *
 * result_reference_type/result_reference_id point at the resulting
 * domain record where safe (e.g. the Task a CreateTask action created)
 * — never a full copy of sensitive domain data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_action_executions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('automation_execution_id')->constrained('automation_executions')->cascadeOnDelete();

            $table->unsignedSmallInteger('action_index');
            $table->string('action_type');
            $table->json('action_config_json');
            $table->string('idempotency_key');

            $table->string('risk_level');
            $table->string('status')->default('pending');

            $table->string('approval_status')->nullable();
            $table->foreignId('approved_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('result_reference_type')->nullable();
            $table->unsignedBigInteger('result_reference_id')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'idempotency_key']);
            $table->index(['firm_id', 'status']);
            $table->index('automation_execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_action_executions');
    }
};
