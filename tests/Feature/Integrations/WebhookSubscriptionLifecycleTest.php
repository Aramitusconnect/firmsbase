<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\FirmActivationStatus;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Jobs\RenewGraphSubscriptionJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Integrations\Services\HealthStateService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WebhookSubscriptionLifecycleTest — FirmsVault Live Integrations,
 * Checkpoint 2 (test-writer pass). Covers the webhook-subscription
 * establishment/renewal machinery end to end at the service/job/command
 * layer (checkpoint2-combined-design.md §2 P-17-P-20):
 *
 *  1. Microsoft365Provider::subscribe() — idempotent from FirmsVault's
 *     own side first: a non-expired Active row for the same
 *     (connection, resource, changeType) means Graph is never called
 *     again.
 *  2. RenewGraphSubscriptionJob — re-verifies connection/subscription
 *     state FRESH at execution time; a connection disconnected between
 *     schedule-time and execution-time is silently no-op'd, never
 *     renewed.
 *  3. RenewProviderWebhookSubscriptionsCommand — selects only
 *     subscriptions within the dynamic safety-margin window, not every
 *     active subscription unconditionally.
 */
final class WebhookSubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.providers' => [ProviderKey::Microsoft365->value => Microsoft365Provider::class]]);
    }

    private function microsoft365ConnectionWithCredential(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($providerRow)->create([
                'external_account_id' => null,
                'status' => $status->value,
            ]),
        );

        $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->ofType(CredentialType::OauthAccessToken)->create(),
        );

        return $connection;
    }

    // ------------------------------------------------------------
    // 1. Microsoft365Provider::subscribe() — idempotency
    // ------------------------------------------------------------

    public function test_subscribe_does_not_call_graph_when_a_non_expired_active_row_already_exists(): void
    {
        // Empty Http::fake() (no rules registered) combined with the
        // suite-wide Http::preventStrayRequests() guard means ANY real
        // outbound call here would fail the test loudly — this proves
        // Graph is never called, not merely that we didn't bother
        // checking.
        Http::fake();

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $existing = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->create([
                    'provider_resource' => 'me/contacts',
                    'provider_change_type' => 'created,updated,deleted',
                    'status' => ProviderWebhookSubscriptionStatus::Active->value,
                    'expires_at' => now()->addHours(50),
                ]),
        );

        $provider = app(Microsoft365Provider::class);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $provider->subscribe(['connection' => $connection, 'resource_type' => ResourceType::Contact->value]),
        );

        Http::assertNothingSent();

        $this->assertSame($existing->provider_subscription_id, $result['subscription_id']);
        $this->assertSame('me/contacts', $result['resource']);
        $this->assertSame('created,updated,deleted', $result['change_type']);
        $this->assertSame($existing->expires_at->toIso8601String(), $result['expires_at']);
    }

    public function test_subscribe_does_call_graph_when_no_matching_active_row_exists(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/subscriptions' => Http::response([
                'id' => 'graph-subscription-id-123',
                'resource' => 'me/contacts',
                'changeType' => 'created,updated,deleted',
                'expirationDateTime' => now()->addHours(70)->toIso8601String(),
            ], 201),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $provider = app(Microsoft365Provider::class);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $provider->subscribe(['connection' => $connection, 'resource_type' => ResourceType::Contact->value]),
        );

        Http::assertSent(fn ($request): bool => (string) $request->url() === 'https://graph.microsoft.com/v1.0/subscriptions');
        $this->assertSame('graph-subscription-id-123', $result['subscription_id']);
    }

    public function test_subscribe_does_call_graph_again_when_the_existing_row_has_already_expired(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/subscriptions' => Http::response([
                'id' => 'fresh-subscription-after-expiry',
                'resource' => 'me/contacts',
                'changeType' => 'created,updated,deleted',
                'expirationDateTime' => now()->addHours(70)->toIso8601String(),
            ], 201),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->create([
                    'provider_resource' => 'me/contacts',
                    'provider_change_type' => 'created,updated,deleted',
                    'status' => ProviderWebhookSubscriptionStatus::Active->value,
                    'expires_at' => now()->subMinute(),
                ]),
        );

        $provider = app(Microsoft365Provider::class);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $provider->subscribe(['connection' => $connection, 'resource_type' => ResourceType::Contact->value]),
        );

        Http::assertSent(fn ($request): bool => (string) $request->url() === 'https://graph.microsoft.com/v1.0/subscriptions');
        $this->assertSame('fresh-subscription-after-expiry', $result['subscription_id']);
    }

    // ------------------------------------------------------------
    // 2. RenewGraphSubscriptionJob — re-verify fresh, silently no-op
    //    on a genuine schedule-time-vs-execution-time race
    // ------------------------------------------------------------

    public function test_renew_graph_subscription_job_silently_no_ops_when_the_connection_was_disconnected_after_it_was_scheduled(): void
    {
        // Empty fake + preventStrayRequests: proves renewSubscription()
        // is never even attempted once the race is detected.
        Http::fake();

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->create(['status' => ProviderWebhookSubscriptionStatus::Active->value, 'expires_at' => now()->addHours(50)]),
        );

        // Construct the exact race directly: the command enumerated this
        // subscription as due and dispatched this job while the
        // connection was still Active, but by the time the job actually
        // EXECUTES, the connection has since been disconnected (e.g. the
        // firm disconnected it in the intervening window).
        $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::query()->where('id', $connection->id)->update(['status' => ConnectionStatus::Disconnected->value]),
        );

        $job = new RenewGraphSubscriptionJob($connection->id, $firm->id, $subscription->id);
        $job->handle(app(ProviderRegistry::class), app(HealthStateService::class));

        Http::assertNothingSent();

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::query()->find($subscription->id),
        );

        $this->assertSame(ProviderWebhookSubscriptionStatus::Active, $fresh->status, 'The subscription row must be left completely untouched, not renewed and not marked failed.');
        $this->assertNull($fresh->last_renewed_at);
    }

    public function test_renew_graph_subscription_job_silently_no_ops_when_the_subscription_was_already_superseded(): void
    {
        Http::fake();

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);

        // The subscription is no longer 'active' by execution time (e.g.
        // a concurrent renewal tick already moved it to renewal_failed,
        // or a fresh subscribe() superseded it) — Gate 1's second half.
        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->renewalFailed()
                ->create(),
        );

        $job = new RenewGraphSubscriptionJob($connection->id, $firm->id, $subscription->id);
        $job->handle(app(ProviderRegistry::class), app(HealthStateService::class));

        Http::assertNothingSent();
    }

    public function test_renew_graph_subscription_job_genuinely_renews_a_still_active_subscription_for_a_still_active_connection(): void
    {
        $newExpiry = now()->addHours(70)->toIso8601String();

        Http::fake([
            'https://graph.microsoft.com/v1.0/subscriptions/*' => Http::response([
                'id' => 'renewed-subscription-id',
                'resource' => 'me/contacts',
                'changeType' => 'created,updated,deleted',
                'expirationDateTime' => $newExpiry,
            ], 200),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->create(['status' => ProviderWebhookSubscriptionStatus::Active->value, 'provider_subscription_id' => 'original-subscription-id']),
        );

        $job = new RenewGraphSubscriptionJob($connection->id, $firm->id, $subscription->id);
        $job->handle(app(ProviderRegistry::class), app(HealthStateService::class));

        Http::assertSent(fn ($request): bool => (string) $request->url() === 'https://graph.microsoft.com/v1.0/subscriptions/original-subscription-id');

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::query()->find($subscription->id),
        );

        $this->assertSame(ProviderWebhookSubscriptionStatus::Active, $fresh->status);
        $this->assertSame('renewed-subscription-id', $fresh->provider_subscription_id);
        $this->assertNotNull($fresh->last_renewed_at);
    }

    // ------------------------------------------------------------
    // 3. RenewProviderWebhookSubscriptionsCommand — dynamic safety-margin
    //    window selection
    // ------------------------------------------------------------

    public function test_the_renew_command_dispatches_only_for_the_subscription_inside_its_own_safety_margin_window(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $connectionOutside = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);
        $connectionInside = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);

        $subscriptionOutside = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connectionOutside)
                ->create(['status' => ProviderWebhookSubscriptionStatus::Active->value]),
        );

        // Lifetime ~71h -> margin = min(24h, 20% * 71h) = 14.2h. Still
        // ~55.8h before the renewal threshold — clearly OUTSIDE the
        // window.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
            ->where('id', $subscriptionOutside->id)
            ->update(['created_at' => now()->subHour(), 'expires_at' => now()->addHours(70)]));

        $subscriptionInside = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connectionInside)
                ->create(['status' => ProviderWebhookSubscriptionStatus::Active->value]),
        );

        // Lifetime ~70.5h -> margin = min(24h, 20% * 70.5h) = 14.1h. The
        // renewal threshold (expires_at - margin) is ~13.6h IN THE PAST
        // — clearly INSIDE the window (overdue for renewal).
        $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
            ->where('id', $subscriptionInside->id)
            ->update(['created_at' => now()->subHours(70), 'expires_at' => now()->addMinutes(30)]));

        $this->artisan('integrations:webhooks:renew-subscriptions')->assertExitCode(0);

        Queue::assertPushed(RenewGraphSubscriptionJob::class, 1);
        Queue::assertPushed(RenewGraphSubscriptionJob::class, fn (RenewGraphSubscriptionJob $job): bool => $job->subscriptionId === $subscriptionInside->id);
        Queue::assertNotPushed(RenewGraphSubscriptionJob::class, fn (RenewGraphSubscriptionJob $job): bool => $job->subscriptionId === $subscriptionOutside->id);
    }

    public function test_the_renew_command_does_not_dispatch_for_a_non_active_subscription_even_if_its_expiry_is_imminent(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->renewalFailed()
                ->create(),
        );

        $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
            ->where('id', $subscription->id)
            ->update(['created_at' => now()->subHours(70), 'expires_at' => now()->addMinutes(5)]));

        $this->artisan('integrations:webhooks:renew-subscriptions')->assertExitCode(0);

        Queue::assertNotPushed(RenewGraphSubscriptionJob::class);
    }

    public function test_the_renew_command_skips_a_non_activated_firm_entirely(): void
    {
        Queue::fake();

        // Deliberately NOT ->activated() — draft by factory default.
        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm, ConnectionStatus::Active);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationProviderWebhookSubscription::factory()
                ->forFirmIntegration($connection)
                ->create(['status' => ProviderWebhookSubscriptionStatus::Active->value]),
        );

        $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
            ->where('id', $subscription->id)
            ->update(['created_at' => now()->subHours(70), 'expires_at' => now()->addMinutes(5)]));

        $this->assertNotSame(FirmActivationStatus::Activated, $firm->fresh()->activation_status);

        $this->artisan('integrations:webhooks:renew-subscriptions')->assertExitCode(0);

        Queue::assertNotPushed(RenewGraphSubscriptionJob::class);
    }
}
