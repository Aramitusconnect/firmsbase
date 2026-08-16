<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentDestinationClass;
use App\Enums\PaymentRefundState;
use App\Exceptions\Pay\RefundCapacityExceededException;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\Pay\PaymentAttemptService;
use App\Services\Pay\PaymentIntentService;
use App\Services\Pay\RefundReservationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pay\Concerns\CleansUpDurablePayAudit;
use Tests\TestCase;

/**
 * FV-A2-050 / FV-A2-053 / FV-A2-054 / FV-A2-055 — refund core.
 * CERTIFICATION BLOCKING.
 *
 * The genuine two-process concurrency proof (FV-A2-051/052) lives in
 * RefundReservationRaceTest, which cannot use RefreshDatabase.
 */
class RefundCoreTest extends TestCase
{
    use CleansUpDurablePayAudit;
    use RefreshDatabase;

    private function refunds(): RefundReservationService
    {
        return app(RefundReservationService::class);
    }

    /** FV-A2-050 — a refund reservation succeeds within capacity. */
    public function test_fv_a2_050_refund_reservation_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 4_000, 'client requested');

        $this->assertSame(PaymentRefundState::Reserved, $refund->state);
        $this->assertSame(4_000, (int) $refund->amount_cents);
        $this->assertNotNull($refund->reserved_at);
        $this->assertTrue($refund->holdsCapacity());

        $this->assertSame(4_000, $this->refunds()->heldCapacityCents($attempt));
    }

    /** Sequential over-reservation is refused. */
    public function test_reserving_more_than_remaining_capacity_is_refused(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $this->refunds()->reserve($attempt, 7_000);

        try {
            $this->refunds()->reserve($attempt, 4_000);
            $this->fail('Reserving beyond remaining capacity must be refused.');
        } catch (RefundCapacityExceededException $e) {
            $this->assertSame(10_000, $e->capturedCents);
            $this->assertSame(7_000, $e->alreadyHeldCents);
            $this->assertSame(4_000, $e->requestedCents);
        }

        // The invariant holds.
        $this->assertLessThanOrEqual(10_000, $this->refunds()->heldCapacityCents($attempt));
    }

    /** A non-captured attempt has no refundable capacity at all. */
    public function test_a_non_captured_attempt_cannot_be_refunded(): void
    {
        $firm = Firm::factory()->create();
        $intent = $this->executableIntent($firm, 10_000);
        $attempt = app(PaymentAttemptService::class)->open($intent);

        $this->expectException(RefundCapacityExceededException::class);

        $this->refunds()->reserve($attempt, 1_000);
    }

    /**
     * FV-A2-053 — an undetermined refund outcome KEEPS the reservation
     * held. This is the rule that prevents a double refund.
     */
    public function test_fv_a2_053_outcome_unknown_keeps_the_reservation_held(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 10_000);
        $pending = $this->refunds()->submitToProvider($refund);
        $unknown = $this->refunds()->resolve($pending, PaymentRefundState::OutcomeUnknown);

        $this->assertSame(PaymentRefundState::OutcomeUnknown, $unknown->state);
        $this->assertTrue($unknown->holdsCapacity(), 'An undetermined refund still consumes capacity.');
        $this->assertNotNull($unknown->reserved_at, 'The reservation evidence must survive an unknown outcome.');

        $this->assertSame(
            10_000,
            $this->refunds()->heldCapacityCents($attempt),
            'A timeout must never release refundable capacity.'
        );
    }

    /**
     * FV-A2-054 — an unknown refund cannot create a second provider
     * refund command, and no further refund can be reserved against the
     * held capacity.
     */
    public function test_fv_a2_054_unknown_refund_cannot_create_a_second_provider_refund(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 10_000);
        $pending = $this->refunds()->submitToProvider($refund);
        $originalCommandId = (int) $pending->provider_command_id;

        $unknown = $this->refunds()->resolve($pending, PaymentRefundState::OutcomeUnknown);

        // No automated transition out of unknown.
        $this->assertSame([], PaymentRefundState::transitionMatrix()[PaymentRefundState::OutcomeUnknown->value]);

        // Cannot resubmit the same refund.
        try {
            $this->refunds()->submitToProvider($unknown);
            $this->fail('An undetermined refund must never be resubmitted to the provider.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Illegal refund transition', $e->getMessage());
        }

        // Cannot reserve a fresh refund for the same money.
        $this->expectException(RefundCapacityExceededException::class);
        $this->refunds()->reserve($attempt, 10_000);

        // (unreachable assertion retained for intent) the command is unchanged
        $this->assertSame($originalCommandId, (int) $unknown->provider_command_id);
    }

    /** FV-A2-054 — a refund is bound to one command by the database. */
    public function test_fv_a2_054_a_refund_can_never_acquire_a_second_provider_command(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 5_000);
        $pending = $this->refunds()->submitToProvider($refund);

        $this->expectException(UniqueConstraintViolationException::class);

        // A second refund row cannot claim the same command.
        $this->runWithFirmContext($firm, fn () => DB::table('payment_refunds')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'payment_attempt_id' => $attempt->id,
            'provider_command_id' => $pending->provider_command_id,
            'state' => 'reserved',
            'amount_cents' => 1_000,
            'currency' => 'USD',
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** FV-A2-055 — a released reservation frees capacity; a held one does not. */
    public function test_fv_a2_055_only_provably_unsent_refunds_release_capacity(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 10_000);
        $this->assertSame(10_000, $this->refunds()->heldCapacityCents($attempt));

        // Expiring a never-sent reservation legitimately frees capacity.
        $this->assertTrue($this->refunds()->expireIfUnsent($refund));
        $this->assertSame(0, $this->refunds()->heldCapacityCents($attempt));

        // Now the money can be reserved again.
        $second = $this->refunds()->reserve($attempt, 10_000);
        $pending = $this->refunds()->submitToProvider($second);
        $unknown = $this->refunds()->resolve($pending, PaymentRefundState::OutcomeUnknown);

        // But an undetermined refund can NEVER be expired.
        $this->assertFalse(
            $this->refunds()->expireIfUnsent($unknown),
            'A refund whose outcome is unknown must never be expired — the money may already be gone.'
        );
        $this->assertSame(10_000, $this->refunds()->heldCapacityCents($attempt));
    }

    /** A succeeded refund permanently consumes capacity. */
    public function test_a_succeeded_refund_permanently_consumes_capacity(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 6_000);
        $pending = $this->refunds()->submitToProvider($refund);
        $succeeded = $this->refunds()->resolve($pending, PaymentRefundState::Succeeded, providerReference: 'RF-1');

        $this->assertSame(PaymentRefundState::Succeeded, $succeeded->state);
        $this->assertSame(6_000, $this->refunds()->heldCapacityCents($attempt));

        // Only the remainder is available.
        $this->expectException(RefundCapacityExceededException::class);
        $this->refunds()->reserve($attempt, 5_000);
    }

    /** A provider-failed refund releases capacity, because no money moved. */
    public function test_a_provider_failed_refund_releases_capacity(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);

        $refund = $this->refunds()->reserve($attempt, 10_000);
        $pending = $this->refunds()->submitToProvider($refund);
        $failed = $this->refunds()->resolve($pending, PaymentRefundState::ProviderFailed, failureReason: 'declined');

        $this->assertFalse($failed->holdsCapacity());
        $this->assertSame(0, $this->refunds()->heldCapacityCents($attempt));
    }

    /** The amount a refund reserves can never move. */
    public function test_a_refund_amount_is_immutable(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->capturedAttempt($firm, 10_000);
        $refund = $this->refunds()->reserve($attempt, 3_000);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/can never move/');

        $this->runWithFirmContext($firm, fn () => $refund->update(['amount_cents' => 9_000]));
    }

    // ---------------------------------------------------------------

    private function executableIntent(Firm $firm, int $amountCents): PaymentIntent
    {
        $intents = app(PaymentIntentService::class);
        $intent = $intents->createDraft($firm, $amountCents, 'invoice_payment');
        $intents->addAllocation($intent, PaymentDestinationClass::Operating, $amountCents);

        return $intents->freeze($intent);
    }

    private function capturedAttempt(Firm $firm, int $amountCents): PaymentAttempt
    {
        $attempts = app(PaymentAttemptService::class);
        $attempt = $attempts->open($this->executableIntent($firm, $amountCents));
        $submitted = $attempts->transition($attempt, PaymentAttemptState::Submitted);

        return $attempts->transition($submitted, PaymentAttemptState::Captured, providerReference: 'CAP-1');
    }
}
