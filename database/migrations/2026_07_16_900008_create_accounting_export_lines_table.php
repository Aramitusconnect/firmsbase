<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_export_lines — one row per exported (or attempted-export)
 * source record. source_record_type names which of the three nullable
 * FKs (invoice_id/payment_id/expense_id) is populated — service-
 * enforced XOR, mirroring Phase 11's dual-FK "source typing" pattern.
 * firm_id is a direct column (defense-in-depth, mirrors signature_events'
 * precedent) even though the row is also reachable transitively through
 * accounting_export_batch_id. chart_of_accounts_id is nullable: when a
 * source record's category/account cannot be mapped, the line is still
 * created (status Pending) and only fails at simulation time
 * (AccountingExportSimulationService), per correction #4 — never
 * silently skipped, always logged as a failed line + error.
 * payment_id may ONLY ever reference a Payment whose payment_classification
 * is OperatingPayment (enforced by AccountingExportLineBuilderService,
 * not a DB constraint, since payment_classification is a sibling column
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_export_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('accounting_export_batch_id')->constrained('accounting_export_batches')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('source_record_type');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();

            $table->foreignId('chart_of_accounts_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedInteger('mapped_amount_cents');
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index('accounting_export_batch_id');
            $table->index('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_lines');
    }
};
