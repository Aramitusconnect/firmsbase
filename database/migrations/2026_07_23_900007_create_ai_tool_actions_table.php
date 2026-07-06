<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_tool_actions — every AI tool action is audited (project rule 10),
 * append-only (no updated_at). tool_name is a string constrained at
 * the application layer to an explicit allowlist (never freeform,
 * never dynamically resolved — project rule 14: "AI tool actions must
 * be constrained and audited"). was_constrained records whether
 * PromptInjectionResistanceService intervened (e.g. stripped/rejected
 * an instruction found only in document-derived text) so a Blocked row
 * is distinguishable in audit review from a normal not-requested
 * action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('ai_usage_event_id')->constrained('ai_usage_events')->cascadeOnDelete();

            $table->string('tool_name');
            $table->json('input_snapshot_json')->nullable();
            $table->json('output_snapshot_json')->nullable();
            $table->boolean('was_constrained')->default(false);
            $table->string('status');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'tool_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_actions');
    }
};
