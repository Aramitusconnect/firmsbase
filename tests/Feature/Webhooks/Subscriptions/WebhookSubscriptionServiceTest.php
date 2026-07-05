<?php

namespace Tests\Feature\Webhooks\Subscriptions;

use App\Enums\WebhookEventType;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\Firm;
use App\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

class WebhookSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    private WebhookSubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookSubscriptionService::class);
    }

    public function test_subscription_can_be_created(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);

        $subscription = $this->service->create(
            $firm,
            $owner,
            [WebhookEventType::MatterCreated->value, WebhookEventType::DocumentUploaded->value],
            'https://example.com/hooks',
            $owner,
        );

        $this->assertSame(WebhookSubscriptionStatus::Active, $subscription->status);
        $this->assertDatabaseHas('webhook_subscriptions', ['id' => $subscription->id, 'firm_id' => $firm->id]);
    }

    public function test_subscription_can_be_disabled_and_re_enabled(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = $this->service->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks', $owner);

        $this->service->disable($firm, $subscription, $owner);
        $this->assertSame(WebhookSubscriptionStatus::Disabled, $subscription->fresh()->status);

        $this->service->enable($firm, $subscription->fresh(), $owner);
        $this->assertSame(WebhookSubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_creation_requires_the_webhook_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $owner = $this->makeFirmOwner($firm);

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks', $owner);
    }

    public function test_only_firm_owner_or_attorney_can_manage_subscriptions(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $billingStaff = $this->makeBillingStaff($firm);

        // FirmOwner and Attorney succeed.
        $this->service->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks-owner', $owner);
        $attorney = $this->makeAttorney($firm);
        $this->service->create($firm, $attorney, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks-attorney', $attorney);

        // BillingStaff is blocked (correction #10).
        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks-billing', $billingStaff);
    }

    public function test_billing_staff_is_explicitly_blocked_from_disabling_a_subscription(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $billingStaff = $this->makeBillingStaff($firm);
        $subscription = $this->service->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks', $owner);

        $this->expectException(\RuntimeException::class);
        $this->service->disable($firm, $subscription, $billingStaff);
    }

    public function test_unsupported_event_type_is_rejected_on_creation(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($firm, $owner, ['not.a.real.event'], 'https://example.com/hooks', $owner);
    }

    public function test_unsupported_event_type_is_rejected_on_update(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = $this->service->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hooks', $owner);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateEventTypes($firm, $subscription, ['not.a.real.event'], $owner);
    }
}
