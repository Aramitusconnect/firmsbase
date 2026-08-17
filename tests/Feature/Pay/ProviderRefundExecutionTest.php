<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRefundState;
use App\Exceptions\Pay\RefundCapacityExceededException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\ProviderCommand;
use App\Services\Pay\PaymentOutcomeRecoveryService;
use App\Services\Pay\ProviderCommandExecutorService;
use App\Services\Pay\ProviderOutcomeApplierService;
use App\Services\Pay\RefundReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\BuildsPayFixtures;
use Tests\TestCase;

/**
 * FV-A3-040 … FV-A3-046 — refund execution through the fake provider.
 * CERTIFICATION BLOCKING throughout (v1.4 §30-§34).
 */
class ProviderRefundExecutionTest extends TestCase
{
    use BuildsPayFixtures, RefreshDatabase;

    private function executor(): ProviderCommandExecutorService
    {
        return app(ProviderCommandExecutorService::class);
    }

    private function refunds(): RefundReservationService
    {
        return app(RefundReservationService::class);
    }

    private function recovery(): PaymentOutcomeRecoveryService
    {
        return app(PaymentOutcomeRecoveryService::class);
    }

    /**
     * A captured attempt executed through the REAL fake-provider flow,
     * so refunds run against a genuine provider resource.
     *
     * @return array{0: Firm, 1: PaymentAttempt, 2: IntegrationProvider, 3: FirmIntegration}
     */
    private function capturedViaProvider(int $amountCents = 10_000): array
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success', $amountCents);
        $this->executor()->execute($this->payCommandOf($attempt));

        $captured = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $this->assertSame(PaymentAttemptState::Captured, $captured->state);

        return [$firm, $captured, $provider, $connection];
    }

    private function submittedRefund(Firm $firm, PaymentAttempt $attempt, string $scenario, int $amountCents, int $providerId): PaymentRefund
    {
        $refund = $this->refunds()->reserve($attempt, $amountCents, $scenario);

        return $this->refunds()->submitToProvider($refund, $providerId);
    }

    /** FV-A3-040 — refund success: the full §30 chain, exactly once. */
    public function test_fv_a3_040_refund_success_end_to_end(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:success', 4_000, (int) $provider->id);
        $result = $this->executor()->execute($this->payCommandOf($refund));

        $this->assertSame(ProviderCommandExecutorService::RESULT_EXECUTED, $result);

        $this->runWithFirmContext($firm, function () use ($refund, $attempt) {
            $fresh = PaymentRefund::query()->findOrFail($refund->id);
            $this->assertSame(PaymentRefundState::Succeeded, $fresh->state);
            $this->assertNotNull($fresh->provider_reference);

            // Exactly one refund posting.
            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)
                ->count());

            // The reservation resolved into permanent consumption:
            // succeeded still holds capacity (the money is gone).
            $held = (int) PaymentRefund::query()
                ->where('payment_attempt_id', $attempt->id)
                ->whereIn('state', PaymentRefundState::capacityHoldingValues())
                ->sum('amount_cents');
            $this->assertSame(4_000, $held);
        });
    }

    /** FV-A3-041 — definitive refund failure: PROVIDER_FAILED, capacity released, no posting. */
    public function test_fv_a3_041_refund_definitive_failure(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:fail', 4_000, (int) $provider->id);
        $this->executor()->execute($this->payCommandOf($refund));

        $this->runWithFirmContext($firm, function () use ($refund, $attempt) {
            $fresh = PaymentRefund::query()->findOrFail($refund->id);
            $this->assertSame(PaymentRefundState::ProviderFailed, $fresh->state);

            $this->assertSame(0, DB::table('accounting_journal_entries')
                ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)
                ->count(), 'A failed refund must never post.');

            // Capacity safely released — the full amount is reservable again.
            $held = (int) PaymentRefund::query()
                ->where('payment_attempt_id', $attempt->id)
                ->whereIn('state', PaymentRefundState::capacityHoldingValues())
                ->sum('amount_cents');
            $this->assertSame(0, $held);
        });
    }

    /**
     * FV-A3-042 / FV-A3-043 — refund timeout: OUTCOME_UNKNOWN, the
     * reservation REMAINS ACTIVE, and no second refund command exists.
     */
    public function test_fv_a3_042_043_refund_timeout_keeps_reservation_active(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:timeout-success', 10_000, (int) $provider->id);
        $result = $this->executor()->execute($this->payCommandOf($refund));

        $this->assertSame(ProviderCommandExecutorService::RESULT_OUTCOME_UNKNOWN, $result);

        $this->runWithFirmContext($firm, function () use ($refund, $attempt) {
            $fresh = PaymentRefund::query()->findOrFail($refund->id);
            $this->assertSame(PaymentRefundState::OutcomeUnknown, $fresh->state);
            $this->assertNotNull($fresh->reserved_at, 'The reservation evidence survives.');

            // Reservation REMAINS ACTIVE (v1.4 §32).
            $held = (int) PaymentRefund::query()
                ->where('payment_attempt_id', $attempt->id)
                ->whereIn('state', PaymentRefundState::capacityHoldingValues())
                ->sum('amount_cents');
            $this->assertSame(10_000, $held, 'A refund timeout must never release its reservation.');

            // No second refund command exists — and none can be created
            // for the held money.
            $this->assertSame(2, ProviderCommand::query()->count(), 'capture + ONE refund command only.');
        });

        // The held capacity blocks any fresh refund of the same money.
        $this->expectException(RefundCapacityExceededException::class);
        $this->refunds()->reserve(
            $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id)),
            10_000,
        );
    }

    /** FV-A3-044 — unknown refund → SUCCESS recovery: exactly one posting, no second refund. */
    public function test_fv_a3_044_unknown_refund_recovers_to_success_exactly_once(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:timeout-success', 6_000, (int) $provider->id);
        $this->executor()->execute($this->payCommandOf($refund));

        $unknown = $this->runWithFirmContext($firm, fn () => PaymentRefund::query()->findOrFail($refund->id));
        $applied = $this->recovery()->recoverRefund($unknown);

        $this->assertSame(ProviderOutcomeApplierService::APPLIED, $applied);

        $this->runWithFirmContext($firm, function () use ($refund) {
            $fresh = PaymentRefund::query()->findOrFail($refund->id);
            $this->assertSame(PaymentRefundState::Succeeded, $fresh->state);

            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)
                ->count(), 'Exactly one refund posting.');
        });

        // Recovery again: no-op, still one posting.
        $resolved = $this->runWithFirmContext($firm, fn () => PaymentRefund::query()->findOrFail($refund->id));
        $this->assertSame(ProviderOutcomeApplierService::ALREADY_RESOLVED, $this->recovery()->recoverRefund($resolved));

        $this->runWithFirmContext($firm, fn () => $this->assertSame(1, DB::table('accounting_journal_entries')
            ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)->count()));
    }

    /** FV-A3-045 — unknown refund → FAILURE recovery: released safely, no posting. */
    public function test_fv_a3_045_unknown_refund_recovers_to_failure_and_releases(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:timeout-fail', 6_000, (int) $provider->id);
        $this->executor()->execute($this->payCommandOf($refund));

        $unknown = $this->runWithFirmContext($firm, fn () => PaymentRefund::query()->findOrFail($refund->id));
        $applied = $this->recovery()->recoverRefund($unknown);

        $this->assertSame(ProviderOutcomeApplierService::APPLIED, $applied);

        $this->runWithFirmContext($firm, function () use ($refund, $attempt) {
            $fresh = PaymentRefund::query()->findOrFail($refund->id);
            $this->assertSame(PaymentRefundState::ProviderFailed, $fresh->state);

            $this->assertSame(0, DB::table('accounting_journal_entries')
                ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)
                ->count(), 'No successful-refund posting.');

            $held = (int) PaymentRefund::query()
                ->where('payment_attempt_id', $attempt->id)
                ->whereIn('state', PaymentRefundState::capacityHoldingValues())
                ->sum('amount_cents');
            $this->assertSame(0, $held, 'The reservation is safely released after a PROVEN failure.');
        });
    }

    /** STILL_UNKNOWN refund lookup changes nothing (v1.4 §17 refund-side). */
    public function test_unknown_refund_remains_unknown_and_keeps_holding(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:timeout-unknown', 6_000, (int) $provider->id);
        $this->executor()->execute($this->payCommandOf($refund));

        $unknown = $this->runWithFirmContext($firm, fn () => PaymentRefund::query()->findOrFail($refund->id));

        $this->assertSame(ProviderOutcomeApplierService::STILL_UNKNOWN, $this->recovery()->recoverRefund($unknown));

        $this->runWithFirmContext($firm, function () use ($refund) {
            $this->assertSame(PaymentRefundState::OutcomeUnknown, PaymentRefund::query()->findOrFail($refund->id)->state);
        });
    }

    /**
     * FV-A3-046 — duplicate refund-command delivery cannot duplicate the
     * refund: one adapter call, one posting, send_count = 1.
     */
    public function test_fv_a3_046_duplicate_refund_command_cannot_duplicate_refund(): void
    {
        [$firm, $attempt, $provider] = $this->capturedViaProvider();

        $refund = $this->submittedRefund($firm, $attempt, 'fake:success', 4_000, (int) $provider->id);
        $command = $this->payCommandOf($refund);

        $first = $this->executor()->execute($command);
        $redelivered = $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->findOrFail($command->id));
        $second = $this->executor()->execute($redelivered);

        $this->assertSame(ProviderCommandExecutorService::RESULT_EXECUTED, $first);
        $this->assertSame(ProviderCommandExecutorService::RESULT_DUPLICATE_DELIVERY_NOOP, $second);
        $this->assertSame(1, $this->payFake()->refundCalls, 'The provider was refunded exactly once.');

        $this->runWithFirmContext($firm, function () use ($refund) {
            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)
                ->count());
        });

        $gate = DB::table('provider_operation_attempts')
            ->where('logical_operation_key', 'fvpay:'.$command->uuid)->first();
        $this->assertSame(1, (int) $gate->send_count);
    }
}
