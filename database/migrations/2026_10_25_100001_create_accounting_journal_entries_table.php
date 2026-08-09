<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_journal_entries — the header row of a double-entry
 * accounting event, mirroring trust_ledger_entries' own append-only
 * discipline (no status column, no updated_at; a row is created once
 * and never mutated — enforced additionally by the model's own
 * booted() guard). Correction is represented only by a brand-new
 * entry with every posting's debit/credit swapped and
 * reverses_journal_entry_id pointing at the original — the original's
 * fields never change.
 *
 * Every entry traces back to the domain event that caused it via a
 * structured, nullable FK (payment_id/invoice_id/expense_id/
 * trust_transfer_request_id) — never a generic morph or JSON blob,
 * matching trust_ledger_entries' own "must trace back to the
 * authorizing record, never buried in metadata" rule. Exactly one of
 * these is populated per source_type; which one is enforced by
 * AccountingJournalPostingService, not by the schema.
 *
 * This table records ONLY the operating-side accounting consequence of
 * a domain event. It never replaces trust_ledger_entries — a Trust
 * event's trust-side movement is already fully recorded there;
 * trust_transfer_request_id here is how the two systems cross-
 * reference without merging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->date('entry_date');
            $table->string('description');
            $table->string('source_type');

            $table->foreignId('reverses_journal_entry_id')->nullable()->constrained('accounting_journal_entries')->nullOnDelete();
            $table->foreignId('posted_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->foreignId('trust_transfer_request_id')->nullable()->constrained('trust_transfer_requests')->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'entry_date']);
            $table->index('source_type');
            $table->index('reverses_journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_entries');
    }
};
