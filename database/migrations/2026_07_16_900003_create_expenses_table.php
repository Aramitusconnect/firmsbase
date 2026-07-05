<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expenses — the firm-owned root of the operating-expense workflow.
 * matter_id is nullable (an expense may be firm-overhead, not tied to
 * any matter — matter linkage/attribution itself lives in
 * matter_expenses, not here, per the approved Data Contract).
 * expense_category_id is non-nullable and restrictOnDelete — a category
 * cannot be deleted while expenses reference it. reimbursable defaults
 * false; only Approved + reimbursable=true expenses are ever eligible
 * for invoice reimbursement (ReimbursableExpenseInvoiceEligibilityService).
 * accounting only (project rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();

            $table->string('vendor_name');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('usd');
            $table->date('expense_date');
            $table->string('status')->default('draft');
            $table->boolean('reimbursable')->default(false);
            $table->text('description')->nullable();

            $table->foreignId('created_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['firm_id', 'status']);
            $table->index('matter_id');
            $table->index('expense_category_id');
            $table->index(['firm_id', 'reimbursable', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
