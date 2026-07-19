<?php

namespace Tests\Feature\Webhooks\Delivery;

use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Correction #13: webhook_delivery_attempts and webhook_events are
 * append-only (update/delete throw). webhook_deliveries may update ONLY
 * status/attempt_count/next_attempt_at/last_attempted_at.
 *
 * All 5 Wave 11 tables now have permanent FORCE ROW LEVEL SECURITY
 * active, so every factory create/update/read below must run under an
 * explicit, single, consistent firm's tenant context — bare nested
 * factory defaults (independent Firm::factory() calls at every level)
 * would each need their own, different context to insert, which is
 * impossible in one wrapped call. Every helper below threads one
 * single Firm through the whole parent chain explicitly instead.
 */
class WebhookDeliveryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_existing_delivery_attempt_throws(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->createAttemptForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->runWithFirmContext($firm, fn () => $attempt->update(['outcome' => 'failure']));
    }

    public function test_deleting_an_existing_delivery_attempt_throws(): void
    {
        $firm = Firm::factory()->create();
        $attempt = $this->createAttemptForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->runWithFirmContext($firm, fn () => $attempt->delete());
    }

    public function test_updating_an_existing_webhook_event_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => WebhookEvent::factory()->forFirm($firm)->create());

        $this->expectException(\LogicException::class);
        $this->runWithFirmContext($firm, fn () => $event->update(['payload_json' => ['tampered' => true]]));
    }

    public function test_deleting_an_existing_webhook_event_throws(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => WebhookEvent::factory()->forFirm($firm)->create());

        $this->expectException(\LogicException::class);
        $this->runWithFirmContext($firm, fn () => $event->delete());
    }

    public function test_webhook_events_table_has_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('webhook_events', 'updated_at'));
    }

    public function test_webhook_delivery_can_update_only_the_four_mutable_status_fields(): void
    {
        $firm = Firm::factory()->create();
        $delivery = $this->createDeliveryForFirm($firm);

        // Allowed: status/attempt_count/next_attempt_at/last_attempted_at.
        $this->runWithFirmContext($firm, fn () => $delivery->update(['attempt_count' => 1]));
        $fresh = $this->runWithFirmContext($firm, fn () => $delivery->fresh());
        $this->assertSame(1, $fresh->attempt_count);

        // Disallowed: any other field.
        $this->expectException(\LogicException::class);
        $this->runWithFirmContext($firm, fn () => $delivery->update(['webhook_subscription_id' => 999999]));
    }

    public function test_webhook_delivery_replay_lineage_fields_cannot_be_changed_after_creation(): void
    {
        $firm = Firm::factory()->create();
        $delivery = $this->createDeliveryForFirm($firm);

        $this->expectException(\LogicException::class);
        $this->runWithFirmContext($firm, fn () => $delivery->update(['replayed_from_delivery_id' => 1]));
    }

    private function createDeliveryForFirm(Firm $firm): WebhookDelivery
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
            $subscription = WebhookSubscription::factory()->forFirm($firm)->create(['created_by_firm_user_id' => $owner->id]);
            $event = WebhookEvent::factory()->forFirm($firm)->create();

            return WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();
        });
    }

    private function createAttemptForFirm(Firm $firm): WebhookDeliveryAttempt
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->create(['firm_id' => $firm->id]);
            $subscription = WebhookSubscription::factory()->forFirm($firm)->create(['created_by_firm_user_id' => $owner->id]);
            $event = WebhookEvent::factory()->forFirm($firm)->create();
            $delivery = WebhookDelivery::factory()->forSubscriptionAndEvent($subscription, $event)->create();

            return WebhookDeliveryAttempt::factory()->forDelivery($delivery)->create();
        });
    }
}
