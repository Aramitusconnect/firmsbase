<?php

namespace Tests\Feature\Webhooks\Delivery;

use App\Enums\WebhookDeliveryAttemptOutcome;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\WebhookDispatchJob;
use App\Services\FakeWebhookTransport;
use App\Services\WebhookDeliveryAttemptService;
use App\Services\WebhookSecretService;
use App\Services\WebhookSignatureService;
use App\Services\WebhookSubscriptionService;
use App\ValueObjects\WebhookTransportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Webhooks\Concerns\SetsUpWebhookEntitledFirm;
use Tests\TestCase;

/**
 * Correction #3/#16: WebhookDispatchJob uses FakeWebhookTransport only,
 * never performs real HTTP, never throws outward, and every outcome
 * becomes exactly one webhook_delivery_attempts row.
 */
class WebhookDispatchJobTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    private function makeDelivery(): array
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        app(WebhookSecretService::class)->generate($firm, $subscription);

        $event = \App\Models\WebhookEvent::factory()->forFirm($firm)->create();
        $delivery = app(\App\Services\WebhookDeliveryService::class)->enqueue($event, $subscription);

        return [$firm, $subscription, $delivery];
    }

    public function test_successful_dispatch_records_a_success_attempt_and_marks_delivered(): void
    {
        [, , $delivery] = $this->makeDelivery();

        $transport = new FakeWebhookTransport(WebhookTransportResult::success(200, 'ok'));
        $job = new WebhookDispatchJob($delivery->id);
        $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $this->assertDatabaseHas('webhook_delivery_attempts', [
            'webhook_delivery_id' => $delivery->id,
            'outcome' => WebhookDeliveryAttemptOutcome::Success->value,
        ]);
        $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertCount(1, $transport->sentRecords());
    }

    public function test_headers_include_all_four_required_signature_headers(): void
    {
        [, , $delivery] = $this->makeDelivery();

        $transport = new FakeWebhookTransport();
        $job = new WebhookDispatchJob($delivery->id);
        $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $headers = $transport->sentRecords()[0]['headers'];

        $this->assertArrayHasKey('X-FirmsBase-Event-Id', $headers);
        $this->assertArrayHasKey('X-FirmsBase-Delivery-Id', $headers);
        $this->assertArrayHasKey('X-FirmsBase-Timestamp', $headers);
        $this->assertArrayHasKey('X-FirmsBase-Signature', $headers);
        $this->assertStringStartsWith('sha256=', $headers['X-FirmsBase-Signature']);
    }

    public function test_failed_dispatch_schedules_a_retry_when_under_max_attempts(): void
    {
        [, , $delivery] = $this->makeDelivery();

        $transport = new FakeWebhookTransport(WebhookTransportResult::failure(500, 'server error'));
        $job = new WebhookDispatchJob($delivery->id);
        $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDeliveryStatus::Pending, $fresh->status);
        $this->assertSame(1, $fresh->attempt_count);
        $this->assertNotNull($fresh->next_attempt_at);
    }

    public function test_delivery_is_exhausted_after_max_attempts(): void
    {
        [, $subscription, $delivery] = $this->makeDelivery();
        $subscription->update(['retry_policy_json' => ['max_attempts' => 2, 'base_delay_seconds' => 1, 'multiplier' => 2]]);

        $transport = new FakeWebhookTransport(WebhookTransportResult::failure(500, 'server error'));

        for ($i = 0; $i < 2; $i++) {
            $job = new WebhookDispatchJob($delivery->id);
            $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));
        }

        $this->assertSame(WebhookDeliveryStatus::Exhausted, $delivery->fresh()->status);
        $this->assertDatabaseCount('webhook_delivery_attempts', 2);
    }

    public function test_dispatch_job_never_throws_outward_even_when_the_delivery_is_missing(): void
    {
        $job = new WebhookDispatchJob(999999999);

        $job->handle(new FakeWebhookTransport(), app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $this->assertTrue(true);
    }

    public function test_dispatch_job_records_a_failure_attempt_when_no_active_secret_exists(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        // No secret generated for this subscription.
        $event = \App\Models\WebhookEvent::factory()->forFirm($firm)->create();
        $delivery = app(\App\Services\WebhookDeliveryService::class)->enqueue($event, $subscription);

        $job = new WebhookDispatchJob($delivery->id);
        $job->handle(new FakeWebhookTransport(), app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $this->assertDatabaseHas('webhook_delivery_attempts', [
            'webhook_delivery_id' => $delivery->id,
            'outcome' => WebhookDeliveryAttemptOutcome::Failure->value,
        ]);
    }
}
