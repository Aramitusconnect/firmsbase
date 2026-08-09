<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_write_offs — Phase G. Records the firm formally forgiving an
 * invoice's remaining UNPAID balance. Under this codebase's chosen
 * revenue-recognition model (fees are recognized as earned Revenue
 * only when payment is actually applied — see
 * OperatingJournalRecorderService's own docblock — not at invoice
 * issuance), the unpaid remainder was never posted to the operating
 * journal in the first place, so writing it off has NO accounting
 * journal consequence to reverse; this table is the write-off's own
 * audit trail (who, when, how much, why), and InvoiceWriteOffService
 * is the only writer. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_write_offs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason');
            $table->foreignId('actor_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_write_offs');
    }
};
