<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_attempts — FirmsVault Pay Gate A2 (v1.4 §22/§23).
 *
 * WHY EXISTING SCHEMA IS INSUFFICIENT. Gate A1 established that
 * `payments` is a record of an OUTCOME, not an attempt state machine:
 * its PaymentStatus has no SUBMITTED, no DECLINED distinct from FAILED,
 * no CANCELLED, and — critically — no OUTCOME_UNKNOWN. Adding those to
 * `payments` would change the meaning of a status column the entire
 * Billing domain, Filament UI and reporting layer already branch on,
 * for every historical row. A separate attempt table leaves `payments`
 * untouched and lets the canonical Payment continue to mean exactly
 * what it means today.
 *
 * ARCHITECTURE ROLE. v3.1 `PaymentAttempt`.
 *
 * ONE ATTEMPT, ONE COMMAND. `UNIQUE (provider_command_id)` — an attempt
 * and its economic instruction are 1:1. This is what makes "recovery
 * resolves the EXISTING attempt" (§23) enforceable rather than
 * aspirational: there is nowhere for a second command to attach.
 *
 * OUTCOME_UNKNOWN (§23) is a first-class state here, not an error code.
 * It means the economic outcome is undetermined. The state machine in
 * App\Enums\PaymentAttemptState gives it no outgoing transitions, and
 * App\Services\Pay\PaymentAttemptService refuses to open a new attempt
 * for an intent that has one — so an unknown outcome can never silently
 * become a second charge (FV-A2-028).
 *
 * TENANT OWNERSHIP. Tenant-owned, RLS + FORCE RLS in the companion
 * migration, composite FKs to payment_intents, provider_commands and
 * firm_integrations (v1.4 §35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->unsignedBigInteger('payment_intent_id');
            $table->unsignedBigInteger('provider_command_id')->nullable();
            $table->unsignedBigInteger('firm_integration_id')->nullable();

            $table->string('state')->default('created');

            // The amount actually being executed. For POC #1 this equals
            // the intent's Operating-destined total (trust is never
            // executable), asserted by PaymentAttemptService.
            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('USD');

            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();

            // The link back to the canonical Payment row, populated only
            // once the capture has been routed through the existing
            // Billing chain. Nullable because an attempt exists long
            // before (and often without) a Payment.
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'state']);
            $table->index(['firm_id', 'payment_intent_id']);

            // 1:1 with its economic instruction.
            $table->unique('provider_command_id', 'payment_attempts_provider_command_unique');

            // Composite-FK target for payment_refunds.
            $table->unique(['id', 'firm_id'], 'payment_attempts_id_firm_id_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT payment_attempts_intent_same_firm_fk
            FOREIGN KEY (payment_intent_id, firm_id)
            REFERENCES payment_intents (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT payment_attempts_command_same_firm_fk
            FOREIGN KEY (provider_command_id, firm_id)
            REFERENCES provider_commands (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT payment_attempts_firm_integration_same_firm_fk
            FOREIGN KEY (firm_id, firm_integration_id)
            REFERENCES firm_integrations (firm_id, id)
        SQL);

        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount_positive CHECK (amount_cents > 0)');

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_usd_only CHECK (currency = 'USD')");

        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT payment_attempts_state_values CHECK (
                state IN ('created', 'submitted', 'captured', 'declined', 'failed', 'outcome_unknown', 'cancelled')
            )
        SQL);

        // Only a captured attempt may point at a canonical Payment.
        // This is the structural half of "a non-captured attempt never
        // produces money in Billing".
        DB::statement(<<<'SQL'
            ALTER TABLE payment_attempts
            ADD CONSTRAINT payment_attempts_payment_only_when_captured CHECK (
                payment_id IS NULL OR state = 'captured'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
