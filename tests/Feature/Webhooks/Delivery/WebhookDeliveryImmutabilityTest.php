<?php

namespace Tests\Feature\Webhooks\Delivery;

use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Correction #13: webhook_delivery_attempts and webhook_events are
 * append-only (update/delete throw). webhook_deliveries may update ONLY
 * status/attempt_count/next_attempt_at/last_attempted_at.
 */
class WebhookDeliveryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_an_existing_delivery_attempt_throws(): void
    {
        $attempt = WebhookDeliveryAttempt::factory()->create();

        $this->expectException(\LogicException::class);
        $attempt->update(['outcome' => 'failure']);
    }

    public function test_deleting_an_existing_delivery_attempt_throws(): void
    {
        $attempt = WebhookDeliveryAttempt::factory()->create();

        $this->expectException(\LogicException::class);
        $attempt->delete();
    }

    public function test_updating_an_existing_webhook_event_throws(): void
    {
        $event = WebhookEvent::factory()->create();

        $this->expectException(\LogicException::class);
        $event->update(['payload_json' => ['tampered' => true]]);
    }

    public function test_deleting_an_existing_webhook_event_throws(): void
    {
        $event = WebhookEvent::factory()->create();

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_webhook_events_table_has_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('webhook_events', 'updated_at'));
    }

    public function test_webhook_delivery_can_update_only_the_four_mutable_status_fields(): void
    {
        $delivery = \App\Models\WebhookDelivery::factory()->create();

        // Allowed: status/attempt_count/next_attempt_at/last_attempted_at.
        $delivery->update(['attempt_count' => 1]);
        $this->assertSame(1, $delivery->fresh()->attempt_count);

        // Disallowed: any other field.
        $this->expectException(\LogicException::class);
        $delivery->update(['webhook_subscription_id' => 999999]);
    }

    public function test_webhook_delivery_replay_lineage_fields_cannot_be_changed_after_creation(): void
    {
        $delivery = \App\Models\WebhookDelivery::factory()->create();

        $this->expectException(\LogicException::class);
        $delivery->update(['replayed_from_delivery_id' => 1]);
    }
}
