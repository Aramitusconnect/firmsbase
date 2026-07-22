<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Data\SanitizedPayloadReference;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Models\Contact;
use App\Models\Firm;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IntegrationOutboxTransactionDurabilityTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §7/§11;
 * agent-6h-test-plan-and-review.md §6 item 6/7; diff-review.md
 * Residual 2). The core transactional-outbox guarantee, plus thorough
 * coverage of IntegrationOutboxEventService::fail()'s reuse of
 * WebhookRetryPolicyService's backoff calculator — flagged in
 * diff-review.md as "the least-reviewed design decision in the whole
 * batch."
 *
 * Note on "fresh connection": this codebase's own test convention
 * (confirmed by every precedent RLS/durability test in this repo) runs
 * each test inside RefreshDatabase's single wrapping transaction, so a
 * literal second physical DB connection would see nothing at all
 * (nothing here — or in any prior checkpoint's tests — is ever
 * committed to disk outside the test transaction). "Fresh connection"
 * is therefore proven the way this whole codebase's test suite proves
 * it: via a fresh DB::table()/query-builder read that never reuses the
 * in-PHP model instance the write produced, rather than a genuinely
 * separate physical connection.
 */
class IntegrationOutboxTransactionDurabilityTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationOutboxEventService(new WebhookRetryPolicyService());
    }

    // ------------------------------------------------------------
    // The core guarantee
    // ------------------------------------------------------------

    public function test_a_domain_write_and_its_outbox_row_committed_together_are_both_durable_and_claimable(): void
    {
        $firm = Firm::factory()->create();
        $contact = Contact::factory()->forFirm($firm)->create(['name' => 'Original Name']);
        $domainEventId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $contact, $domainEventId) {
            DB::transaction(function () use ($firm, $contact, $domainEventId) {
                $contact->update(['name' => 'Updated Name']);

                $payload = new SanitizedPayloadReference(ResourceType::Contact, (string) $contact->uuid, ['name' => 'Updated Name']);
                $this->service->recordOnce($firm->id, null, $domainEventId, 'contact.updated', $payload);
            });
        });

        // No claim()/dispatch code is ever invoked here — the row must
        // already be durable and claimable purely from the transaction
        // that wrote it.
        $freshContactName = $this->runWithFirmContext($firm, fn () => DB::table('contacts')->where('id', $contact->id)->value('name'));
        $this->assertSame('Updated Name', $freshContactName);

        $freshOutboxRow = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_outbox_events')->where('domain_event_id', $domainEventId)->first(),
        );
        $this->assertNotNull($freshOutboxRow);
        $this->assertSame('pending', $freshOutboxRow->status);
        $this->assertTrue(Carbon::parse($freshOutboxRow->next_attempt_at)->lessThanOrEqualTo(now()));

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(1, $claimed);
        $this->assertSame($freshOutboxRow->id, $claimed->first()->id);
    }

    // ------------------------------------------------------------
    // Negative control: rollback discards BOTH writes together
    // ------------------------------------------------------------

    public function test_rolling_back_the_shared_transaction_discards_both_the_domain_write_and_the_outbox_row(): void
    {
        $firm = Firm::factory()->create();
        $contact = Contact::factory()->forFirm($firm)->create(['name' => 'Original Name']);
        $domainEventId = (string) Str::uuid();

        $this->runWithFirmContext($firm, function () use ($firm, $contact, $domainEventId) {
            DB::beginTransaction();

            $contact->update(['name' => 'Should Never Persist']);

            $payload = new SanitizedPayloadReference(ResourceType::Contact, (string) $contact->uuid, ['name' => 'Should Never Persist']);
            $this->service->recordOnce($firm->id, null, $domainEventId, 'contact.updated', $payload);

            DB::rollBack();
        });

        $freshContactName = $this->runWithFirmContext($firm, fn () => DB::table('contacts')->where('id', $contact->id)->value('name'));
        $this->assertSame('Original Name', $freshContactName, 'The domain write must not survive the rollback.');

        $outboxRowExists = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_outbox_events')->where('domain_event_id', $domainEventId)->exists(),
        );
        $this->assertFalse($outboxRowExists, 'The outbox row must not survive the rollback — it is not independently persisted.');
    }

    // ------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------

    public function test_two_sequential_record_once_calls_with_the_same_domain_event_id_return_the_same_row(): void
    {
        $firm = Firm::factory()->create();
        $domainEventId = (string) Str::uuid();

        $first = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce($firm->id, null, $domainEventId, 'token_refresh_retry'));
        $second = $this->runWithFirmContext($firm, fn () => $this->service->recordOnce($firm->id, null, $domainEventId, 'token_refresh_retry'));

        $this->assertSame($first->id, $second->id);

        $count = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_outbox_events')->where('domain_event_id', $domainEventId)->count(),
        );
        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------
    // fail() — backoff delay computed at each attempt count
    // ------------------------------------------------------------

    public function test_fail_schedules_a_retry_with_the_correct_backoff_delay_at_the_first_attempt(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 10]);

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(1, $claimed->attempts);

        $before = now();
        $result = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claimed->lock_token, 'simulated_transient_failure'));

        $this->assertNotNull($result);
        $this->assertSame(OutboxEventStatus::Pending, $result->status);
        $this->assertNull($result->lock_token);
        $this->assertNull($result->locked_at);
        $this->assertSame('simulated_transient_failure', $result->last_error);

        // base_delay_seconds=30, multiplier=2, attempt=1 -> 30 * 2^0 = 30s.
        $expectedDelay = 30;
        $actualDelaySeconds = $before->diffInSeconds($result->next_attempt_at, false);
        $this->assertGreaterThanOrEqual($expectedDelay - 2, $actualDelaySeconds);
        $this->assertLessThanOrEqual($expectedDelay + 5, $actualDelaySeconds);
    }

    public function test_fail_computes_exponentially_increasing_delay_across_successive_attempts(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 10]);

        // Attempt 1: expect ~30s delay (30 * 2^0).
        $claim1 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $before1 = now();
        $fail1 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim1->lock_token, 'err_1'));
        $delay1 = $before1->diffInSeconds($fail1->next_attempt_at, false);
        $this->assertGreaterThanOrEqual(28, $delay1);
        $this->assertLessThanOrEqual(35, $delay1);

        // Make the row immediately eligible again (simulating time
        // passing) rather than waiting on a real clock.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->update(['next_attempt_at' => now()->subSecond()]));

        // Attempt 2: expect ~60s delay (30 * 2^1).
        $claim2 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(2, $claim2->attempts);
        $before2 = now();
        $fail2 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim2->lock_token, 'err_2'));
        $delay2 = $before2->diffInSeconds($fail2->next_attempt_at, false);
        $this->assertGreaterThanOrEqual(58, $delay2);
        $this->assertLessThanOrEqual(65, $delay2);

        $this->assertGreaterThan($delay1, $delay2, 'The backoff delay must strictly increase across successive failed attempts.');
    }

    // ------------------------------------------------------------
    // fail() — dead_lettered transition at the correct attempt threshold
    // ------------------------------------------------------------

    public function test_fail_dead_letters_the_row_once_attempts_reach_the_rows_own_max_attempts(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 2]);

        // Attempt 1 of 2 — not yet exhausted, must retry.
        $claim1 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(1, $claim1->attempts);
        $fail1 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim1->lock_token, 'err_1'));
        $this->assertSame(OutboxEventStatus::Pending, $fail1->status);

        $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->update(['next_attempt_at' => now()->subSecond()]));

        // Attempt 2 of 2 — exhausted, must dead-letter.
        $claim2 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(2, $claim2->attempts);
        $fail2 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim2->lock_token, 'err_2_final'));

        $this->assertSame(OutboxEventStatus::DeadLettered, $fail2->status);
        $this->assertNotNull($fail2->dead_lettered_at);
        $this->assertSame('err_2_final', $fail2->last_error);

        // A dead-lettered row must never be re-claimable.
        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(0, $reclaim);
    }

    // ------------------------------------------------------------
    // fail() — no accidental coupling to webhooks-domain-specific
    // config/state (diff-review.md Residual 2)
    // ------------------------------------------------------------

    public function test_fail_uses_the_rows_own_max_attempts_not_the_webhook_domains_default_of_five(): void
    {
        $firm = Firm::factory()->create();
        // max_attempts = 2 is deliberately far BELOW WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts'] = 5.
        // If the outbox accidentally fell back to the webhook default,
        // attempts=2 would NOT be exhausted (2 < 5) and this would retry
        // instead of dead-lettering.
        $this->assertLessThan(WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts'], 2);

        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 2]);

        $claim1 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim1->lock_token, 'err_1'));
        $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->update(['next_attempt_at' => now()->subSecond()]));

        $claim2 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $result = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim2->lock_token, 'err_2'));

        $this->assertSame(
            OutboxEventStatus::DeadLettered,
            $result->status,
            'The outbox must dead-letter at ITS OWN max_attempts=2, proving no fallback to the webhook domain\'s max_attempts=5 default occurred.'
        );
    }

    public function test_the_outbox_default_max_attempts_is_independently_defined_from_the_webhook_domain_default(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(); // default max_attempts, per IntegrationOutboxEventFactory, is 10.

        $this->assertSame(10, $event->max_attempts);
        $this->assertNotSame(
            WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts'],
            $event->max_attempts,
            'The outbox default (10) must remain independent of WebhookRetryPolicyService::DEFAULT_RETRY_POLICY (5).'
        );
    }

    public function test_fail_never_reads_any_webhook_domain_table_or_config(): void
    {
        // Static-assertion companion to the runtime tests above: the
        // service source itself must not reference any webhook-scoped
        // table, model, or config key — only the pure, I/O-free
        // calculator methods of WebhookRetryPolicyService.
        $source = file_get_contents(app_path('Integrations/Services/IntegrationOutboxEventService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('webhook_', $source);
        $this->assertStringNotContainsString('WebhookSubscription', $source);
        $this->assertStringNotContainsString('WebhookDelivery', $source);
        $this->assertStringNotContainsString("config('webhooks", $source);
        $this->assertStringContainsString('isExhausted(', $source);
        $this->assertStringContainsString('nextAttemptDelaySeconds(', $source);
    }
}
