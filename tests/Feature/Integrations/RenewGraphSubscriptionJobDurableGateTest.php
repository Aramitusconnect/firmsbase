<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\RenewGraphSubscriptionJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\Support\NonBillableWebhookStubProvider;
use Tests\TestCase;
use Throwable;

/**
 * RenewGraphSubscriptionJobDurableGateTest — Checkpoint 8.2 §A7, for the
 * path that matters most: the DIRECT provider call every real Microsoft
 * 365 / Google Workspace webhook renewal makes.
 *
 * That path never went through `ProviderBillableCallPipeline` (only Plaid
 * implements `RequiresBillableCallPipelineContract`), so before this
 * checkpoint it had no at-most-once protection whatsoever: a renewal that
 * succeeded at the provider and then failed locally was simply renewed
 * again on the next attempt, creating a duplicate provider-side
 * subscription and duplicated inbound webhook traffic.
 *
 * The billable/Plaid path is covered by
 * `Billing\RenewGraphSubscriptionJobIdempotencyKeyTest`; this file is
 * deliberately about the non-billable one.
 */
class RenewGraphSubscriptionJobDurableGateTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    private NonBillableWebhookStubProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
        Cache::flush();

        $this->provider = new NonBillableWebhookStubProvider;
        $this->app->instance(NonBillableWebhookStubProvider::class, $this->provider);

        config(['integrations.providers' => [
            ProviderKey::Microsoft365->value => NonBillableWebhookStubProvider::class,
        ]]);
    }

    private function firm(): Firm
    {
        // Committed on the independent connection because the pipeline-free
        // path still records audit/health rows there; mirrors the sibling
        // suite's identical fixture discipline.
        $firm = Firm::factory()->connection(self::DURABLE_CONNECTION)->create();

        $this->beforeApplicationDestroyed(function () use ($firm) {
            $durable = DB::connection(self::DURABLE_CONNECTION);

            $durable->transaction(function () use ($durable, $firm) {
                $durable->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                $durable->table('timeline_events')->where('firm_id', $firm->id)->delete();
            });

            Firm::on(self::DURABLE_CONNECTION)->where('id', $firm->id)->delete();
        });

        return $firm;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create(['status' => ConnectionStatus::Active->value]));
    }

    private function subscription(Firm $firm, FirmIntegration $connection): IntegrationProviderWebhookSubscription
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::factory()
            ->forFirmIntegration($connection)
            ->create([
                'provider_key' => ProviderKey::Microsoft365->value,
                'provider_subscription_id' => 'graph-subscription-original',
                'expires_at' => now()->addHours(2),
                'status' => ProviderWebhookSubscriptionStatus::Active->value,
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

    private function attemptRow(Firm $firm): ?object
    {
        return DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', $firm->id)
            ->orderByDesc('id')
            ->first();
    }

    private function subscriptionRow(Firm $firm, int $subscriptionId): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
            ->where('id', $subscriptionId)
            ->first());
    }

    /**
     * Makes the LOCAL apply fail after the provider has already succeeded,
     * by throwing from an Eloquent `updated` listener — a real failure at a
     * real point inside `applySubscriptionState()`'s own transaction.
     * Event-facade listeners are per-test, so nothing leaks.
     */
    private function failTheLocalWrite(): void
    {
        Event::listen('eloquent.updated: '.IntegrationProviderWebhookSubscription::class, function (): void {
            throw new RuntimeException('local subscription write exploded');
        });
    }

    private function stopFailingTheLocalWrite(): void
    {
        Event::forget('eloquent.updated: '.IntegrationProviderWebhookSubscription::class);
    }

    // ------------------------------------------------------------------

    public function test_a_direct_graph_renewal_is_gated_and_settles_end_to_end(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->assertNull($this->runJob($firm, $connection, $subscription));
        $this->assertSame(1, $this->provider->renewCalls);

        $attempt = $this->attemptRow($firm);
        $this->assertNotNull($attempt, 'The non-pipeline path must still record a durable gate row.');
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $attempt->attempt_state);
        $this->assertSame(1, (int) $attempt->send_count);
        $this->assertSame('webhook_subscription.renew', $attempt->operation_type);

        $row = $this->subscriptionRow($firm, $subscription->id);
        $this->assertSame('graph-subscription-renewed', $row->provider_subscription_id);
    }

    public function test_a_repeated_tick_for_the_same_renewal_cycle_never_calls_the_provider_twice(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->runJob($firm, $connection, $subscription);
        $this->assertSame(1, $this->provider->renewCalls);

        // A second scheduler tick that raced the first: same subscription,
        // but the renewal cycle it names has already been performed. (The
        // cycle token is derived from the subscription's provider id and
        // expiry, both of which the first run rewrote — so this second run
        // computes a NEW cycle and legitimately renews again. Force the
        // ORIGINAL cycle by restoring the row's pre-renewal identity,
        // which is exactly the state a rolled-back local write leaves.)
        $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
            ->where('id', $subscription->id)
            ->update([
                'provider_subscription_id' => 'graph-subscription-original',
                'expires_at' => $subscription->expires_at,
                'status' => ProviderWebhookSubscriptionStatus::Active->value,
            ]));

        $this->runJob($firm, $connection, $subscription);

        $this->assertSame(
            1,
            $this->provider->renewCalls,
            'One renewal cycle must produce at most one provider call, even if local state was lost.'
        );
    }

    public function test_a_renewal_that_succeeded_then_failed_locally_is_resumed_from_durable_evidence(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->failTheLocalWrite();
        $failure = $this->runJob($firm, $connection, $subscription);

        $this->assertNotNull($failure, 'The local failure must surface to the caller.');
        $this->assertSame(1, $this->provider->renewCalls);

        $attempt = $this->attemptRow($firm);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingFailed->value, $attempt->attempt_state);
        $this->assertSame(1, (int) $attempt->send_count, 'The send is on the record despite the local failure.');
        $this->assertNotNull($attempt->redacted_result_metadata, 'Recovery evidence must have been kept.');

        // The evidence holds ONLY the two non-secret fields this system
        // already stores in plaintext for this subscription.
        $evidence = json_decode((string) $attempt->redacted_result_metadata, true);
        $this->assertSame(['provider_subscription_id', 'expires_at'], array_keys($evidence));
        $this->assertSame('graph-subscription-renewed', $evidence['provider_subscription_id']);

        // The retry, with a healthy local layer: resumed, not renewed.
        $this->stopFailingTheLocalWrite();
        $this->assertNull($this->runJob($firm, $connection, $subscription));

        $this->assertSame(
            1,
            $this->provider->renewCalls,
            'The provider already did the work — a resume must never send again.'
        );

        $row = $this->subscriptionRow($firm, $subscription->id);
        $this->assertSame(
            'graph-subscription-renewed',
            $row->provider_subscription_id,
            'The local row must end up carrying what the provider actually returned.'
        );
        $this->assertSame(
            ProviderOperationAttemptState::LocalProcessingComplete->value,
            $this->attemptRow($firm)->attempt_state
        );
    }

    public function test_an_ambiguous_provider_failure_demands_reconciliation_instead_of_renewing_again(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->provider->onRenew = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'renewSubscription');
        };

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));
        $this->assertSame(1, $this->provider->renewCalls);

        $attempt = $this->attemptRow($firm);
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired->value, $attempt->attempt_state);
        $this->assertStringContainsString('uncertain_provider_outcome:', (string) $attempt->reconciliation_reason);

        // Every further attempt at the same cycle is refused loudly. A
        // timeout may mean the subscription WAS created, so renewing again
        // would risk a duplicate.
        $retryFailure = $this->runJob($firm, $connection, $subscription);

        $this->assertInstanceOf(ProviderOperationRequiresReconciliationException::class, $retryFailure);
        $this->assertSame(1, $this->provider->renewCalls);
    }

    public function test_a_definite_provider_rejection_stays_retryable(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $attempts = 0;
        $this->provider->onRenew = static function () use (&$attempts): array {
            $attempts++;

            if ($attempts === 1) {
                throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'renewSubscription');
            }

            return [
                'subscription_id' => 'graph-subscription-renewed',
                'expires_at' => now()->addHours(70)->toIso8601String(),
            ];
        };

        $this->assertNotNull($this->runJob($firm, $connection, $subscription));
        $this->assertSame(ProviderOperationAttemptState::ProviderRejected->value, $this->attemptRow($firm)->attempt_state);

        // The queue retry recovers: a 429 is positive knowledge that no
        // subscription was created.
        $this->assertNull($this->runJob($firm, $connection, $subscription));
        $this->assertSame(2, $this->provider->renewCalls);

        $final = $this->attemptRow($firm);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $final->attempt_state);
        $this->assertSame(1, (int) $final->send_count, 'The new generation sent once.');
        $this->assertSame(2, (int) $final->total_send_count, 'Both sends stay on the record.');
    }

    public function test_a_genuine_404_falls_back_to_subscribe_as_its_own_gated_operation(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->provider->onRenew = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, 404, 'renewSubscription');
        };

        $this->assertNull($this->runJob($firm, $connection, $subscription));

        $this->assertSame(1, $this->provider->renewCalls);
        $this->assertSame(1, $this->provider->subscribeCalls, 'A subscription gone at the provider is re-created.');

        // Two distinct logical operations, each gated once.
        $rows = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', $firm->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('webhook_subscription.renew', $rows[0]->operation_type);
        $this->assertSame(ProviderOperationAttemptState::ProviderRejected->value, $rows[0]->attempt_state);
        $this->assertSame('webhook_subscription.subscribe', $rows[1]->operation_type);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $rows[1]->attempt_state);

        $row = $this->subscriptionRow($firm, $subscription->id);
        $this->assertSame('graph-subscription-new', $row->provider_subscription_id);
    }

    public function test_no_transaction_or_lock_is_held_across_the_renewal_call(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);
        $levelBefore = DB::transactionLevel();
        $observedLevel = null;

        $this->provider->onRenew = function () use (&$observedLevel): array {
            $observedLevel = DB::transactionLevel();

            return [
                'subscription_id' => 'graph-subscription-renewed',
                'expires_at' => now()->addHours(70)->toIso8601String(),
            ];
        };

        $this->runJob($firm, $connection, $subscription);

        $this->assertSame(
            $levelBefore,
            $observedLevel,
            'The renewal call must not run inside a transaction this job opened.'
        );
    }

    public function test_tenant_context_is_restored_even_when_the_renewal_fails(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);
        $subscription = $this->subscription($firm, $connection);

        $this->provider->onRenew = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'renewSubscription');
        };

        app(TenantContextService::class)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->runJob($firm, $connection, $subscription);

        $this->assertNoDatabaseTenantContext(
            'The session-scoped provider phase must restore context even on failure.'
        );
    }
}
