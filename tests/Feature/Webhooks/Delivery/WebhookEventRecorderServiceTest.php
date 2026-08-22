<?php

namespace Tests\Feature\Webhooks\Delivery;

use App\Enums\WebhookEventType;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\TenantContextService;
use App\Services\WebhookEventRecorderService;
use App\Services\WebhookSubscriptionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * Correction #11: one webhook_events row per business event, fanned out
 * to N webhook_deliveries — one per matching Active subscription. Also
 * covers failure isolation (correction #16): record() never throws.
 *
 * Uses DatabaseMigrations rather than RefreshDatabase (Wave 11 change):
 * RefreshDatabase wraps the whole test in one never-truly-committed
 * outer transaction, so Laravel's transaction manager never reaches
 * level 0 and DB::afterCommit() callbacks registered inside a
 * RefreshDatabase test never actually fire — the exact reason every
 * other DB::afterCommit()-exercising test in this domain
 * (ClientCreatedWiringTest, DocumentUploadedWiringTest,
 * LeadCreatedWiringTest) already uses DatabaseMigrations instead. This
 * class needs that same real-commit behavior for
 * test_record_runs_correctly_from_inside_a_real_after_commit_closure()
 * below (the regression test for Wave 11 Finding 1's decoy-wrap fix),
 * so the whole class was switched rather than only one method.
 */
class WebhookEventRecorderServiceTest extends TestCase
{
    use DatabaseMigrations, SetsUpWebhookEntitledFirm;

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

        $event = app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $matter);

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
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->create(['firm_id' => $firm->id]);

        $event = app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $matter);

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
        $subject = new \stdClass;

        $event = app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $subject);

        $this->assertNull($event);
    }

    /**
     * Regression test for Wave 11 Finding 1 (the "decoy wrap" bug):
     * every real production caller invokes record() from inside a
     * DB::afterCommit() closure, registered from within its own
     * DB::transaction()/runWithFirmContext() unit — by the time
     * record() actually runs, the calling workflow's own ambient
     * context has already been restored/cleared. Before the fix,
     * record()'s wrap covered only the payload-builder call, leaving
     * WebhookEvent::create() and the subscription fan-out unwrapped —
     * which would have failed WITH CHECK the instant webhook_events/
     * webhook_subscriptions/webhook_deliveries went FORCE RLS (this
     * migration batch). This test reproduces the exact real call
     * shape (a genuine, actually-firing DB::afterCommit() closure) and
     * asserts the resulting WebhookEvent/WebhookDelivery rows really
     * exist afterward.
     */
    public function test_record_runs_correctly_from_inside_a_real_after_commit_closure(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscriptionService = app(WebhookSubscriptionService::class);
        $subscription = $subscriptionService->create($firm, $owner, [WebhookEventType::MatterCreated->value], 'https://example.com/hook-a', $owner);

        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->create(['firm_id' => $firm->id]));

        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $matter) {
            DB::afterCommit(function () use ($firm, $matter) {
                app(WebhookEventRecorderService::class)->record($firm, WebhookEventType::MatterCreated, $matter);
            });
        });

        $event = $this->runWithFirmContext($firm, fn () => WebhookEvent::query()->where('firm_id', $firm->id)->first());

        $this->assertNotNull($event, 'WebhookEvent must actually be persisted when record() is invoked from inside a real DB::afterCommit() closure.');
        $this->assertSame(WebhookEventType::MatterCreated, $event->event_type);

        $deliveryExists = $this->runWithFirmContext($firm, fn () => WebhookDelivery::query()
            ->where('webhook_subscription_id', $subscription->id)
            ->where('webhook_event_id', $event->id)
            ->exists());

        $this->assertTrue($deliveryExists, 'The matching subscription must receive exactly one fanned-out webhook_deliveries row.');
    }
}
