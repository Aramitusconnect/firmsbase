<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deletion_requests — approved decision #9: firm_id + subject_type +
 * subject_id + subject_snapshot_json, because deletion governance may
 * target many record types over time (unlike legal_holds' fixed 4
 * levels). subject_snapshot_json is a declared, informational snapshot
 * of the target record's identifying fields at request time — never a
 * substitute for the real row, never used to reconstruct/restore it.
 *
 * Approved decision #1: this table's terminal success status is
 * ready_for_execution (DeletionRequestStatus) — Phase 17 never
 * physically deletes the target row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('subject_snapshot_json')->nullable();

            $table->text('reason');
            $table->string('status')->default('requested');
            $table->foreignId('offboarding_export_id')->nullable()->constrained('offboarding_exports')->nullOnDelete();

            $table->nullableMorphs('requested_by');
            $table->timestamp('requested_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_requests');
    }
};
