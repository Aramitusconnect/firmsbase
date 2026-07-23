<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\FirmActivationStatus;
use App\Jobs\OutboxDispatchJob;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DispatchOutboxEventsCommandTest — Checkpoint 8
 * (agent-8b-outbox-dispatch-design.md §1.4;
 * agent-8h-architecture-security-review.md §4.2). Enumerates only
 * Firm::where('activation_status', Activated); dispatches exactly one
 * job per active firm; does not touch inactive/unactivated firms.
 */
class DispatchOutboxEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_exactly_one_job_per_activated_firm(): void
    {
        Queue::fake();

        $activeFirmA = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated->value]);
        $activeFirmB = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated->value]);

        Artisan::call('integrations:outbox:dispatch');

        Queue::assertPushed(OutboxDispatchJob::class, 2);
        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $activeFirmA->id);
        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $activeFirmB->id);
    }

    public function test_does_not_dispatch_for_a_draft_firm(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft->value]);

        Artisan::call('integrations:outbox:dispatch');

        Queue::assertNotPushed(OutboxDispatchJob::class);
    }

    public function test_does_not_dispatch_for_an_onboarding_firm(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Onboarding->value]);

        Artisan::call('integrations:outbox:dispatch');

        Queue::assertNotPushed(OutboxDispatchJob::class);
    }

    public function test_a_mix_of_activated_and_non_activated_firms_dispatches_only_for_the_activated_ones(): void
    {
        Queue::fake();

        $activated = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated->value]);
        $draft = Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft->value]);
        $onboarding = Firm::factory()->create(['activation_status' => FirmActivationStatus::Onboarding->value]);

        Artisan::call('integrations:outbox:dispatch');

        Queue::assertPushed(OutboxDispatchJob::class, 1);
        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $activated->id);
        Queue::assertNotPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $draft->id);
        Queue::assertNotPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->firmId === $onboarding->id);
    }

    public function test_the_dispatched_jobs_batch_size_matches_the_batch_size_option(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated->value]);

        Artisan::call('integrations:outbox:dispatch', ['--batch-size' => 50]);

        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->batchSize === 50);
    }

    public function test_the_dispatched_jobs_default_batch_size_is_25(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated->value]);

        Artisan::call('integrations:outbox:dispatch');

        Queue::assertPushed(OutboxDispatchJob::class, fn (OutboxDispatchJob $job) => $job->batchSize === 25);
    }

    public function test_zero_activated_firms_dispatches_nothing_and_still_succeeds(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Draft->value]);

        $exitCode = Artisan::call('integrations:outbox:dispatch');

        $this->assertSame(0, $exitCode);
        Queue::assertNotPushed(OutboxDispatchJob::class);
    }

    public function test_the_command_requires_no_tenant_context_to_run(): void
    {
        Queue::fake();

        Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated->value]);

        $this->assertNoDatabaseTenantContext('No tenant context should be active before running the command.');

        Artisan::call('integrations:outbox:dispatch');

        $this->assertNoDatabaseTenantContext('The command itself never sets tenant context — `firms` is not FORCE-RLS\'d.');
    }
}
