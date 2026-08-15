<?php

namespace Tests\Feature\Operations;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformSchedulerPage;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\SchedulerHealthService;
use App\Services\SchedulerObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operations Control Plane — proves the Scheduler console never
 * presents a registration as an execution.
 *
 * A cron expression can tell you when a command was SUPPOSED to run.
 * Deriving "last run" from it produces a number that is right most of
 * the time and catastrophically wrong exactly when it matters — when
 * the scheduler has stopped.
 */
class SchedulerExecutionTruthTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SchedulerObservabilityService
    {
        return app(SchedulerObservabilityService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_no_execution_history_backend_exists(): void
    {
        $this->assertFalse(
            $this->service()->hasExecutionHistory(),
            'a real scheduler run-history backend now exists — the console must start showing it, and this '.
            'mission\'s reported SCHEDULER_EXECUTION_HISTORY finding must be revisited',
        );
    }

    public function test_registered_entries_never_carry_a_last_run_field(): void
    {
        foreach ($this->service()->registeredEntries() as $entry) {
            $this->assertArrayNotHasKey('last_run', $entry);
            $this->assertArrayNotHasKey('last_success', $entry);
            $this->assertArrayNotHasKey('last_failure', $entry);
            $this->assertArrayNotHasKey('consecutive_failures', $entry);
        }
    }

    public function test_registered_entries_are_read_from_the_real_schedule(): void
    {
        $entries = collect($this->service()->registeredEntries());

        $this->assertNotEmpty($entries);
        $this->assertTrue(
            $entries->contains(fn (array $entry): bool => str_contains($entry['command'], 'health:checks:run')),
            'the registered schedule must be read live, not from a maintained list',
        );
    }

    public function test_each_entry_reports_cron_timezone_and_overlap_settings(): void
    {
        $entry = collect($this->service()->registeredEntries())
            ->first(fn (array $entry): bool => str_contains($entry['command'], 'health:checks:run'));

        $this->assertSame('*/5 * * * *', $entry['expression']);
        $this->assertTrue($entry['without_overlapping']);
        $this->assertNotEmpty($entry['timezone']);
        $this->assertIsBool($entry['on_one_server']);
    }

    /**
     * Next run IS derivable from a cron expression — it is a property
     * of the registration, not a claim about history — so it is shown.
     */
    public function test_next_expected_run_is_derived_from_the_cron_expression(): void
    {
        $entry = collect($this->service()->registeredEntries())
            ->first(fn (array $entry): bool => str_contains($entry['command'], 'health:checks:run'));

        $this->assertNotNull($entry['next_run']);
        $this->assertGreaterThan(
            now()->subMinute()->toDateTimeString(),
            $entry['next_run'],
            'the next expected run must be in the future',
        );
    }

    /**
     * Lock state must always come from the closed, unambiguous
     * vocabulary — never a bare dash, never blank. Entries that may
     * overlap have no lock to report and must say Not Applicable
     * rather than implying an unheld lock.
     */
    public function test_lock_state_always_uses_the_explicit_vocabulary(): void
    {
        $entries = $this->service()->registeredEntries();

        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            if (! $entry['without_overlapping']) {
                $this->assertSame('Not Applicable', $entry['lock_state'], $entry['command']);

                continue;
            }

            $this->assertContains(
                $entry['lock_state'],
                ['Not Held', 'Held (running, or a previous run did not release it)', 'Unknown'],
                $entry['command'].' reported an unrecognised lock state',
            );
        }
    }

    public function test_an_unheld_overlap_lock_reads_as_not_held(): void
    {
        $entry = collect($this->service()->registeredEntries())
            ->first(fn (array $entry): bool => $entry['without_overlapping']);

        $this->assertSame('Not Held', $entry['lock_state']);
    }

    // --- Heartbeat ---

    public function test_heartbeat_reports_never_observed_before_the_scheduler_has_ever_run(): void
    {
        $heartbeat = $this->service()->heartbeat();

        $this->assertFalse($heartbeat['observed']);
        $this->assertFalse($heartbeat['healthy']);
        $this->assertNull($heartbeat['last_heartbeat_at']);
        $this->assertNull($heartbeat['age_seconds'], 'an unobserved heartbeat has no age, not an age of 0');
    }

    public function test_heartbeat_reports_healthy_once_recorded(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        $heartbeat = $this->service()->heartbeat();

        $this->assertTrue($heartbeat['observed']);
        $this->assertTrue($heartbeat['healthy']);
        $this->assertNotNull($heartbeat['last_heartbeat_at']);
    }

    // --- Page rendering ---

    public function test_the_page_states_that_execution_history_is_unavailable(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl());

        $response->assertOk();
        $response->assertSee('Execution History Not Available');
        $response->assertSee('Registration is not execution.');
    }

    public function test_the_page_shows_registrations_labelled_as_registrations(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl());

        $response->assertOk();
        $response->assertSee('These are registrations, not executions.');
        $response->assertSee('next expected run');
    }

    public function test_the_page_offers_no_run_now_or_clear_lock_action(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformSchedulerPage::getUrl());

        $response->assertOk();
        // Clearing a live overlap mutex would permit a second
        // concurrent run of a command that explicitly asked never to
        // overlap. No canonical safe service exists for either action,
        // so neither is offered.
        $response->assertDontSee('Run Now');
        $response->assertDontSee('Clear Lock');
    }
}
