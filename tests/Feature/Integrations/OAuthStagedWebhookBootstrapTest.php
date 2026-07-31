<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\WebhookBootstrapState;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\BootstrapWebhookSubscriptionsJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\Support\OAuthWebhookStubProvider;
use Tests\TestCase;

/**
 * OAuthStagedWebhookBootstrapTest — Checkpoint 8.2 §A7b.
 *
 * WHAT CHANGED, AND WHY IT NEEDED TO. The webhook-subscription bootstrap
 * used to run INSIDE the OAuth completion transaction, while that
 * transaction held `FOR UPDATE` on the connection row. Its own docblock
 * stated the consequence as if it were a feature: a failure "rolls back
 * the entire OAuth connect, leaving the connection never `Active`, rather
 * than silently degrading to manual-sync-only."
 *
 * Two things were wrong with that. A provider HTTP call held a row lock
 * for its full duration — the exact shape Checkpoint 8.1 proved deadlocks
 * durable cross-session writes. And one transient `subscribe()` hiccup
 * threw away a COMPLETED, valid authorization, including the credential
 * just exchanged, forcing the user through the whole consent flow again.
 *
 * The bootstrap now runs after that transaction commits, and the
 * connection carries an explicit `webhook_bootstrap_state` so the
 * intermediate reality — connected, but push delivery not yet live — is
 * visible instead of invisible.
 */
final class OAuthStagedWebhookBootstrapTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private OAuthWebhookStubProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();

        // CHECKPOINT 8.2 (§A-bootstrap-retry). Faked globally, not just
        // in the handful of tests that used to opt in individually:
        // BootstrapWebhookSubscriptionsJob::handle() now rethrows a
        // genuinely retryable failure so a REAL queue's own $tries/
        // backoff() can see it (see that job's own docblock). Under the
        // `sync` queue driver this test suite otherwise runs with,
        // dispatch() would execute that job INLINE, synchronously,
        // immediately after completeOAuthCallback()'s own first attempt
        // — and Laravel's sync connector does not honor $tries at all
        // (a single exception is treated as final), so an unfaked queue
        // would let that uncontrolled second attempt exhaust the
        // connection to `bootstrap_failed` before a test ever gets to
        // observe the real, single-attempt `bootstrap_pending_retry`
        // outcome. Faking the queue here is what makes "one HTTP-facing
        // attempt, one recorded outcome" observable at all; every test
        // that wants a retry to actually run does so explicitly via its
        // own `(new BootstrapWebhookSubscriptionsJob(...))->handle(...)`
        // or `ProviderConnectionService::retryWebhookBootstrap()` call.
        Queue::fake();

        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        $this->provider = new OAuthWebhookStubProvider;
        $this->app->instance(OAuthWebhookStubProvider::class, $this->provider);
        config(['integrations.providers' => [ProviderKey::Test->value => OAuthWebhookStubProvider::class]]);

        Http::fake();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function service(): ProviderConnectionService
    {
        return app(ProviderConnectionService::class);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(): array
    {
        $firm = Firm::factory()->create();
        TenantEncryptionKey::factory()->forFirm($firm)->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->pending()
            ->create([
                'external_account_id' => null,
                'webhook_routing_token' => null,
                'requested_capabilities_json' => [ResourceType::Contact->value],
            ]));

        $user = User::factory()->create();
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'role' => FirmUserRole::Attorney->value,
        ]));

        return [$firm, $connection, $firmUser];
    }

    private function completeConnect(Firm $firm, FirmIntegration $connection, FirmUser $firmUser)
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $initiation = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($initiation->authorizationUrl, PHP_URL_QUERY), $query);

        $code = $this->provider->delegate()->simulateAuthorizationGrant($query['code_challenge']);

        return $this->service()->completeOAuthCallback($query['state'], $code, $firmUser->user_id);
    }

    private function freshConnection(Firm $firm, int $connectionId): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->findOrFail($connectionId));
    }

    private function subscriptionCount(Firm $firm, int $connectionId): int
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::query()
            ->where('firm_integration_id', $connectionId)
            ->count());
    }

    private function credentialCount(Firm $firm, int $connectionId): int
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connectionId)
            ->count());
    }

    private function failSubscribeWith(SanitizedProviderHttpException $exception): void
    {
        $this->provider->onSubscribe = static function () use ($exception): array {
            throw $exception;
        };
    }

    // ------------------------------------------------------------------
    // 1. The happy path still ends fully bootstrapped.
    // ------------------------------------------------------------------

    public function test_a_successful_connect_bootstraps_its_subscriptions_and_reports_complete(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $result = $this->completeConnect($firm, $connection, $firmUser);

        $this->assertSame(ConnectionStatus::Active, $result->status);
        $this->assertSame(1, $this->provider->subscribeCalls);
        $this->assertSame(1, $this->subscriptionCount($firm, $connection->id));
        $this->assertSame(
            WebhookBootstrapState::Complete,
            $this->freshConnection($firm, $connection->id)->webhook_bootstrap_state
        );
    }

    // ------------------------------------------------------------------
    // 2. The defect: a subscribe failure no longer destroys the connect.
    // ------------------------------------------------------------------

    public function test_a_subscribe_failure_no_longer_rolls_back_the_completed_authorization(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'subscribe'
        ));

        $result = $this->completeConnect($firm, $connection, $firmUser);

        // The authorization survived, in full.
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $fresh = $this->freshConnection($firm, $connection->id);
        $this->assertSame(ConnectionStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->connected_at);
        $this->assertGreaterThan(
            0,
            $this->credentialCount($firm, $connection->id),
            'The credential exchanged during OAuth must NOT be rolled back by a webhook-subscribe failure.'
        );

        // And the degradation is recorded rather than hidden.
        $this->assertSame(WebhookBootstrapState::PendingRetry, $fresh->webhook_bootstrap_state);
        $this->assertSame(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, $fresh->webhook_bootstrap_error);
        $this->assertNotNull($fresh->webhook_bootstrap_attempted_at);
        $this->assertSame(0, $this->subscriptionCount($firm, $connection->id));
    }

    // ------------------------------------------------------------------
    // 3. A retryable failure queues a retry.
    // ------------------------------------------------------------------

    public function test_a_retryable_failure_queues_a_bootstrap_retry_job(): void
    {
        Queue::fake();

        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'subscribe'
        ));

        $this->completeConnect($firm, $connection, $firmUser);

        Queue::assertPushed(
            BootstrapWebhookSubscriptionsJob::class,
            fn (BootstrapWebhookSubscriptionsJob $job): bool => $job->firmIntegrationId === $connection->id
                && $job->firmId === $firm->id
        );
    }

    public function test_the_queued_retry_completes_the_bootstrap_once_the_provider_recovers(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'subscribe'
        ));

        $this->completeConnect($firm, $connection, $firmUser);
        $this->assertSame(WebhookBootstrapState::PendingRetry, $this->freshConnection($firm, $connection->id)->webhook_bootstrap_state);

        // The provider recovers; the retry job runs.
        $this->provider->onSubscribe = static fn (): array => [
            'subscription_id' => 'stub-webhook-subscription',
            'status' => 'active',
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ];

        (new BootstrapWebhookSubscriptionsJob($connection->id, $firm->id, $firmUser->user_id))
            ->handle($this->service());

        $fresh = $this->freshConnection($firm, $connection->id);
        $this->assertSame(WebhookBootstrapState::Complete, $fresh->webhook_bootstrap_state);
        $this->assertNull($fresh->webhook_bootstrap_error);
        $this->assertSame(1, $this->subscriptionCount($firm, $connection->id));
        $this->assertSame(
            ProviderWebhookSubscriptionStatus::Active,
            $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::query()
                ->where('firm_integration_id', $connection->id)
                ->firstOrFail())->status
        );
    }

    /**
     * REGRESSION for a defect this checkpoint introduced and then fixed:
     * the retry path dispatched its own successor unconditionally, so a
     * retry that failed again queued another retry. Under a synchronous
     * queue driver that dispatch runs INLINE, which recursed until the
     * process was killed — and even on a real queue it was an uncapped
     * retry chain with no terminal state. Repetition belongs to
     * BootstrapWebhookSubscriptionsJob's own $tries/backoff() alone.
     */
    public function test_a_retry_never_queues_another_retry(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'subscribe'
        ));

        $this->completeConnect($firm, $connection, $firmUser);

        // Fake the queue only NOW, so the initial dispatch above is real
        // and only the retry's own behavior is measured.
        Queue::fake();

        $state = $this->service()->retryWebhookBootstrap($connection->id, $firm->id, $firmUser->user_id);

        $this->assertSame(WebhookBootstrapState::PendingRetry, $state);
        Queue::assertNotPushed(
            BootstrapWebhookSubscriptionsJob::class,
            'A retry must never queue its own successor.'
        );
    }

    // ------------------------------------------------------------------
    // 4. An ambiguous failure is never retried automatically.
    // ------------------------------------------------------------------

    public function test_an_ambiguous_subscribe_failure_requires_reconciliation_and_is_never_auto_retried(): void
    {
        Queue::fake();

        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'subscribe'
        ));

        $this->completeConnect($firm, $connection, $firmUser);

        $fresh = $this->freshConnection($firm, $connection->id);
        $this->assertSame(WebhookBootstrapState::ReconciliationRequired, $fresh->webhook_bootstrap_state);
        $this->assertTrue($fresh->webhook_bootstrap_state->needsHumanAttention());

        Queue::assertNotPushed(BootstrapWebhookSubscriptionsJob::class);

        // Even an explicit retry refuses: a timed-out subscribe may have
        // created a subscription at the provider, and a blind retry is
        // exactly how a duplicate is created.
        $this->provider->onSubscribe = static fn (): array => [
            'subscription_id' => 'would-be-duplicate',
            'status' => 'active',
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ];
        $subscribeCallsBefore = $this->provider->subscribeCalls;

        $state = $this->service()->retryWebhookBootstrap($connection->id, $firm->id, $firmUser->user_id, force: true);

        $this->assertSame(WebhookBootstrapState::ReconciliationRequired, $state);
        $this->assertSame($subscribeCallsBefore, $this->provider->subscribeCalls, 'No further provider call may be made.');
    }

    // ------------------------------------------------------------------
    // 5. A definite failure is terminal, not retried.
    // ------------------------------------------------------------------

    public function test_a_definite_subscribe_failure_is_recorded_as_failed_without_a_retry(): void
    {
        Queue::fake();

        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_AUTHORIZATION_FAILED, 403, 'subscribe'
        ));

        $this->completeConnect($firm, $connection, $firmUser);

        $fresh = $this->freshConnection($firm, $connection->id);
        $this->assertSame(WebhookBootstrapState::Failed, $fresh->webhook_bootstrap_state);
        $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHORIZATION_FAILED, $fresh->webhook_bootstrap_error);
        Queue::assertNotPushed(BootstrapWebhookSubscriptionsJob::class);

        // And an automated retry declines to touch it.
        $this->assertSame(
            WebhookBootstrapState::Failed,
            $this->service()->retryWebhookBootstrap($connection->id, $firm->id, $firmUser->user_id)
        );
    }

    // ------------------------------------------------------------------
    // 6. The intermediate state is durable, and honest.
    // ------------------------------------------------------------------

    public function test_the_pending_state_is_committed_with_the_connection_so_a_crash_leaves_it_visible(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        // Simulate the worst case: the process dies immediately after the
        // OAuth transaction commits, before the bootstrap runs at all. The
        // state written INSIDE that transaction is what a later reader
        // sees.
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'subscribe'
        ));
        $this->completeConnect($firm, $connection, $firmUser);

        $fresh = $this->freshConnection($firm, $connection->id);
        $this->assertTrue(
            $fresh->webhook_bootstrap_state->isDegraded(),
            'A connection whose push delivery is not live must never look fully healthy.'
        );
        $this->assertStringContainsString(
            'Scheduled and manual syncs work normally',
            $fresh->webhook_bootstrap_state->firmFacingSummary(),
            'The firm-facing copy must say what still works, not just what broke.'
        );
    }

    public function test_a_provider_with_nothing_to_subscribe_is_marked_not_required_rather_than_pending(): void
    {
        $firm = Firm::factory()->create();
        TenantEncryptionKey::factory()->forFirm($firm)->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        // No requested capabilities => nothing intersects => nothing to do.
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->pending()
            ->create([
                'external_account_id' => null,
                'webhook_routing_token' => null,
                'requested_capabilities_json' => [],
            ]));

        $user = User::factory()->create();
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'role' => FirmUserRole::Attorney->value,
        ]));

        $this->completeConnect($firm, $connection, $firmUser);

        $this->assertSame(
            WebhookBootstrapState::NotRequired,
            $this->freshConnection($firm, $connection->id)->webhook_bootstrap_state
        );
        $this->assertSame(0, $this->provider->subscribeCalls);
    }

    // ------------------------------------------------------------------
    // 7. No provider call inside the OAuth transaction.
    // ------------------------------------------------------------------

    public function test_the_subscribe_call_happens_outside_the_oauth_transaction(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $levelBefore = DB::transactionLevel();
        $observed = null;

        $this->provider->onSubscribe = function () use (&$observed): array {
            $observed = DB::transactionLevel();

            return [
                'subscription_id' => 'stub-webhook-subscription',
                'status' => 'active',
                'expires_at' => now()->addDays(3)->toIso8601String(),
            ];
        };

        $this->completeConnect($firm, $connection, $firmUser);

        $this->assertNotNull($observed, 'subscribe() must actually have been called.');
        $this->assertSame(
            $levelBefore,
            $observed,
            'subscribe() must not run inside the OAuth completion transaction — that transaction holds FOR UPDATE on the connection row.'
        );
    }

    // ------------------------------------------------------------------
    // 8. Every state change is audited.
    // ------------------------------------------------------------------

    public function test_every_bootstrap_state_change_is_audited(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->failSubscribeWith(new SanitizedProviderHttpException(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'subscribe'
        ));

        $this->completeConnect($firm, $connection, $firmUser);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_oauth.webhook_bootstrap_state_changed')
            ->where('subject_id', $connection->id)
            ->latest('id')
            ->first());

        $this->assertNotNull($event, 'A bootstrap outcome must be auditable.');
        $this->assertSame(
            WebhookBootstrapState::PendingRetry->value,
            $event->metadata_json['webhook_bootstrap_state'] ?? null
        );
        $this->assertSame(
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED,
            $event->metadata_json['sanitized_category'] ?? null,
            'Only the sanitized category is recorded — never a provider message.'
        );
    }
}
