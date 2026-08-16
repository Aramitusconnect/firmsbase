<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_refunds — FirmsVault Pay Gate A2 (v1.4 §24-§28).
 *
 * WHY EXISTING SCHEMA IS INSUFFICIENT. `payment_reversals` (written by
 * OperatingPaymentRefundService) records a refund that has ALREADY
 * happened in the firm's own books; it has no reservation, no provider
 * command identity, and no unresolved-outcome state, because nothing in
 * the repository has ever executed a refund through a provider. A
 * provider refund needs to hold capacity BEFORE the money moves and to
 * keep holding it when the outcome is unknown. Bolting that onto
 * `payment_reversals` would change the semantics of existing rows in
 * the operating refund path.
 *
 * ARCHITECTURE ROLE. v3.1 `Refund`.
 *
 * ============================================================
 * THE RESERVATION INVARIANT (§26) — certification blocking
 * ============================================================
 *     SUM(successful refunds + active reservations)
 *         <= captured refundable amount
 *
 * A cross-row SUM cannot be a row-level CHECK, and this codebase has a
 * standing zero-trigger convention, so the invariant is enforced by a
 * REAL PostgreSQL locking protocol in
 * App\Services\Pay\RefundReservationService::reserve():
 *
 *     BEGIN
 *       SELECT ... FROM payment_attempts
 *         WHERE id = ? FOR UPDATE          <- serializes all reservers
 *                                             on ONE row, per attempt
 *       SELECT COALESCE(SUM(amount_cents),0) FROM payment_refunds
 *         WHERE payment_attempt_id = ?
 *           AND state IN (<capacity-holding states>)
 *       -- refuse if requested > captured - held
 *       INSERT INTO payment_refunds ...
 *     COMMIT
 *
 * The FOR UPDATE on the parent attempt is what makes the read-then-
 * insert atomic: a second worker blocks on that row until the first
 * commits, then re-reads a sum that already includes the first
 * reservation. Explicitly NOT the forbidden pattern of "SELECT balance
 * / PHP compares / INSERT" without locking (§25). Proved under real
 * concurrency by FV-A2-051/052.
 *
 * OUTCOME_UNKNOWN KEEPS THE RESERVATION HELD (§28) — see
 * App\Enums\PaymentRefundState::holdsRefundableCapacity(), which is the
 * single definition of "active reservation" used by both the service
 * and the tests. A timeout never releases capacity, so a second refund
 * cannot go out while the first is unresolved (FV-A2-053/054).
 *
 * TENANT OWNERSHIP. Tenant-owned, RLS + FORCE RLS, composite FKs to
 * payment_attempts, provider_commands and firm_integrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->unsignedBigInteger('payment_attempt_id');
            $table->unsignedBigInteger('provider_command_id')->nullable();
            $table->unsignedBigInteger('firm_integration_id')->nullable();

            $table->string('state')->default('requested');

            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('USD');

            $table->string('reason')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'state']);

            // The exact index the reservation SUM query rides on.
            $table->index(['payment_attempt_id', 'state'], 'payment_refunds_attempt_state_idx');

            // 1:1 with its economic instruction — a refund can never
            // acquire a second provider command (§28).
            $table->unique('provider_command_id', 'payment_refunds_provider_command_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_refunds
            ADD CONSTRAINT payment_refunds_attempt_same_firm_fk
            FOREIGN KEY (payment_attempt_id, firm_id)
            REFERENCES payment_attempts (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_refunds
            ADD CONSTRAINT payment_refunds_command_same_firm_fk
            FOREIGN KEY (provider_command_id, firm_id)
            REFERENCES provider_commands (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_refunds
            ADD CONSTRAINT payment_refunds_firm_integration_same_firm_fk
            FOREIGN KEY (firm_id, firm_integration_id)
            REFERENCES firm_integrations (firm_id, id)
        SQL);

        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_amount_positive CHECK (amount_cents > 0)');

        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_usd_only CHECK (currency = 'USD')");

        DB::statement(<<<'SQL'
            ALTER TABLE payment_refunds
            ADD CONSTRAINT payment_refunds_state_values CHECK (
                state IN (
                    'requested', 'reserved', 'provider_pending', 'outcome_unknown',
                    'succeeded', 'provider_failed', 'reservation_expired', 'cancelled'
                )
            )
        SQL);

        // Any state that holds refundable capacity must carry its
        // reservation evidence — capacity can never be held implicitly.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_refunds
            ADD CONSTRAINT payment_refunds_reservation_evidence CHECK (
                state NOT IN ('reserved', 'provider_pending', 'outcome_unknown', 'succeeded')
                OR reserved_at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
