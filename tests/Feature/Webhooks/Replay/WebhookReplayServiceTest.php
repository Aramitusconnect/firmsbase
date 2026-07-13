<?php

namespace Tests\Feature\Webhooks\Replay;

use App\Enums\WebhookDeliveryStatus;
use App\Services\WebhookReplayService;
use App\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * Correction #9: replay creates a NEW delivery row, never mutates the
 * original delivery or its attempts, max 3 replays per original,
 * FirmOwner/Attorney only, audited via TimelineEventRecorder +
 * security_events, requires the webhook entitlement still enabled.
 */
class WebhookReplayServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    private WebhookReplayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WebhookReplayService::class);
    }

    private function makeExhaustedDelivery(): array
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        $event = \App\Models\WebhookEvent::factory()->forFirm($firm)->create();
        $delivery = \App\Models\WebhookDelivery::factory()->exhausted()->create([
            'firm_id' => $firm->id,
            'webhook_subscription_id' => $subscription->id,
            'webhook_event_id' => $event->id,
        ]);

        return [$firm, $owner, $delivery];
    }

    public function test_replay_creates_a_new_delivery_and_never_mutates_the_original(): void
    {
        [$firm, $owner, $original] = $this->makeExhaustedDelivery();
        $originalAttributesBefore = $original->fresh()->getAttributes();

        $replay = $this->service->replay($firm, $original, $owner);

        $this->assertNotSame($original->id, $replay->id);
        $this->assertSame(WebhookDeliveryStatus::Pending, $replay->status);
        $this->assertSame($original->id, $replay->replayed_from_delivery_id);
        $this->assertSame($owner->id, $replay->replayed_by_firm_user_id);
        $this->assertNotNull($replay->replayed_at);
        $this->assertSame($original->webhook_event_id, $replay->webhook_event_id);
        $this->assertSame($originalAttributesBefore, $original->fresh()->getAttributes());
    }

    public function test_replay_is_audited_through_timeline_and_security_events(): void
    {
        [$firm, $owner, $original] = $this->makeExhaustedDelivery();

        $this->service->replay($firm, $original, $owner);

        // timeline_events has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3L, Checkpoint 33) — replay()'s own narrow
        // context wrap around the timeline->record() call correctly
        // clears before returning, so this read-time assertion needs
        // its own context wrap to see the row it just wrote.
        // security_events is NOT yet FORCE-protected (this arc's own
        // eighth and final table, not yet started), so that assertion
        // stays bare/unwrapped.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseHas('timeline_events', ['firm_id' => $firm->id, 'event_type' => 'webhook_delivery_replayed']);
        });
        $this->assertDatabaseHas('security_events', ['firm_id' => $firm->id, 'event_type' => 'webhook_delivery_replayed', 'category' => 'webhook_replay']);
    }

    public function test_replay_is_capped_at_3_per_original_delivery(): void
    {
        [$firm, $owner, $original] = $this->makeExhaustedDelivery();

        $this->service->replay($firm, $original, $owner);
        $this->service->replay($firm, $original, $owner);
        $this->service->replay($firm, $original, $owner);

        $this->expectException(\RuntimeException::class);
        $this->service->replay($firm, $original, $owner);
    }

    public function test_only_firm_owner_or_attorney_may_replay(): void
    {
        [$firm, , $original] = $this->makeExhaustedDelivery();
        $billingStaff = $this->makeBillingStaff($firm);

        $this->expectException(\RuntimeException::class);
        $this->service->replay($firm, $original, $billingStaff);
    }

    public function test_replay_requires_the_webhook_entitlement_still_enabled(): void
    {
        [$firm, $owner, $original] = $this->makeExhaustedDelivery();

        app(\App\Services\EntitlementService::class)->setForSource(
            $firm,
            'webhook',
            \App\Enums\EntitlementSource::AdminOverride,
            false,
        );

        $this->expectException(\RuntimeException::class);
        $this->service->replay($firm, $original, $owner);
    }
}
