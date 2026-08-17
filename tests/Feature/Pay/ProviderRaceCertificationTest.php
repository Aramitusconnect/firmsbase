<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRefundState;
use App\Enums\ProviderOutcome;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\ProviderCommand;
use App\Services\Pay\Data\FakeProviderEvent;
use App\Services\Pay\PaymentOutcomeRecoveryService;
use App\Services\Pay\ProviderCommandExecutorService;
use App\Services\Pay\ProviderEventIngestionService;
use App\Services\Pay\RefundReservationService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\BuildsPayFixtures;
use Tests\Feature\Pay\Concerns\CleansUpPayAuditFixtures;
use Tests\TestCase;

/**
 * FV-A3-020 / FV-A3-021 / FV-A3-022 / FV-A3-047 — GENUINE concurrency
 * certification for the exactly-once applier. CERTIFICATION BLOCKING.
 *
 * Real OS-process concurrency via pcntl_fork() (the repository's
 * established race-test precedent: PlatformAdminRecoveryCodeRaceTest,
 * ProviderResourceOwnershipRaceTest, RefundReservationRaceTest). Two
 * processes, each with its OWN PostgreSQL connection, race the same
 * economic fact through DIFFERENT paths. The invariant proven is the
 * POST-CONDITION — exactly one terminal state, one journal effect, one
 * ownership relationship — regardless of interleaving, which is what
 * makes these deterministic despite scheduling nondeterminism
 * (v1.4 §47: no assertion below depends on WHO wins).
 *
 * The mechanism under test is ProviderOutcomeApplierService's
 * FOR-UPDATE row lock + terminal-state no-op + the journal's partial
 * UNIQUE — the same single applier every path converges on.
 *
 * NO RefreshDatabase: forked children need COMMITTED fixtures
 * (PostgreSQL MVCC). Fixtures are torn down explicitly; the firm
 * cascade removes the financial rows, and the durable audit rows are
 * purged BEFORE the firm goes (CleansUpPayAuditFixtures).
 */
class ProviderRaceCertificationTest extends TestCase
{
    use BuildsPayFixtures, CleansUpPayAuditFixtures;

    /** @var list<int> */
    private array $firmIds = [];

    protected function tearDown(): void
    {
        DB::purge();

        $this->purgeAuditFixturesForFirms($this->firmIds);

        if ($this->firmIds !== []) {
            // The firm cascade removes every Pay/financial fixture row;
            // journal legs, attempts, commands, canonical events and
            // mode-B ownership rows all carry ON DELETE CASCADE firm
            // paths.
            DB::table('firms')->whereIn('id', $this->firmIds)->delete();
        }

        $this->assertNoOrphanedPayAuditRows();

        parent::tearDown();
    }

    /**
     * @return array{0: Firm, 1: PaymentAttempt, 2: IntegrationProvider}
     */
    private function unknownPaymentFixture(): array
    {
        $firm = $this->payFirmWithAccounting();
        $this->firmIds[] = (int) $firm->id;
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:timeout-success');
        app(ProviderCommandExecutorService::class)->execute($this->payCommandOf($attempt));

        $unknown = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $this->assertSame(PaymentAttemptState::OutcomeUnknown, $unknown->state);

        return [$firm, $unknown, $provider];
    }

    /**
     * Fork, run $parentSide and $childSide in genuinely separate
     * processes/connections, and return [parentPayload, childPayload].
     *
     * @return array{0: string, 1: string}
     */
    private function race(callable $parentSide, callable $childSide): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl is required for certification-blocking race tests (v1.4 §51).');
        }

        $childFile = tempnam(sys_get_temp_dir(), 'a3_race_child_');
        $parentFile = tempnam(sys_get_temp_dir(), 'a3_race_parent_');

        DB::disconnect();
        DB::purge();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            try {
                DB::purge();
                file_put_contents($childFile, (string) $childSide());
            } catch (\Throwable $e) {
                file_put_contents($childFile, 'error:'.substr($e->getMessage(), 0, 160));
            }

            exit(0);
        }

        try {
            DB::purge();
            file_put_contents($parentFile, (string) $parentSide());
        } catch (\Throwable $e) {
            file_put_contents($parentFile, 'error:'.substr($e->getMessage(), 0, 160));
        }

        pcntl_waitpid($pid, $status);

        $out = [trim((string) file_get_contents($parentFile)), trim((string) file_get_contents($childFile))];
        @unlink($parentFile);
        @unlink($childFile);
        DB::purge();

        return $out;
    }

    private function assertSingleCaptureEffect(Firm $firm, int $attemptId, int $providerId): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($attemptId, $providerId) {
            $fresh = PaymentAttempt::query()->findOrFail($attemptId);
            $this->assertSame(PaymentAttemptState::Captured, $fresh->state, 'ONE terminal result.');

            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attemptId)->count(), 'Journal effect exactly ONCE.');

            $this->assertSame(1, DB::table('integration_webhook_routing_index')
                ->where('integration_provider_id', $providerId)
                ->where('provider_resource_type', 'payment')
                ->where('provider_resource_id', $fresh->provider_reference)
                ->count(), 'Ownership relationship exactly ONCE.');
        });
    }

    /**
     * FV-A3-021 — outcome-recovery lookup racing the provider's success
     * EVENT for the same unknown payment: captured exactly once
     * economically, no invalid transition, no duplicate effect.
     */
    public function test_fv_a3_021_recovery_vs_provider_event_race_is_single_effect(): void
    {
        [$firm, $unknown, $provider] = $this->unknownPaymentFixture();

        // The provider's authoritative resource reference — the event
        // names it even though the timed-out caller never saw it.
        $resourceRef = $this->payFake()->resourceReferenceFor('fvpay:'.$this->payCommandOf($unknown)->uuid);
        $this->assertNotNull($resourceRef);

        [$parent, $child] = $this->race(
            parentSide: function () use ($firm, $unknown) {
                $fresh = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($unknown->id));

                return app(PaymentOutcomeRecoveryService::class)->recoverPayment($fresh);
            },
            childSide: function () use ($provider, $resourceRef, $unknown) {
                // Bounded, fixed retry — the event may arrive before
                // recovery has recorded ownership (UNRESOLVED is a valid
                // fail-closed answer); certification is the post-condition.
                $ingestion = app(ProviderEventIngestionService::class);
                $last = 'never_ran';

                for ($i = 0; $i < 40; $i++) {
                    $last = $ingestion->ingest(new FakeProviderEvent(
                        integrationProviderId: (int) $provider->id,
                        providerKey: $provider->code,
                        eventId: 'evt-race-021',
                        resourceType: 'payment',
                        resourceReference: (string) $resourceRef,
                        outcome: ProviderOutcome::Succeeded,
                        amountCents: (int) $unknown->amount_cents,
                        environment: 'sandbox',
                    ));

                    if ($last !== ProviderEventIngestionService::UNRESOLVED) {
                        break;
                    }

                    usleep(25_000);
                }

                return $last;
            },
        );

        $this->assertStringNotContainsString('error:', $parent, $parent);
        $this->assertStringNotContainsString('error:', $child, $child);

        $this->assertSingleCaptureEffect($firm, (int) $unknown->id, (int) $provider->id);
    }

    /**
     * FV-A3-022 — two event workers deliver the SAME provider event
     * concurrently: the canonical unique constraint plus the applier
     * lock make the economic effect single.
     */
    public function test_fv_a3_022_duplicate_event_workers_cannot_double_post(): void
    {
        $firm = $this->payFirmWithAccounting();
        $this->firmIds[] = (int) $firm->id;
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        app(ProviderCommandExecutorService::class)->execute($this->payCommandOf($attempt));

        $captured = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));

        $event = fn () => new FakeProviderEvent(
            integrationProviderId: (int) $provider->id,
            providerKey: $provider->code,
            eventId: 'evt-race-022',
            resourceType: 'payment',
            resourceReference: (string) $captured->provider_reference,
            outcome: ProviderOutcome::Succeeded,
            amountCents: (int) $captured->amount_cents,
            environment: 'sandbox',
        );

        [$parent, $child] = $this->race(
            parentSide: fn () => app(ProviderEventIngestionService::class)->ingest($event()),
            childSide: fn () => app(ProviderEventIngestionService::class)->ingest($event()),
        );

        $this->assertStringNotContainsString('error:', $parent, $parent);
        $this->assertStringNotContainsString('error:', $child, $child);

        $this->assertSingleCaptureEffect($firm, (int) $captured->id, (int) $provider->id);

        (new TenantContextService)->runWithFirmContext($firm, function () use ($connection, $provider) {
            $this->assertSame(1, DB::table('integration_inbound_webhook_events')
                ->where('firm_integration_id', $connection->id)
                ->where('provider_key', $provider->code)
                ->where('provider_event_id', 'evt-race-022')
                ->count(), 'Exactly one canonical event row survives two concurrent workers.');
        });
    }

    /**
     * FV-A3-047 — refund recovery lookup racing the refund success
     * event: one successful refund, one refund journal effect, one
     * reservation resolution.
     */
    public function test_fv_a3_047_refund_recovery_vs_event_race_is_single_effect(): void
    {
        $firm = $this->payFirmWithAccounting();
        $this->firmIds[] = (int) $firm->id;
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        app(ProviderCommandExecutorService::class)->execute($this->payCommandOf($attempt));
        $captured = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));

        $refunds = app(RefundReservationService::class);
        $refund = $refunds->submitToProvider($refunds->reserve($captured, 6_000, 'fake:timeout-success'), (int) $provider->id);
        app(ProviderCommandExecutorService::class)->execute($this->payCommandOf($refund));

        $unknown = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentRefund::query()->findOrFail($refund->id));
        $this->assertSame(PaymentRefundState::OutcomeUnknown, $unknown->state);

        $refundResourceRef = $this->payFake()->resourceReferenceFor('fvpay:'.$this->payCommandOf($unknown)->uuid);

        [$parent, $child] = $this->race(
            parentSide: function () use ($firm, $unknown) {
                $fresh = (new TenantContextService)->runWithFirmContext($firm, fn () => PaymentRefund::query()->findOrFail($unknown->id));

                return app(PaymentOutcomeRecoveryService::class)->recoverRefund($fresh);
            },
            childSide: function () use ($provider, $refundResourceRef, $unknown) {
                $ingestion = app(ProviderEventIngestionService::class);
                $last = 'never_ran';

                for ($i = 0; $i < 40; $i++) {
                    $last = $ingestion->ingest(new FakeProviderEvent(
                        integrationProviderId: (int) $provider->id,
                        providerKey: $provider->code,
                        eventId: 'evt-race-047',
                        resourceType: 'refund',
                        resourceReference: (string) $refundResourceRef,
                        outcome: ProviderOutcome::Succeeded,
                        amountCents: (int) $unknown->amount_cents,
                        environment: 'sandbox',
                    ));

                    if ($last !== ProviderEventIngestionService::UNRESOLVED && $last !== ProviderEventIngestionService::DEFERRED) {
                        break;
                    }

                    usleep(25_000);
                }

                return $last;
            },
        );

        $this->assertStringNotContainsString('error:', $parent, $parent);
        $this->assertStringNotContainsString('error:', $child, $child);

        (new TenantContextService)->runWithFirmContext($firm, function () use ($refund) {
            $fresh = PaymentRefund::query()->findOrFail($refund->id);
            $this->assertSame(PaymentRefundState::Succeeded, $fresh->state, 'ONE successful refund result.');

            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('idempotency_key', 'provider_refund_succeeded:payment_refund:'.$refund->id)
                ->count(), 'Refund journal effect exactly ONCE.');
        });
    }

    /**
     * FV-A3-020 (concurrent form) — the synchronous success response
     * racing the provider's success event. The sequential shape is
     * ProviderEventIngestionTest; this is the genuinely-parallel form.
     */
    public function test_fv_a3_020_sync_response_vs_event_race_is_single_effect(): void
    {
        $firm = $this->payFirmWithAccounting();
        $this->firmIds[] = (int) $firm->id;
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        $command = $this->payCommandOf($attempt);

        // The fake assigns references deterministically per instance
        // sequence; the forked child inherits a copy of the adapter, so
        // BOTH processes compute the same reference the provider will
        // assign — exactly the situation where the event can beat the
        // synchronous response.
        $predictedRef = 'fpr_000001';

        [$parent, $child] = $this->race(
            parentSide: function () use ($firm, $command) {
                $fresh = (new TenantContextService)->runWithFirmContext($firm, fn () => ProviderCommand::query()->findOrFail($command->id));

                return app(ProviderCommandExecutorService::class)->execute($fresh);
            },
            childSide: function () use ($provider, $predictedRef, $attempt) {
                $ingestion = app(ProviderEventIngestionService::class);
                $last = 'never_ran';

                for ($i = 0; $i < 40; $i++) {
                    $last = $ingestion->ingest(new FakeProviderEvent(
                        integrationProviderId: (int) $provider->id,
                        providerKey: $provider->code,
                        eventId: 'evt-race-020',
                        resourceType: 'payment',
                        resourceReference: $predictedRef,
                        outcome: ProviderOutcome::Succeeded,
                        amountCents: (int) $attempt->amount_cents,
                        environment: 'sandbox',
                    ));

                    if ($last !== ProviderEventIngestionService::UNRESOLVED && $last !== ProviderEventIngestionService::DEFERRED) {
                        break;
                    }

                    usleep(25_000);
                }

                return $last;
            },
        );

        $this->assertStringNotContainsString('error:', $parent, $parent);
        $this->assertStringNotContainsString('error:', $child, $child);

        $this->assertSingleCaptureEffect($firm, (int) $attempt->id, (int) $provider->id);
    }
}
