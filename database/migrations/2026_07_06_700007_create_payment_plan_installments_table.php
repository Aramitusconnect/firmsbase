<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_plan_installments — paid_amount_cents is a CACHE recomputed
 * exclusively by PaymentApplicationService from canonical payments
 * applied against this installment (payments.payment_plan_installment_
 * id); it never competes with or duplicates the payments table
 * (project rule 4). dunning_state is a plain nullable string (not an
 * enum), consistent with the decision to keep event/state-label fields
 * on log-like columns as free strings. Carries a public uuid per
 * approved decision — the future mobile portal needs to link to a
 * specific upcoming installment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();

            $table->unsignedInteger('sequence');
            $table->unsignedInteger('amount_cents');
            $table->timestamp('due_at');
            $table->string('status')->default('scheduled');

            // Cache only — recomputed by PaymentApplicationService from
            // the canonical payments table. Never written anywhere else.
            $table->unsignedInteger('paid_amount_cents')->default(0);
            $table->timestamp('paid_at')->nullable();

            $table->string('dunning_state')->nullable();

            $table->timestamps();

            $table->unique(['payment_plan_id', 'sequence']);
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plan_installments');
    }
};
