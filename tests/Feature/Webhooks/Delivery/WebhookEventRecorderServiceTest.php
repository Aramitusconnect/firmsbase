<?php

namespace Tests\Feature\Webhooks\Delivery;

use App\Enums\WebhookEventType;
use App\Models\Matter;
use App\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * Correction #11: one webhook_events row per business event, fanned out
 * to N webhook_deliveries — one per matching Active subscription. Also
 * covers failure isolation (correction #16): record() never throws.
 */
class WebhookEventRecorderServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    public function test_recording_an_event_fans_out_to_every_matching_active_subscription(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscriptionService = app(WebhookSubscriptionService::class);

        $matchingA = $subscriptionService->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hook-a', $owner);
        $matchingB = $subscriptionService->create($firm, $owner, [WebhookEventType::MatterCreated->value, WebhookEventType::TaskCompleted->value], 'https://example.com/hook-b', $owner);
        $nonMatching = $subscriptionService->create($firm, $owner, [WebhookEventType::TaskCompleted->value], 'https://example.com/hook-c', $owner);
        $disabled = $subscriptionService->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hook-d', $owner);
        $subscriptionService->disable($firm, $disabled, $owner);

        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $event = app(\App\Services\WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $matter);

        $this->assertNotNull($event);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_subscription_id' => $matchingA->id, 'webhook_event_id' => $event->id]);
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_subscription_id' => $matchingB->id, 'webhook_event_id' => $event->id]);
        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_subscription_id' => $nonMatching->id]);
        $this->assertDatabaseMissing('webhook_deliveries', ['webhook_subscription_id' => $disabled->id]);
    }

    public function test_record_returns_null_and_never_throws_when_the_entitlement_is_disabled(): void
    {
        $firm = \App\Models\Firm::factory()->create();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $event = app(\App\Services\WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $matter);

        $this->assertNull($event);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_record_never_throws_even_when_the_payload_builder_fails_internally(): void
    {
        $firm = $this->makeWebhookEntitledFirm();

        // Passing a subject type with no builder mapping (a plain
        // stdClass) forces WebhookPayloadBuilderService to throw
        // internally — record() must catch this and return null rather
        // than letting the exception escape into the calling business
        // workflow (correction #16).
        $subject = new \stdClass();

        $event = app(\App\Services\WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $subject);

        $this->assertNull($event);
    }
}
