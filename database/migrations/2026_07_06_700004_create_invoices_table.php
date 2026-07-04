<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices — draft invoices only in Phase 3 (project rule: "Do not
 * build Stripe collection yet"). invoice_type distinguishes a
 * time-based draft from a first-class flat-fee invoice. amount_paid_
 * cents is a CACHE recomputed exclusively by PaymentApplicationService
 * from the canonical payments table — it is not an independent ledger
 * and nothing else may write to it (project rule: "Do not treat
 * invoices as payment ledgers"). Carries a public uuid — the client
 * portal will need to reference a specific invoice later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->string('invoice_type')->default('time_and_expense');
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('usd');

            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            // Cache only — recomputed by PaymentApplicationService from
            // the canonical payments table. Never written anywhere else.
            $table->unsignedInteger('amount_paid_cents')->default(0);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'client_id']);
            $table->index('matter_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
