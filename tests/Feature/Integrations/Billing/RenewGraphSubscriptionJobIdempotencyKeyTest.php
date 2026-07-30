<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Enums\EntitlementSource;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\RenewGraphSubscriptionJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\Support\BillableWebhookStubProvider;
use Tests\TestCase;
use Throwable;

/**
 * RenewGraphSubscriptionJobIdempotencyKeyTest — the job-level half of the
 * double-billing remediation.
 *
 * RenewGraphSubscriptionJob is `$tries = 5` with
 * `backoff() = [30, 60, 120, 240]`, and its two pipeline call sites used
 * to key their reservation on `now()->format('YmdHi')`. Because the very
 * first backoff (30s) already frequently crosses a minute boundary and
 * every later one (60/120/240s) crosses one in practice, each retry
 * computed a DIFFERENT idempotency key — so
 * `ProviderUsageReservationService::reserve()` saw no conflict at all,
 * INSERTed a brand-new reservation, and the pipeline made another REAL,
 * separately-billed outbound call for ONE logical renewal.
 *
 * The key is now derived from the renewal CYCLE — the subscription's
 * current provider-side id and expiry, both re-read fresh at the top of
 * every attempt and both rewritten only once a renewal actually succeeds
 * — so it is stable across wall-clock time and changes exactly when the
 * logical operation changes.
 *
 * The key is captured from INSIDE the provider call (where the
 * reservation row already exists) rather than after the job returns,
 * because `TenantAwareJobContext::runInFirmContext()` wraps the entire
 * job body in one `DB::transaction()` — see
 * test_a_failed_attempts_reservation_is_rolled_back_with_the_jobs_own_transaction()
 * below, which characterises that pre-existing behaviour explicitly.
 *
 * No real Plaid, no real HTTP: the provider is
 * `Tests\Support\BillableWebhookStubProvider`, a counting stub
 * implementing the same two contracts PlaidProvider does.
 */
class RenewGraphSubscriptionJobIdempotencyKeyTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private BillableWebhookStubProvider $provider;

    /** @var list<?string> */
    private array $capturedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
        Cache::flush();

        $this->provider = new BillableWebhookStubProvider;
        $this->app->instance(BillableWebhookStubProvider::class, $this->provider);

        config(['integrations.providers' => [
            ProviderKey::Plaid->value => BillableWebhookStubProvider::class,
        ]]);
    }

    private function firmWithEntitlement(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();

        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });

        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function plaidConnection(Firm $firm): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();

        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create(['status' => ConnectionStatus::Active->value]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscription(Firm $firm, FirmIntegration $connection, array $overrides = []): IntegrationProviderWebhookSubscription
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::factory()
            ->forFirmIntegration($connection)
            ->create([
                'provider_key' => ProviderKey::Plaid->value,
                'provider_subscription_id' => 'sub-original',
                'expires_at' => now()->addHours(2),
                'status' => ProviderWebhookSubscriptionStatus::Active->value,
                ...$overrides,
            ]));
    }

    private function runJob(Firm $firm, FirmIntegration $connection, IntegrationProviderWebhookSubscription $subscription): ?Throwable
    {
        try {
            (new RenewGraphSubscriptionJob($connection->id, $firm->id, $subscription->id))
                ->handle(app(ProviderRegistry::class), app(HealthStateService::class));

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * Captures the idempotency key of the reservation the pipeline just
     * created, read from inside the provider call itself — the one point
     * where the row provably exists regardless of how the attempt ends.
     */
    private function captureKeyThenFail(): void
    {
        $test = $this;

        $this->provider->onRenew = static function () use ($test): array {
            $test->recordCapturedKey();

            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, 500, 'renewSubscription');
        };
    }

    public function recordCapturedKey(): void
    {
        $this->capturedKeys[] = DB::table('provider_billable_call_reservations')
            ->orderByDesc('id')
            ->value('idempotency_key');
    }

    /** @return list<string> */
    private function persistedReservationKeys(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->orderBy('id')
            ->pluck('idempotency_key')
            ->all());
    }

    private function usageRecordCount(Firm $firm): int
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()->count());
    }

    // ------------------------------------------------------------

    public function test_two_attempts_at_the_same_renewal_in_different_wall_clock_minutes_share_one_idempotency_key(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscription = $this->subscription($firm, $connection);
        $this->captureKeyThenFail();

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));

        // The first backoff is 30s; travelling a full 90s guarantees the
        // retry lands in a different wall-clock minute — the exact
        // condition the old `YmdHi` key broke on.
        $this->travel(90)->seconds();

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));

        $this->assertCount(2, $this->capturedKeys);
        $this->assertNotNull($this->capturedKeys[0]);
        $this->assertStringStartsWith('provider_webhook_renew:', (string) $this->capturedKeys[0]);
        $this->assertSame(
            $this->capturedKeys[0],
            $this->capturedKeys[1],
            'Two attempts at ONE renewal, in different minutes, must produce the SAME idempotency key.',
        );
    }

    public function test_the_full_five_attempt_backoff_sequence_produces_one_single_idempotency_key(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscription = $this->subscription($firm, $connection);
        $this->captureKeyThenFail();

        $this->runJob($firm, $connection, $subscription);

        foreach ([30, 60, 120, 240] as $backoffSeconds) {
            $this->travel($backoffSeconds)->seconds();
            $this->runJob($firm, $connection, $subscription);
        }

        $this->assertCount(5, $this->capturedKeys);
        $this->assertCount(
            1,
            array_unique($this->capturedKeys),
            'All five attempts across the 30/60/120/240s backoff sequence must share one key.',
        );
    }

    public function test_a_successful_renewal_is_billed_exactly_once(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->assertNull($this->runJob($firm, $connection, $subscription));

        $this->assertSame(1, $this->provider->renewCalls);
        $this->assertCount(1, $this->persistedReservationKeys($firm));
        $this->assertSame(1, $this->usageRecordCount($firm));
    }

    public function test_a_genuinely_new_renewal_cycle_gets_a_different_key_than_the_cycle_before_it(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->assertNull($this->runJob($firm, $connection, $subscription));

        // The renewal succeeded, so expires_at (and provider_subscription_id)
        // moved — the NEXT renewal is genuinely a different logical
        // operation and must therefore be separately reservable.
        $this->travel(90)->seconds();
        $subscription = $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::query()->findOrFail($subscription->id));

        $this->assertNull($this->runJob($firm, $connection, $subscription));

        $keys = $this->persistedReservationKeys($firm);
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'A genuinely different renewal cycle must get a different key.');
        $this->assertSame(2, $this->provider->renewCalls);
        $this->assertSame(2, $this->usageRecordCount($firm));
    }

    public function test_two_different_subscriptions_never_share_an_idempotency_key(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscriptionA = $this->subscription($firm, $connection);
        $subscriptionB = $this->subscription($firm, $connection, [
            'resource_type' => 'calendar_event',
            'provider_resource' => 'me/calendars/primary/events',
            'provider_subscription_id' => 'sub-other',
        ]);
        $this->captureKeyThenFail();

        $this->runJob($firm, $connection, $subscriptionA);
        $this->runJob($firm, $connection, $subscriptionB);

        $this->assertCount(2, $this->capturedKeys);
        $this->assertNotSame($this->capturedKeys[0], $this->capturedKeys[1]);
    }

    /**
     * CHECKPOINT 8.2 (§A6/§A7) — this REPLACES a characterisation test
     * that recorded the residual C3 gap as accepted behaviour.
     *
     * What it used to assert: that a failed attempt's reservation was
     * rolled back with the job's own job-wide transaction, and that
     * closing that window "requires the reservation ledger to be written
     * outside the ambient transaction ... well beyond this remediation's
     * scope."
     *
     * That work has now been done, though more narrowly than that note
     * anticipated: the job no longer wraps its whole body — provider call
     * included — in one transaction, so a failed attempt's evidence
     * survives; and the authoritative record of "this logical operation
     * already sent a request" lives in `provider_operation_attempts` on an
     * independent database session, which no rollback can reach.
     *
     * Both facts are asserted here, because either alone would mislead:
     * the reservation surviving only matters if the gate genuinely refuses
     * to re-send.
     */
    public function test_a_failed_attempts_evidence_survives_the_attempt(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscription = $this->subscription($firm, $connection);
        $this->captureKeyThenFail();

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));

        $this->assertCount(1, $this->capturedKeys, 'The reservation provably existed during the attempt...');
        $this->assertCount(
            1,
            $this->persistedReservationKeys($firm),
            '...and it is still there afterwards: the job no longer runs inside one all-or-nothing transaction.'
        );

        // The durable gate recorded the send on its own session, entirely
        // independently of how the job ended.
        $attempt = DB::connection('pgsql_audit')
            ->table('provider_operation_attempts')
            ->where('firm_id', $firm->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($attempt, 'The durable gate row must exist independently of the job.');
        $this->assertSame(1, (int) $attempt->total_send_count, 'Exactly one send is on the record.');

        // No usage record: a 500 is finalized non-billable, exactly as
        // before this checkpoint.
        $this->assertSame(0, $this->usageRecordCount($firm));
    }

    /**
     * The success-then-rollback case §A7 exists for: the provider really
     * performed the renewal, and then this side's own work failed. A retry
     * must never renew again.
     */
    public function test_a_renewal_that_succeeded_before_a_local_failure_is_never_repeated(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->plaidConnection($firm);
        $subscription = $this->subscription($firm, $connection);

        // The provider succeeds, but returns an expiry this side cannot
        // parse — extractSubscriptionState() throws AFTER the call.
        $this->provider->onRenew = static fn (): array => [
            'subscription_id' => 'sub-renewed',
            'expires_at' => 'not-a-parseable-date',
        ];

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));
        $this->assertSame(1, $this->provider->renewCalls);

        // The retry. The gate knows the provider already did the work.
        $this->runJob($firm, $connection, $subscription);

        $this->assertSame(
            1,
            $this->provider->renewCalls,
            'A renewal the provider already performed must never be sent a second time.'
        );
    }
}
