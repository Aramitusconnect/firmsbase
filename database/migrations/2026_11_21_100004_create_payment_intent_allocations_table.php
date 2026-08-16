<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_intent_allocations — FirmsVault Pay Gate A2 (v1.4 §18).
 *
 * WHY EXISTING SCHEMA IS INSUFFICIENT. `payment_allocations` allocates
 * an already-received Payment across invoices; it has no concept of a
 * destination class and is append-only against a different parent. This
 * table answers a different question: how a PROPOSED instruction's
 * amount is divided between the Operating and Trust sides BEFORE any
 * money moves. Reusing `payment_allocations` would conflate an executed
 * allocation with a proposed one.
 *
 * ARCHITECTURE ROLE. v3.1 `PaymentAllocation` (intent-side).
 *
 * THE §18 INVARIANT this table exists to make representable:
 *
 *     SUM(all allocations) = PaymentIntent.amount_cents      <- completeness
 *
 * is deliberately SEPARATE from
 *
 *     all executable value is OPERATING                      <- eligibility
 *
 * A $10,000 intent split $3,000 Operating / $7,000 Trust is COMPLETE
 * (allocation passes) yet NOT EXECUTABLE (trust execution disabled).
 * Completeness is enforced at freeze time by
 * App\Services\Pay\PaymentIntentService::freeze(); eligibility is a
 * separate query. Conflating them is the contradiction §18 orders
 * fixed.
 *
 * WHY COMPLETENESS IS NOT A DATABASE CHECK. A cross-row SUM cannot be
 * expressed as a row-level CHECK, and this codebase has a standing
 * zero-trigger convention. It is instead enforced atomically inside the
 * freeze transaction, which locks the parent intent FOR UPDATE, and the
 * frozen intent's immutability (both allocations and the parent become
 * append-only/frozen at that instant) is what keeps the invariant true
 * forever afterwards. Proved by FV-A2-023.
 *
 * TENANT OWNERSHIP. Tenant-owned. RLS + FORCE RLS in the companion
 * migration. `(payment_intent_id, firm_id)` composite-FKs the parent so
 * a Firm A allocation can never attach to a Firm B intent (v1.4 §35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intent_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('payment_intent_id');

            $table->string('destination_class');
            $table->bigInteger('amount_cents');

            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'payment_intent_id']);
            $table->index(['payment_intent_id', 'destination_class']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intent_allocations
            ADD CONSTRAINT payment_intent_allocations_intent_same_firm_fk
            FOREIGN KEY (payment_intent_id, firm_id)
            REFERENCES payment_intents (id, firm_id)
            ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intent_allocations
            ADD CONSTRAINT payment_intent_allocations_amount_positive CHECK (amount_cents > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intent_allocations
            ADD CONSTRAINT payment_intent_allocations_destination_class_values CHECK (
                destination_class IN ('operating', 'trust')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intent_allocations');
    }
};
