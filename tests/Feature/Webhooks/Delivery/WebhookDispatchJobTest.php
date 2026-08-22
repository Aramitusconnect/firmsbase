<?php

namespace Tests\Feature\Webhooks\Delivery;

use App\Enums\WebhookDeliveryAttemptOutcome;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\WebhookDispatchJob;
use App\Models\Firm;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Models\WebhookSubscription;
use App\Services\FakeWebhookTransport;
use App\Services\TenantContextResolver;
use App\Services\TenantContextService;
use App\Services\WebhookDeliveryAttemptService;
use App\Services\WebhookDeliveryService;
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
 *
 * Wave 11 change: WebhookDispatchJob's constructor now requires an
 * explicit $firmId (see app/Jobs/WebhookDispatchJob.php) — the firm
 * identity can no longer be safely derived from a pre-context read
 * against webhook_deliveries itself, now that table is FORCE RLS'd.
 * Every call site below passes $firm->id explicitly, and every
 * read that happens AFTER handle() returns (which clears its own
 * context in a finally block) must re-establish tenant context, since
 * webhook_deliveries/webhook_delivery_attempts have no BelongsToTenant
 * global scope and are both permanently FORCE RLS'd.
 */
class WebhookDispatchJobTest extends TestCase
{
    use RefreshDatabase, SetsUpWebhookEntitledFirm;

    /**
     * @return array{0: Firm, 1: WebhookSubscription, 2: WebhookDelivery}
     */
    private function makeDelivery(): array
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        app(WebhookSecretService::class)->generate($firm, $subscription);

        $event = $this->runWithFirmContext($firm, fn () => WebhookEvent::factory()->forFirm($firm)->create());
        $delivery = app(WebhookDeliveryService::class)->enqueue($event, $subscription);

        return [$firm, $subscription, $delivery];
    }

    public function test_successful_dispatch_records_a_success_attempt_and_marks_delivered(): void
    {
        [$firm, , $delivery] = $this->makeDelivery();

        $transport = new FakeWebhookTransport(WebhookTransportResult::success(200, 'ok'));
        $job = new WebhookDispatchJob($delivery->id, $firm->id);
        $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $this->runWithFirmContext($firm, function () use ($delivery) {
            $this->assertDatabaseHas('webhook_delivery_attempts', [
                'webhook_delivery_id' => $delivery->id,
                'outcome' => WebhookDeliveryAttemptOutcome::Success->value,
            ]);
            $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->fresh()->status);
        });
        $this->assertCount(1, $transport->sentRecords());
    }

    public function test_headers_include_all_four_required_signature_headers(): void
    {
        [$firm, , $delivery] = $this->makeDelivery();

        $transport = new FakeWebhookTransport;
        $job = new WebhookDispatchJob($delivery->id, $firm->id);
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
        [$firm, , $delivery] = $this->makeDelivery();

        $transport = new FakeWebhookTransport(WebhookTransportResult::failure(500, 'server error'));
        $job = new WebhookDispatchJob($delivery->id, $firm->id);
        $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $fresh = $this->runWithFirmContext($firm, fn () => $delivery->fresh());
        $this->assertSame(WebhookDeliveryStatus::Pending, $fresh->status);
        $this->assertSame(1, $fresh->attempt_count);
        $this->assertNotNull($fresh->next_attempt_at);
    }

    public function test_delivery_is_exhausted_after_max_attempts(): void
    {
        [$firm, $subscription, $delivery] = $this->makeDelivery();
        $this->runWithFirmContext($firm, fn () => $subscription->update(['retry_policy_json' => ['max_attempts' => 2, 'base_delay_seconds' => 1, 'multiplier' => 2]]));

        $transport = new FakeWebhookTransport(WebhookTransportResult::failure(500, 'server error'));

        for ($i = 0; $i < 2; $i++) {
            $job = new WebhookDispatchJob($delivery->id, $firm->id);
            $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));
        }

        $this->runWithFirmContext($firm, function () use ($delivery) {
            $this->assertSame(WebhookDeliveryStatus::Exhausted, $delivery->fresh()->status);
            $this->assertDatabaseCount('webhook_delivery_attempts', 2);
        });
    }

    public function test_dispatch_job_never_throws_outward_even_when_the_delivery_is_missing(): void
    {
        $firm = $this->makeWebhookEntitledFirm();

        $job = new WebhookDispatchJob(999999999, $firm->id);

        $job->handle(new FakeWebhookTransport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $this->assertTrue(true);
    }

    public function test_dispatch_job_records_a_failure_attempt_when_no_active_secret_exists(): void
    {
        $firm = $this->makeWebhookEntitledFirm();
        $owner = $this->makeFirmOwner($firm);
        $subscription = app(WebhookSubscriptionService::class)->create($firm, $owner, ['matter.created'], 'https://example.com/hooks', $owner);
        // No secret generated for this subscription.
        $event = $this->runWithFirmContext($firm, fn () => WebhookEvent::factory()->forFirm($firm)->create());
        $delivery = app(WebhookDeliveryService::class)->enqueue($event, $subscription);

        $job = new WebhookDispatchJob($delivery->id, $firm->id);
        $job->handle(new FakeWebhookTransport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        $this->runWithFirmContext($firm, function () use ($delivery) {
            $this->assertDatabaseHas('webhook_delivery_attempts', [
                'webhook_delivery_id' => $delivery->id,
                'outcome' => WebhookDeliveryAttemptOutcome::Failure->value,
            ]);
        });
    }

    /**
     * Regression test for Wave 11 Finding 2 (WebhookDispatchJob
     * establishing no tenant context at all). Constructs the job
     * exactly the way a real queue worker would — with NO ambient
     * PHP-memory or PostgreSQL tenant context active anywhere — and
     * asserts handle() still finds the delivery (via its own explicit
     * $firmId, never derived from an RLS-gated read), records exactly
     * one webhook_delivery_attempts row, and does not silently no-op.
     */
    public function test_dispatch_job_finds_the_delivery_and_records_an_attempt_with_zero_ambient_context(): void
    {
        [$firm, , $delivery] = $this->makeDelivery();

        // Explicitly prove no ambient context of any kind is active
        // before constructing/handling the job — matching a fresh
        // queue-worker process picking up this job with nothing else
        // having run beforehand.
        TenantContextResolver::clear();
        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $transport = new FakeWebhookTransport(WebhookTransportResult::success(200, 'ok'));
        $job = new WebhookDispatchJob($delivery->id, $firm->id);
        $job->handle($transport, app(WebhookSignatureService::class), app(WebhookSecretService::class), app(WebhookDeliveryAttemptService::class));

        // The job must not have silently no-op'd: exactly one
        // transport send and exactly one recorded attempt row, plus
        // the delivery must have actually transitioned to Delivered —
        // none of which is possible if handle()'s opening
        // WebhookDelivery::query()->find() returned null.
        $this->assertCount(1, $transport->sentRecords());

        $this->runWithFirmContext($firm, function () use ($delivery) {
            $this->assertDatabaseCount('webhook_delivery_attempts', 1);
            $this->assertDatabaseHas('webhook_delivery_attempts', [
                'webhook_delivery_id' => $delivery->id,
                'outcome' => WebhookDeliveryAttemptOutcome::Success->value,
            ]);
            $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->fresh()->status);
        });

        // handle() must clear its own context afterward, exactly like
        // every other runInFirmContext()/runWithFirmContext() caller.
        $this->assertNoDatabaseTenantContext();
    }
}
