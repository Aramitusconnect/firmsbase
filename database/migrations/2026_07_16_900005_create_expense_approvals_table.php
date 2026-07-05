<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expense_approvals — one row per approval decision. An expense may
 * accumulate more than one row over its lifetime (e.g. rejected, then
 * resubmitted and approved); Expense::latestApproval() reads the most
 * recent via latestOfMany(), mirroring Payment::latestClassificationEvent().
 * Written exclusively by ExpenseApprovalService — the approval role set
 * (FirmOwner, BillingStaff only) is enforced at the service layer, not
 * the schema layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();

            $table->string('status')->default('pending');
            $table->foreignId('decided_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approvals');
    }
};
