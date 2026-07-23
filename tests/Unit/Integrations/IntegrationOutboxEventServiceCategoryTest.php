<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\IntegrationRequeueAuditLogger;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationOutboxEventServiceCategoryTest — Checkpoint 8
 * (agent-8h-architecture-security-review.md §1 item 2 / §2 item 2;
 * agent-8e-retry-backoff-ratelimit-design.md §1/§6). Proves fail()'s
 * new, optional 4th $category parameter:
 *
 *  - default-null behavior is BYTE-IDENTICAL to pre-Checkpoint-8 —
 *    rerun the exact assertions
 *    IntegrationOutboxTransactionDurabilityTest's two named backoff
 *    tests use ([28,35]/[58,65] tolerance windows), with no category
 *    passed, confirming identical results;
 *  - a terminal category (e.g. authentication_failed) forces
 *    dead-letter at attempts=1 even with a high max_attempts
 *    remaining.
 *
 * Placed under tests/Unit per agent-8h §4.2's exact file allowlist,
 * despite exercising a real Postgres connection via RefreshDatabase —
 * mirrors this codebase's own existing convention (e.g.
 * IntegrationInboundWebhookEventsForceRlsActivationTest also lives
 * under tests/Unit/Integrations while using RefreshDatabase).
 */
class IntegrationOutboxEventServiceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationOutboxEventService(new WebhookRetryPolicyService(), new TimelineEventRecorder(), new IntegrationRequeueAuditLogger());
    }

    // ------------------------------------------------------------
    // Default-null behavior is byte-identical to pre-Checkpoint-8 —
    // rerunning IntegrationOutboxTransactionDurabilityTest's exact two
    // named backoff assertions with no 4th argument passed.
    // ------------------------------------------------------------

    public function test_fail_with_no_category_schedules_a_retry_with_the_correct_backoff_delay_at_the_first_attempt(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 10]);

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(1, $claimed->attempts);

        $before = now();
        // No 4th argument at all — proves the parameter is genuinely
        // optional, not merely defaultable-but-required.
        $result = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claimed->lock_token, 'simulated_transient_failure'));

        $this->assertNotNull($result);
        $this->assertSame(OutboxEventStatus::Pending, $result->status);
        $this->assertNull($result->lock_token);
        $this->assertNull($result->locked_at);
        $this->assertSame('simulated_transient_failure', $result->last_error);

        $expectedDelay = 30;
        $actualDelaySeconds = $before->diffInSeconds($result->next_attempt_at, false);
        $this->assertGreaterThanOrEqual($expectedDelay - 2, $actualDelaySeconds);
        $this->assertLessThanOrEqual($expectedDelay + 5, $actualDelaySeconds);
    }

    public function test_fail_with_explicit_null_category_computes_exponentially_increasing_delay_across_successive_attempts(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 10]);

        $claim1 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $before1 = now();
        // Explicit null (the documented default) passed as the 4th
        // argument this time — proves null-explicit and
        // omitted-entirely are indistinguishable.
        $fail1 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim1->lock_token, 'err_1', null));
        $delay1 = $before1->diffInSeconds($fail1->next_attempt_at, false);
        $this->assertGreaterThanOrEqual(28, $delay1);
        $this->assertLessThanOrEqual(35, $delay1);

        $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->update(['next_attempt_at' => now()->subSecond()]));

        $claim2 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(2, $claim2->attempts);
        $before2 = now();
        $fail2 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim2->lock_token, 'err_2', null));
        $delay2 = $before2->diffInSeconds($fail2->next_attempt_at, false);
        $this->assertGreaterThanOrEqual(58, $delay2);
        $this->assertLessThanOrEqual(65, $delay2);

        $this->assertGreaterThan($delay1, $delay2);
    }

    public function test_fail_with_no_category_still_dead_letters_at_the_rows_own_max_attempts(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 2]);

        $claim1 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $fail1 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim1->lock_token, 'err_1'));
        $this->assertSame(OutboxEventStatus::Pending, $fail1->status, 'Attempt 1 of 2 must still retry — never exhausted early.');

        $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->update(['next_attempt_at' => now()->subSecond()]));

        $claim2 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(2, $claim2->attempts);
        $fail2 = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claim2->lock_token, 'err_2_final'));

        $this->assertSame(OutboxEventStatus::DeadLettered, $fail2->status);
        $this->assertNotNull($fail2->dead_lettered_at);

        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(0, $reclaim);
    }

    // ------------------------------------------------------------
    // A terminal category forces dead-letter at attempts=1 even with a
    // high max_attempts remaining.
    // ------------------------------------------------------------

    public function test_a_terminal_category_forces_dead_letter_at_attempts_1_even_with_a_high_max_attempts(): void
    {
        $this->assertContains(
            'authentication_failed',
            WebhookRetryPolicyService::TERMINAL_CATEGORIES,
            'Sanity check: authentication_failed must genuinely be a terminal category for this test to prove anything.'
        );

        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 50]); // deliberately high — plenty of "remaining" attempts

        $claim1 = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(1, $claim1->attempts, 'This must be the FIRST attempt.');

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->fail($event->id, $claim1->lock_token, 'auth_failed', 'authentication_failed'),
        );

        $this->assertNotNull($result);
        $this->assertSame(
            OutboxEventStatus::DeadLettered,
            $result->status,
            'A terminal category must force immediate dead-letter on the FIRST failed attempt, regardless of the row\'s own high max_attempts (50).'
        );
        $this->assertNotNull($result->dead_lettered_at);
        $this->assertSame('auth_failed', $result->last_error);
        $this->assertSame(1, $result->attempts, 'attempts must remain 1 — the terminal category short-circuits BEFORE any attempt-count-based exhaustion check, not by silently inflating the attempt counter.');
    }

    public function test_every_terminal_category_forces_first_occurrence_dead_letter(): void
    {
        foreach (WebhookRetryPolicyService::TERMINAL_CATEGORIES as $category) {
            $firm = Firm::factory()->create();
            $event = IntegrationOutboxEvent::factory()
                ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
                ->create(['max_attempts' => 100]);

            $claim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
            $result = $this->runWithFirmContext(
                $firm,
                fn () => $this->service->fail($event->id, $claim->lock_token, "failed:{$category}", $category),
            );

            $this->assertSame(
                OutboxEventStatus::DeadLettered,
                $result->status,
                "Category '{$category}' is declared TERMINAL and must force first-occurrence dead-letter."
            );
        }
    }

    public function test_a_non_terminal_category_still_retries_normally_rather_than_forcing_dead_letter(): void
    {
        $this->assertNotContains('rate_limited', WebhookRetryPolicyService::TERMINAL_CATEGORIES);

        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 10]);

        $claim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->fail($event->id, $claim->lock_token, 'rate_limited_error', 'rate_limited'),
        );

        $this->assertSame(
            OutboxEventStatus::Pending,
            $result->status,
            'A non-terminal category (rate_limited) with plenty of remaining max_attempts must retry normally, never force dead-letter.'
        );
        $this->assertNull($result->dead_lettered_at);
        $this->assertNotNull($result->next_attempt_at);
    }

    public function test_a_category_category_max_attempts_key_is_not_required_and_ordinary_max_attempts_exhaustion_still_works_with_a_category_present(): void
    {
        // A non-terminal category, at the row's OWN max_attempts
        // exhaustion point, must still dead-letter via the ordinary
        // attempt-count mechanism (proving the category param doesn't
        // accidentally suppress normal exhaustion for non-terminal
        // categories).
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 1]);

        $claim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->assertSame(1, $claim->attempts);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->fail($event->id, $claim->lock_token, 'provider_error', 'provider_rejected'),
        );

        $this->assertSame(
            OutboxEventStatus::DeadLettered,
            $result->status,
            'attempts (1) >= max_attempts (1) must still dead-letter via ordinary exhaustion, independent of the (non-terminal) category.'
        );
    }
}
