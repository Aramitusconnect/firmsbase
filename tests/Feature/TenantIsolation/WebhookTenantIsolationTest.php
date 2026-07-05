<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSecret;
use App\Models\WebhookSubscription;
use App\Services\TenantSafeWebhookPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * Defense-in-depth cross-firm isolation for all 5 Phase 14 tables, via
 * TenantSafeWebhookPolicyService, independent of BelongsToTenant's
 * global scope (webhook_deliveries/webhook_secrets don't use that
 * trait at all — see model docblocks).
 */
class WebhookTenantIsolationTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    private TenantSafeWebhookPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TenantSafeWebhookPolicyService::class);
    }

    protected function tearDown(): void
    {
        \App\Services\TenantContextResolver::clear();

        parent::tearDown();
    }

    public function test_webhook_subscription_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $subscriptionA = WebhookSubscription::factory()->forFirm($firmA)->create();

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookSubscriptionBelongsToFirm($subscriptionA, $firmB);
    }

    public function test_webhook_event_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $eventA = WebhookEvent::factory()->forFirm($firmA)->create();

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookEventBelongsToFirm($eventA, $firmB);
    }

    public function test_webhook_delivery_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $deliveryA = WebhookDelivery::factory()->create(['firm_id' => $firmA->id]);

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookDeliveryBelongsToFirm($deliveryA, $firmB);
    }

    public function test_webhook_secret_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $secretA = WebhookSecret::factory()->create(['firm_id' => $firmA->id]);

        $this->expectException(TenantIsolationException::class);
        $this->service->assertWebhookSecretBelongsToFirm($secretA, $firmB);
    }

    public function test_belongs_to_tenant_global_scope_hides_another_firms_subscriptions(): void
    {
        $firmA = $this->makeWebhookEntitledFirm();
        $firmB = $this->makeWebhookEntitledFirm();
        $subscriptionA = WebhookSubscription::factory()->forFirm($firmA)->create();
        $subscriptionB = WebhookSubscription::factory()->forFirm($firmB)->create();

        app(\App\Services\TenantContextResolver::class)->activateForFirm($firmA);

        $visibleIds = WebhookSubscription::query()->pluck('id')->all();

        $this->assertContains($subscriptionA->id, $visibleIds);
        $this->assertNotContains($subscriptionB->id, $visibleIds);
    }
}
