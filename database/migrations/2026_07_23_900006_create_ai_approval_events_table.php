<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_approval_events — append-only (project rule 9), mirrors
 * webhook_events/TrustApprovalEvent's exact immutability pattern: no
 * updated_at, model booted() hook throws on update/delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_approval_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ai_approval_request_id')->constrained('ai_approval_requests')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['ai_approval_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_approval_events');
    }
};
