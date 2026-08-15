<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\SchedulerObservabilityService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformSchedulerPage — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). Read-only. No genuinely safe "run this
 * scheduled command now" action exists distinct from just running the
 * underlying Artisan command directly (out of scope for a web console
 * per phase4-architecture-map-operations-governance.md §A.3) — this
 * page is inherently introspective.
 *
 * The registered schedule is read live from Laravel's own
 * `Illuminate\Console\Scheduling\Schedule` container binding
 * (->events(), each carrying ->command/->expression/->withoutOverlapping)
 * — never a hand-maintained duplicate list, so this page can never
 * drift from bootstrap/app.php's actual ->withSchedule() registration.
 * Artisan::call('about') forces the console Application to bootstrap
 * first (the ->withSchedule() closure is registered via
 * Illuminate\Console\Application::starting(), which does not run
 * merely from a cold `app(Schedule::class)` resolution — see
 * OperationsScheduleRegistrationTest's own comment for the same
 * mechanism), matching the one existing precedent for this pattern in
 * this codebase.
 *
 * Scheduler liveness (SchedulerHealthService::isHealthy()/
 * lastHeartbeatAt()) is honestly disclosed: it will read unknown/
 * unhealthy until scheduler:heartbeat:record has actually run at
 * least once via a real cron/systemd `schedule:run` invocation in the
 * target environment — an out-of-codebase operational dependency this
 * phase's application code cannot itself satisfy (see bootstrap/app.php's
 * own docblock).
 */
class PlatformSchedulerPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Scheduler';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 83;

    protected static ?string $title = 'Scheduler';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->livenessSection(),
            $this->executionHistorySection(),
            $this->registeredScheduleSection(),
        ]);
    }

    /**
     * The one genuinely observable execution fact: the scheduler
     * process recorded a heartbeat recently, or it did not.
     */
    private function livenessSection(): Section
    {
        $heartbeat = app(SchedulerObservabilityService::class)->heartbeat();

        $status = match (true) {
            ! $heartbeat['observed'] => ['Never Observed — no scheduler heartbeat has ever been recorded', 'danger'],
            $heartbeat['healthy'] => ['Healthy — heartbeat recorded '.$heartbeat['age_seconds'].'s ago', 'success'],
            default => ['Stale — last heartbeat was '.$heartbeat['age_seconds'].'s ago, beyond the staleness window', 'danger'],
        };

        return Section::make('Scheduler Liveness')
            ->icon(Heroicon::OutlinedSignal)
            ->schema([
                Text::make('Status: '.$status[0])->color($status[1]),
                Text::make('Last heartbeat: '.($heartbeat['last_heartbeat_at']?->toDayDateTimeString() ?? 'Never')),
                Text::make(
                    'This reads Never Observed until Laravel\'s own scheduler (`schedule:run`, invoked every minute by '.
                    'a real cron/systemd entry) has actually executed at least once in this environment — an '.
                    'out-of-codebase operational dependency this application cannot itself satisfy. The heartbeat '.
                    'proves the scheduler process is running; it proves nothing about whether any individual command '.
                    'below succeeded.'
                )->color('gray'),
            ]);
    }

    /**
     * The explicit gap. Without this section a reader would
     * reasonably assume the registered entries below had run.
     */
    private function executionHistorySection(): Section
    {
        $service = app(SchedulerObservabilityService::class);

        if ($service->hasExecutionHistory()) {
            return Section::make('Execution History')->schema([Text::make('Execution history is available.')]);
        }

        return Section::make('Execution History Not Available')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema([
                Text::make($service->executionHistoryUnavailableReason())->color('warning'),
                Text::make(
                    'Nothing on this page should be read as "this command ran." Overdue detection and repeated-failure '.
                    'detection are unavailable for the same reason.'
                )->color('gray'),
            ]);
    }

    private function registeredScheduleSection(): Section
    {
        $entries = app(SchedulerObservabilityService::class)->registeredEntries();

        if ($entries === []) {
            return Section::make('Registered Schedule')
                ->schema([Text::make('No commands are currently registered on the schedule.')]);
        }

        return Section::make('Registered Schedule ('.count($entries).')')
            ->icon(Heroicon::OutlinedClock)
            ->description('Read live from Laravel\'s own Schedule object — never a hand-maintained duplicate list. These are registrations, not executions.')
            ->schema(array_map(
                fn (array $entry): Text => Text::make(sprintf(
                    '%s — cron: %s · timezone: %s · %s · %s · next expected run: %s · overlap lock: %s',
                    $entry['command'],
                    $entry['expression'],
                    $entry['timezone'],
                    $entry['without_overlapping'] ? 'withoutOverlapping' : 'may overlap',
                    $entry['on_one_server'] ? 'onOneServer' : 'any server',
                    $entry['next_run'] ?? 'Not Calculable',
                    $entry['lock_state'],
                )),
                $entries,
            ));
    }
}
