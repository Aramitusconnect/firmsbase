<?php

namespace App\Services;

use App\Enums\WebhookSubscriptionStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookSubscription;

/**
 * WebhookSubscriptionService — the only writer of webhook_subscriptions.
 * Every event_types array is validated through WebhookEventTypeRegistry
 * (correction #15); every destination_url is validated through
 * WebhookDestinationValidationService on EVERY create/update, not just
 * at creation (correction #5). Gated by both
 * WebhookEntitlementPolicyService (the 'webhook' module) and
 * WebhookAccessPolicyService (FirmOwner/Attorney only — correction #10).
 */
class WebhookSubscriptionService
{
    private const DEFAULT_RETRY_POLICY = [
        'max_attempts' => 5,
        'base_delay_seconds' => 30,
        'multiplier' => 2,
    ];

    public function __construct(
        private readonly WebhookEntitlementPolicyService $entitlement,
        private readonly WebhookAccessPolicyService $accessPolicy,
        private readonly WebhookDestinationValidationService $destinationValidation,
        private readonly TenantSafeWebhookPolicyService $tenantSafePolicy,
    ) {
    }

    /**
     * @param list<string> $eventTypes
     */
    public function create(
        Firm $firm,
        FirmUser $createdBy,
        array $eventTypes,
        string $destinationUrl,
        FirmUser $actor,
        ?array $retryPolicy = null,
        bool $allowInsecureHttpForTesting = false,
    ): WebhookSubscription {
        $this->entitlement->assertEnabled($firm);
        $this->accessPolicy->assertCanManage($actor);

        WebhookEventTypeRegistry::assertAllSupported($eventTypes);
        $this->destinationValidation->assertSafe($destinationUrl, $allowInsecureHttpForTesting);

        return WebhookSubscription::create([
            'firm_id' => $firm->id,
            'event_types' => array_values($eventTypes),
            'destination_url' => $destinationUrl,
            'status' => WebhookSubscriptionStatus::Active,
            'retry_policy_json' => $retryPolicy ?? self::DEFAULT_RETRY_POLICY,
            'created_by_firm_user_id' => $createdBy->id,
        ]);
    }

    public function disable(Firm $firm, WebhookSubscription $subscription, FirmUser $actor): WebhookSubscription
    {
        $this->entitlement->assertEnabled($firm);
        $this->tenantSafePolicy->assertWebhookSubscriptionBelongsToFirm($subscription, $firm);
        $this->accessPolicy->assertCanManage($actor);

        $subscription->update(['status' => WebhookSubscriptionStatus::Disabled]);

        return $subscription->fresh();
    }

    public function enable(Firm $firm, WebhookSubscription $subscription, FirmUser $actor): WebhookSubscription
    {
        $this->entitlement->assertEnabled($firm);
        $this->tenantSafePolicy->assertWebhookSubscriptionBelongsToFirm($subscription, $firm);
        $this->accessPolicy->assertCanManage($actor);

        $subscription->update(['status' => WebhookSubscriptionStatus::Active]);

        return $subscription->fresh();
    }

    /**
     * @param list<string> $eventTypes
     */
    public function updateEventTypes(Firm $firm, WebhookSubscription $subscription, array $eventTypes, FirmUser $actor): WebhookSubscription
    {
        $this->entitlement->assertEnabled($firm);
        $this->tenantSafePolicy->assertWebhookSubscriptionBelongsToFirm($subscription, $firm);
        $this->accessPolicy->assertCanManage($actor);

        WebhookEventTypeRegistry::assertAllSupported($eventTypes);

        $subscription->update(['event_types' => array_values($eventTypes)]);

        return $subscription->fresh();
    }

    public function updateDestinationUrl(
        Firm $firm,
        WebhookSubscription $subscription,
        string $destinationUrl,
        FirmUser $actor,
        bool $allowInsecureHttpForTesting = false,
    ): WebhookSubscription {
        $this->entitlement->assertEnabled($firm);
        $this->tenantSafePolicy->assertWebhookSubscriptionBelongsToFirm($subscription, $firm);
        $this->accessPolicy->assertCanManage($actor);

        $this->destinationValidation->assertSafe($destinationUrl, $allowInsecureHttpForTesting);

        $subscription->update(['destination_url' => $destinationUrl]);

        return $subscription->fresh();
    }
}
