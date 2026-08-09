<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_postings — the individual debit/credit lines of a double-
 * entry accounting_journal_entries row (at least 2 per entry).
 * debit_cents/credit_cents (classic T-account columns, not a single
 * signed amount) so normal-balance side is never ambiguous across
 * Asset/Liability/Equity/Revenue/Expense accounts. Append-only,
 * mirroring accounting_journal_entries and trust_ledger_entries.
 *
 * chart_of_account_id points into the EXISTING chart_of_accounts table
 * (ChartOfAccountsService) — no new account-taxonomy table is created;
 * that model already supports all five standard account types and is
 * firm-configurable with no hardcoded starter accounts.
 *
 * firm_id is denormalized here (derivable via accounting_journal_entry_id)
 * for RLS/query performance, matching trust_ledger_entries' own
 * denormalized firm_id despite being derivable via trust_ledger_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_postings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('accounting_journal_entry_id')->constrained('accounting_journal_entries')->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->string('memo')->nullable();

            $table->index(['firm_id', 'accounting_journal_entry_id']);
            $table->index('chart_of_account_id');
            $table->index('client_id');
            $table->index('matter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_postings');
    }
};
