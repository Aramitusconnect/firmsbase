<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_usage_events — append-only (project rule 8). Columns match the
 * Master Plan's own Section 32 entity catalog for this table verbatim:
 * firm_id, user_id, matter_id, ai_mode, provider, model, tokens_in,
 * tokens_out, cost_cents, approval_required, action_type, created_at.
 * matter_id is nullable — some AI actions (e.g. a firm-wide summary)
 * are not matter-scoped. cost_cents is metadata only in Phase 15
 * (project rule 24) — it is never written to platform_invoices/
 * payments and never triggers real billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->string('ai_mode');
            $table->string('provider');
            $table->string('model');
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->boolean('approval_required')->default(false);
            $table->string('action_type');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'action_type']);
            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
