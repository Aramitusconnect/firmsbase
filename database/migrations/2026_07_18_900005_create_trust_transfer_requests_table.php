<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_transfer_requests — the trust-to-invoice application workflow
 * root. Requires the target invoice, matter, and trust_ledger's client
 * to all agree before TrustTransferRequestService::apply() ever runs
 * (correction #10/#11) — those cross-checks are enforced in the
 * service layer using the FKs recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('trust_ledger_id')->constrained('trust_ledgers')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            $table->bigInteger('amount_cents');
            $table->string('status')->default('requested');

            $table->foreignId('requested_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->foreignId('approved_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamp('applied_at')->nullable();
            $table->text('denied_reason')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('trust_ledger_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_transfer_requests');
    }
};
