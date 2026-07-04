<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deadlines — representative fields match the master plan PDF's
 * appendix row ("id; firm_id; matter_id; title; deadline_type; due_at;
 * jurisdiction; source; reminder_policy_id; status") with ONE
 * deliberate deviation: no reminder_policy_id column. The PDF's own
 * appendix references this column, but no reminder_policies table is
 * defined anywhere in the plan's data contract or table-family list —
 * the exact same dangling-reference situation as payment_plans.
 * dunning_policy_id in Phase 3. Per your standing instruction not to
 * create speculative tables outside the approved contract, this
 * migration instead stores a simple reminder_offsets_days JSON array
 * (e.g. [7,3,1] days before due_at) directly on the row — no separate
 * policy table. deadline_type is a plain string, not a rigid enum —
 * legal deadline types vary far too much by practice area/jurisdiction
 * to enumerate in the core schema (matches the "core system must not
 * be immigration-only / practice areas are template-driven" project
 * rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadlines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->string('title');
            $table->string('deadline_type');
            $table->timestamp('due_at');
            $table->string('jurisdiction')->nullable();
            $table->string('source')->nullable();
            $table->json('reminder_offsets_days')->nullable();

            $table->string('status')->default('upcoming');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadlines');
    }
};
