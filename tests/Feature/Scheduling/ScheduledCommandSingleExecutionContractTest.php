<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Services\Scheduling\ScheduledCommandSingleExecutionContract as Contract;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Asserts the real schedule matches the reviewed single-execution contract.
 *
 * This reads the Schedule the application actually registers — not the text of
 * bootstrap/app.php — so it cannot be fooled by a comment, a refactor, or a
 * command registered elsewhere.
 *
 * The check runs in BOTH directions on purpose. A command missing from the
 * contract fails as loudly as a contract entry with no command, because the
 * failure mode being guarded is a new scheduled task silently inheriting
 * someone else's risk decision.
 */
class ScheduledCommandSingleExecutionContractTest extends TestCase
{
    /** @return array<string, Event> keyed by artisan command signature */
    private function scheduledEvents(): array
    {
        // withSchedule() in bootstrap/app.php is only applied when the console
        // schedule is actually resolved. A feature test boots the HTTP kernel,
        // so the container otherwise hands back an EMPTY Schedule and every
        // assertion below would pass for the wrong reason (verified: 0 events
        // before, 17 after). Running schedule:list forces real registration.
        Artisan::call('schedule:list');

        $out = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            // Event::$command looks like: '/usr/bin/php8.3' 'artisan' some:command
            if (! preg_match('/\b([a-z0-9]+(?::[a-z0-9_-]+)+)/', (string) $event->command, $m)) {
                continue;
            }

            $out[$m[1]] = $event;
        }

        return $out;
    }

    public function test_every_scheduled_command_has_an_explicit_contract_entry(): void
    {
        $scheduled = array_keys($this->scheduledEvents());
        $contract = array_keys(Contract::definitions());
        sort($scheduled);
        sort($contract);

        $undocumented = array_values(array_diff($scheduled, $contract));
        $this->assertSame([], $undocumented,
            'These scheduled commands have no single-execution classification. Add them to '
            .'ScheduledCommandSingleExecutionContract with a risk class and rationale: '
            .implode(', ', $undocumented));

        $stale = array_values(array_diff($contract, $scheduled));
        $this->assertSame([], $stale,
            'These contract entries no longer correspond to a scheduled command: '.implode(', ', $stale));
    }

    public function test_commands_requiring_single_server_actually_declare_on_one_server(): void
    {
        $events = $this->scheduledEvents();

        foreach (Contract::definitions() as $command => $definition) {
            $this->assertArrayHasKey($command, $events, "Scheduled command [{$command}] not registered.");

            $this->assertSame(
                $definition['on_one_server'],
                $events[$command]->onOneServer,
                sprintf('Command [%s] is classified %s with on_one_server=%s (%s) but the schedule declares %s.',
                    $command,
                    $definition['risk'],
                    $definition['on_one_server'] ? 'true' : 'false',
                    $definition['rationale'],
                    $events[$command]->onOneServer ? 'onOneServer()' : 'no onOneServer()'),
            );
        }
    }

    public function test_commands_requiring_overlap_protection_declare_without_overlapping(): void
    {
        $events = $this->scheduledEvents();

        foreach (Contract::definitions() as $command => $definition) {
            if (! $definition['without_overlapping']) {
                continue;
            }

            $this->assertNotNull($events[$command]->expiresAt ?? null,
                "Command [{$command}] is contracted to use withoutOverlapping() but does not.");
        }
    }

    public function test_every_p0_and_p1_command_is_single_served(): void
    {
        // The cutover-critical assertion: nothing that can move money, issue a
        // provider command, or notify a customer may run on two hosts at once.
        foreach (['P0', 'P1'] as $risk) {
            foreach (Contract::commandsWithRisk($risk) as $command) {
                $this->assertTrue(Contract::definitions()[$command]['on_one_server'],
                    "[{$command}] is classified {$risk} and must require single-server execution.");
            }
        }
    }

    public function test_the_heartbeat_is_deliberately_not_single_served(): void
    {
        // Guards the reasoning, not just the value: this exemption exists so a
        // second live scheduler stays visible. "Fixing" it would silently
        // remove the detection signal.
        $definition = Contract::definitions()['scheduler:heartbeat:record'];

        $this->assertFalse($definition['on_one_server']);
        $this->assertSame('P3', $definition['risk']);
        $this->assertStringContainsString('split-brain', $definition['rationale']);
        $this->assertFalse($this->scheduledEvents()['scheduler:heartbeat:record']->onOneServer);
    }

    public function test_commands_without_an_atomic_second_layer_are_identified(): void
    {
        // These depend on the scheduler being genuinely single across hosts,
        // because nothing behind them claims work atomically. Asserting the
        // list means it cannot quietly grow.
        $expected = [
            'automation:sweep:deadlines',
            'automation:sweep:document-request-reminders',
            'automation:sweep:invoice-overdue',
            'automation:sweep:leverage-recommendations',
            'automation:sweep:matter-budgets',
        ];
        $actual = Contract::commandsWithoutAtomicLayer2();
        sort($actual);

        $this->assertSame($expected, $actual,
            'The set of scheduled commands with no atomic duplicate-protection changed. '
            .'Each relies on scheduler single-execution alone — review carefully.');

        foreach ($actual as $command) {
            $this->assertTrue(Contract::definitions()[$command]['on_one_server'],
                "[{$command}] has no atomic layer 2 and therefore MUST be single-served.");
        }
    }

    public function test_shared_lock_store_requirements_are_documented(): void
    {
        // onOneServer() is only as good as the lock store behind it. If two
        // hosts disagree on any of these the lock lands in two namespaces and
        // both hosts run the task while appearing correctly configured.
        foreach (['CACHE_STORE', 'CACHE_PREFIX', 'APP_NAME', 'REDIS_HOST', 'REDIS_DB'] as $setting) {
            $this->assertContains($setting, Contract::SHARED_LOCK_STORE_REQUIREMENTS,
                "[{$setting}] must be listed as a shared-lock-store requirement.");
        }
    }
}
