<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending-Cash Accounting pass. Extends accounting_journal_entries'
 * existing structured-FK convention (payment_id/invoice_id/expense_id/
 * trust_transfer_request_id — "never a generic morph or JSON blob")
 * with a nullable pending_payment_allocation_id, populated by
 * OperatingJournalRecorderService::recordUnappliedFundsReceived()/
 * recordUnappliedFundsResolved() so both the cash-received entry and
 * the later resolution entry trace back to the exact
 * PendingPaymentAllocation row they belong to, not merely to the
 * Payment (a payment could, in principle, later gain other journal
 * activity unrelated to this specific deferred allocation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->foreignId('pending_payment_allocation_id')
                ->nullable()
                ->after('trust_transfer_request_id')
                ->constrained('payment_pending_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_payment_allocation_id');
        });
    }
};
