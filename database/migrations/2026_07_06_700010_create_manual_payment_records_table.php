<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * manual_payment_records — a DETAIL of a canonical payment, never a
 * second payment ledger (project rule 3). One-to-one with payments:
 * payment_id is unique here, and this row is only ever created after
 * PaymentClassificationService has accepted the payment (status =
 * Succeeded, classification = OperatingPayment) — a blocked attempt
 * never gets a manual_payment_records row, only a payments row (status
 * = Blocked) and a payment_classification_events row. No own firm_id
 * — scoped transitively through payment_id, same pattern as
 * invoice_lines/payment_plan_installments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->string('method_reference')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_records');
    }
};
