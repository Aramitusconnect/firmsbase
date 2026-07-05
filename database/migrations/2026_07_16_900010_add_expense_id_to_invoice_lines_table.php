<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12's ONE narrow, approved existing-table alteration (correction
 * #2/#10). Adds a single nullable, unique expense_id FK to the
 * existing (Phase 3) invoice_lines table so
 * ReimbursableExpenseInvoiceLineService can create exactly one
 * InvoiceLine per approved, reimbursable Expense.
 *
 * The unique constraint on expense_id is the database-level backstop
 * (defense-in-depth, mirroring Phase 11's one-certificate-per-request
 * unique constraint on signature_certificates.signature_request_id) for
 * "an expense must not be added to an invoice twice" — a NULL value is
 * allowed to repeat (ordinary, non-expense invoice lines), but a given
 * expense_id can appear on at most one invoice_lines row, ever, across
 * every invoice, not just the same invoice.
 *
 * No column, index, or constraint on invoices, payments, or any
 * itself is otherwise unchanged — every existing column, index, and
 * constraint from the Phase 3 migration remains exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('expense_id')
                ->nullable()
                ->after('time_entry_id')
                ->unique()
                ->constrained('expenses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
