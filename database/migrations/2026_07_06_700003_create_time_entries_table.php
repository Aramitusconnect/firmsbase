<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * time_entries — manual entries and timer-generated entries share this
 * one table (project rule: "Create manual time entries" alongside
 * "Create timer sessions", not two parallel tables). `seconds` is
 * unsignedInteger, not decimal/float — whole-second storage is
 * enforced by the column type itself, not just application validation
 * (project rule: "Store time in whole seconds only"). billable/non-
 * billable is a plain boolean (`is_billable`) rather than a full enum
 * — the PDF only ever distinguishes those two states.
 *
 * billing_rate_cents_snapshot is captured by TimeEntryApprovalService
 * at approval time from the employee's CURRENT EmployeeRate — this is
 * what makes the effective-dated rate history actually matter: a later
 * rate change never changes what an already-approved entry bills at.
 *
 * No uuid — internal/staff-facing only in Phase 3; nothing here is
 * addressed individually by a client-facing surface (the invoice that
 * is later drafted from it has its own uuid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('time_tracking_session_id')->nullable()->constrained('time_tracking_sessions')->nullOnDelete();

            $table->unsignedInteger('seconds');
            $table->boolean('is_billable')->default(true);
            $table->unsignedInteger('billing_rate_cents_snapshot')->nullable();

            $table->text('description')->nullable();
            $table->date('worked_on');

            $table->string('status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'user_id']);
            $table->index('matter_id');
            $table->index('status');
            $table->index('worked_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
