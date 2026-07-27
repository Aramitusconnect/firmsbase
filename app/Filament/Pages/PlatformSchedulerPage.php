<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\SchedulerHealthService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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

    protected static ?int $navigationSort = 82;

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
        $schedulerHealth = app(SchedulerHealthService::class);
        $isHealthy = $schedulerHealth->isHealthy();
        $lastHeartbeatAt = $schedulerHealth->lastHeartbeatAt();

        return $schema->components([
            Section::make('Scheduler Liveness')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->schema([
                    Text::make('Status: '.($isHealthy ? 'Healthy (recent heartbeat seen)' : 'Unhealthy/Unknown (no recent heartbeat)'))
                        ->color($isHealthy ? 'success' : 'danger'),
                    Text::make('Last heartbeat: '.($lastHeartbeatAt === null ? '—' : Carbon::createFromTimestamp($lastHeartbeatAt)->toDayDateTimeString())),
                    Text::make(
                        'This will read Unhealthy/Unknown until Laravel\'s own scheduler (`schedule:run`, invoked every '.
                        'minute by a real cron/systemd entry) has actually executed at least once in this environment — '.
                        'an out-of-codebase operational dependency this application cannot itself satisfy. This phase '.
                        'wires scheduler:heartbeat:record into the registered schedule below (every minute); the '.
                        'heartbeat only starts arriving once the scheduler itself is genuinely running.'
                    )->color('gray'),
                ]),
            Section::make('Registered Schedule')
                ->description('Read live from Laravel\'s own Schedule object — never a hand-maintained duplicate list.')
                ->schema($this->scheduleEntryComponents()),
        ]);
    }

    /**
     * @return array<int, Text>
     */
    private function scheduleEntryComponents(): array
    {
        Artisan::call('about');

        $schedule = app(Schedule::class);

        $entries = collect($schedule->events())
            ->map(function ($event): string {
                $command = trim(str_replace(["'artisan'", 'artisan'], '', (string) ($event->command ?? '')));
                $overlapping = $event->withoutOverlapping ? 'withoutOverlapping' : 'may overlap';

                return "{$command} — cron: {$event->expression} ({$overlapping})";
            })
            ->sort()
            ->values();

        if ($entries->isEmpty()) {
            return [Text::make('No commands are currently registered on the schedule.')];
        }

        return $entries->map(fn (string $entry): Text => Text::make($entry))->all();
    }
}
