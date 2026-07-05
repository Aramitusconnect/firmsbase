<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_ledger_entries — append-only, no status column (approved
 * correction #5). A row is created once and NEVER updated or deleted —
 * enforced additionally by the model's own booted() guard. Correction
 * is represented only by a brand-new row with entry_type=reversal,
 * amount_cents equal to the exact opposite of the original, and
 * reverses_entry_id pointing at the original — the original's fields
 * never change, including reverses_entry_id/status, since neither
 * exists to change.
 *
 * Every entry must trace back to the approval/request that authorized
 * it (correction #4): trust_approval_event_id for deposits,
 * trust_transfer_request_id for withdrawal_to_invoice entries,
 * trust_refund_request_id for refund entries. source_payment_id is
 * populated ONLY for withdrawal_to_invoice entries, once the
 * corresponding operating Payment has been created via the EXISTING
 * PaymentClassificationService + PaymentApplicationService pipeline
 * (never a new payments row classified trust_iolta_payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('trust_ledger_id')->constrained('trust_ledgers')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->string('entry_type');
            $table->bigInteger('amount_cents');

            $table->foreignId('reverses_entry_id')->nullable()->constrained('trust_ledger_entries')->nullOnDelete();
            $table->foreignId('trust_approval_event_id')->nullable()->constrained('trust_approval_events')->restrictOnDelete();
            $table->foreignId('trust_transfer_request_id')->nullable()->constrained('trust_transfer_requests')->restrictOnDelete();
            $table->foreignId('trust_refund_request_id')->nullable()->constrained('trust_refund_requests')->restrictOnDelete();
            $table->foreignId('source_payment_id')->nullable()->constrained('payments')->restrictOnDelete();

            $table->timestamp('posted_at')->useCurrent();

            $table->index(['firm_id', 'trust_ledger_id']);
            $table->index('matter_id');
            $table->index('entry_type');
            $table->index('trust_approval_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_ledger_entries');
    }
};
