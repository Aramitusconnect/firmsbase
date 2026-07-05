<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_expenses — the matter-attribution link for an expense. No own
 * uuid (accessed only through its parent Expense/Matter, mirrors
 * InvoiceLine's "no own identity needed" reasoning). firm_id IS present
 * as a direct column (unlike InvoiceLine) so MatterExpenseService and
 * TenantSafeAccountingPolicyService can defense-in-depth check it
 * directly, mirroring Phase 11's signature_events precedent (firm_id
 * column present, no BelongsToTenant trait applied). Unique on
 * expense_id: an expense links to at most one matter at a time
 * (Expense::matterExpense() is a hasOne). reimbursable_snapshot freezes
 * the expense's reimbursable flag at link time so a later category or
 * expense-level change cannot retroactively alter an already-linked
 * expense's invoice eligibility history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('expense_id')->unique()->constrained('expenses')->cascadeOnDelete();

            $table->boolean('reimbursable_snapshot');

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_expenses');
    }
};
