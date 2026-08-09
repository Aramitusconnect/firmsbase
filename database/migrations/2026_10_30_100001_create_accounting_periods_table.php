<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_periods — Phase K, month-end close. Unlike every other
 * new table this legal-accounting-foundation mission has added
 * (accounting_journal_entries/postings, payment_allocations,
 * payment_reversals, invoice_write_offs — all append-only ledger-shaped
 * facts), this table models a genuine MUTABLE LIFECYCLE (closed ->
 * reopened -> closed again), so it keeps ordinary timestamps and is
 * NOT append-only — closer in shape to trust_accounts/invoices than to
 * trust_ledger_entries.
 *
 * Snapshots (ar_snapshot_json / trust_liability_snapshot_json) are
 * persisted as structured JSON at close time per the master prompt's
 * own instruction ("Persist the close/report snapshot in structured
 * data until real Document storage is available") — never
 * recomputed-on-read, so a later balance change can never silently
 * rewrite history a closed period already reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status');

            $table->bigInteger('opening_balance_cents')->nullable();
            $table->bigInteger('closing_balance_cents')->nullable();
            $table->json('ar_snapshot_json')->nullable();
            $table->json('trust_liability_snapshot_json')->nullable();
            $table->json('unresolved_exceptions_json')->nullable();

            $table->foreignId('closed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'period_start', 'period_end']);
            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
