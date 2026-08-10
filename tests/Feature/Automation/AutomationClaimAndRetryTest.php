<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Models\AutomationActionExecution;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\DomainEventClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AutomationClaimAndRetryTest — Event-Driven Automation Engine, item
 * 9/10 (retries/failure handling) + item 17 (security — queue retry).
 * Direct unit coverage of DomainEventClaimService and
 * AutomationActionExecutionClaimService's claim/complete/fail
 * primitives: SKIP LOCKED claiming never double-claims, complete()
 * requires the matching lock token, a transient failure below
 * max_attempts schedules a backed-off retry, and an exhausted/permanent
 * failure terminates (dead-letters / fails) instead of retrying
 * forever. AutomationActionExecutionClaimService additionally never
 * claims a RequiresReview row — the structural half of "automation may
 * not approve itself."
 */
class AutomationClaimAndRetryTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // DomainEventClaimService
    // ------------------------------------------------------------

    public function test_domain_event_claim_marks_the_row_claimed_and_increments_attempts(): void
    {
        $firm = Firm::factory()->create();
        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create());

        $claimed = $this->runWithFirmContext($firm, fn () => app(DomainEventClaimService::class)->claim($firm->id));

        $this->assertCount(1, $claimed);
        $this->assertSame($event->id, $claimed->first()->id);
        $this->assertSame('claimed', $claimed->first()->processing_status->value);
        $this->assertSame(1, $claimed->first()->attempts);
    }

    public function test_a_freshly_claimed_domain_event_is_not_reclaimed_by_a_second_claim_call(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create());

        $service = app(DomainEventClaimService::class);
        $first = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id));
        $second = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id));

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    public function test_domain_event_complete_requires_the_matching_lock_token(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create());
        $service = app(DomainEventClaimService::class);
        $claimed = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id))->first();

        $wrongToken = $this->runWithFirmContext($firm, fn () => $service->complete($claimed->id, (string) Str::uuid()));
        $this->assertNull($wrongToken);

        $correct = $this->runWithFirmContext($firm, fn () => $service->complete($claimed->id, $claimed->lock_token));
        $this->assertNotNull($correct);
        $this->assertSame('processed', $correct->processing_status->value);
    }

    public function test_domain_event_transient_failure_below_max_attempts_schedules_a_retry(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create());
        $service = app(DomainEventClaimService::class);
        $claimed = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id))->first();

        $result = $this->runWithFirmContext($firm, fn () => $service->fail($claimed->id, $claimed->lock_token, 'transient hiccup'));

        $this->assertSame('pending', $result->processing_status->value);
        $this->assertNotNull($result->next_attempt_at);
        $this->assertNull($result->lock_token);
        $this->assertNull($result->dead_lettered_at);
    }

    public function test_domain_event_failure_at_max_attempts_dead_letters_instead_of_retrying(): void
    {
        $firm = Firm::factory()->create();
        // Simulates a row that has already failed 4 times.
        $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create(['attempts' => 4]));
        $service = app(DomainEventClaimService::class);
        $claimed = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id))->first(); // attempts -> 5

        $result = $this->runWithFirmContext($firm, fn () => $service->fail($claimed->id, $claimed->lock_token, 'still failing'));

        $this->assertSame('dead_lettered', $result->processing_status->value);
        $this->assertNotNull($result->dead_lettered_at);
    }

    // ------------------------------------------------------------
    // AutomationActionExecutionClaimService
    // ------------------------------------------------------------

    public function test_action_claim_marks_the_row_running_and_increments_attempts(): void
    {
        $firm = Firm::factory()->create();
        $action = $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create());

        $claimed = $this->runWithFirmContext($firm, fn () => app(AutomationActionExecutionClaimService::class)->claim($firm->id));

        $this->assertCount(1, $claimed);
        $this->assertSame($action->id, $claimed->first()->id);
        $this->assertSame(AutomationActionExecutionStatus::Running, $claimed->first()->status);
        $this->assertSame(1, $claimed->first()->attempts);
    }

    public function test_action_claim_never_claims_a_requires_review_row(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create([
            'status' => AutomationActionExecutionStatus::RequiresReview,
        ]));

        $claimed = $this->runWithFirmContext($firm, fn () => app(AutomationActionExecutionClaimService::class)->claim($firm->id));

        $this->assertCount(0, $claimed);
    }

    public function test_action_transient_failure_below_max_attempts_schedules_a_retry(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create());
        $service = app(AutomationActionExecutionClaimService::class);
        $claimed = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id))->first();

        $result = $this->runWithFirmContext($firm, fn () => $service->fail($claimed->id, 'transient hiccup'));

        $this->assertSame(AutomationActionExecutionStatus::RetryScheduled, $result->status);
        $this->assertNotNull($result->next_attempt_at);
    }

    public function test_action_terminal_failure_fails_immediately_regardless_of_attempts_remaining(): void
    {
        $firm = Firm::factory()->create();
        // Only the first attempt — plenty of max_attempts headroom left —
        // yet a permanent exception must still fail immediately, never retry.
        $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create());
        $service = app(AutomationActionExecutionClaimService::class);
        $claimed = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id))->first();

        $result = $this->runWithFirmContext($firm, fn () => $service->fail($claimed->id, 'invalid business state', terminal: true));

        $this->assertSame(AutomationActionExecutionStatus::Failed, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function test_action_skip_marks_succeeded_with_the_reason_recorded(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => AutomationActionExecution::factory()->forFirm($firm)->create());
        $service = app(AutomationActionExecutionClaimService::class);
        $claimed = $this->runWithFirmContext($firm, fn () => $service->claim($firm->id))->first();

        $result = $this->runWithFirmContext($firm, fn () => $service->skip($claimed->id, 'No recipient could be resolved.'));

        $this->assertSame(AutomationActionExecutionStatus::Succeeded, $result->status);
        $this->assertSame('No recipient could be resolved.', $result->last_error);
    }
}
