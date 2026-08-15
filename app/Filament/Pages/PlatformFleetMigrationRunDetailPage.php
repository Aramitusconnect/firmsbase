<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FleetMigrationInstanceStatus as InstanceStatus;
use App\Filament\Actions\Platform\ApplyFleetMigrationInstanceAction;
use App\Filament\Actions\Platform\BeginFleetMigrationRunAction;
use App\Filament\Actions\Platform\CompleteFleetMigrationRunAction;
use App\Filament\Actions\Platform\RollbackFleetMigrationRunAction;
use App\Models\FleetMigrationRun;
use App\Models\PlatformAdmin;
use App\Services\FleetMigrationOrchestrationService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PlatformFleetMigrationRunDetailPage — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Per-run drill-down: shows the
 * run's own status/timestamps, FleetMigrationOrchestrationService::
 * summarize()'s per-status counts, and a per-firm instance-status
 * table (via FleetMigrationOrchestrationService::instancesFor()'s own
 * per-firm-loop pattern — never a second, divergent cross-firm query).
 * Begin/Rollback/Complete are header actions; Apply is a row action on
 * the instances table.
 *
 * Scalar-property-only mount, mirroring PlatformFirmIntegrationsPage's
 * established shape exactly: the only public property is `$runUuid`, a
 * plain string route parameter — the FleetMigrationRun model itself is
 * re-resolved fresh on every read/action via the plain `run()` helper
 * method below (deliberately NOT named getRunProperty()/a Livewire
 * "computed property" — this codebase's Livewire 3 install does not
 * support the old Livewire 2 `getXProperty()` magic-getter convention,
 * confirmed absent everywhere else in this codebase; every other
 * per-firm/per-record drill-down page in this mission instead exposes
 * a plain public scalar property — here `$runUuid` — and has each
 * Action closure re-resolve the model itself via
 * `FleetMigrationRun::findByUuid($livewire->runUuid)`, exactly
 * mirroring RequeueOutboxEventAsSupportAction's own
 * `Firm::findByUuid((string) $livewire->firmUuid)` pattern).
 */
class PlatformFleetMigrationRunDetailPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'platform-fleet-migration-runs/{runUuid}';

    protected static ?string $title = 'Fleet Migration Run';

    public string $runUuid = '';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public function mount(string $runUuid): void
    {
        $this->runUuid = $runUuid;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        if (! static::canAccess()) {
            abort(403);
        }

        try {
            FleetMigrationRun::findByUuid($this->runUuid);
        } catch (ModelNotFoundException $e) {
            throw new NotFoundHttpException('Fleet migration run not found.');
        }
    }

    private function run(): FleetMigrationRun
    {
        return FleetMigrationRun::findByUuid($this->runUuid);
    }

    public function content(Schema $schema): Schema
    {
        $run = $this->run();
        $summary = app(FleetMigrationOrchestrationService::class)->summarize($run);

        return $schema->components([
            Section::make('Rehearsal / Planning Tool — Not a Real Rollout')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->schema([
                    Text::make(
                        'This is a SIMULATED fleet migration rollout. No real infrastructure or firm data is ever '.
                        'touched — every "apply" outcome below is caller-supplied, never the result of a real migration.'
                    )->color('gray'),
                ]),
            Section::make('Run')
                ->schema([
                    Grid::make(3)->schema([
                        Text::make('Migration: '.$run->migration_identifier),
                        Text::make('Status: '.Str::headline($run->status->value)),
                        Text::make('Started at: '.($run->started_at?->toDayDateTimeString() ?? '—')),
                        Text::make('Completed at: '.($run->completed_at?->toDayDateTimeString() ?? '—')),
                        Text::make('Halted reason: '.($run->halted_reason ?? '—')),
                        Text::make(sprintf(
                            'Instances: %d pending, %d applied, %d failed, %d rolled back, %d skipped (%d total)',
                            $summary->pendingCount,
                            $summary->appliedCount,
                            $summary->failedCount,
                            $summary->rolledBackCount,
                            $summary->skippedCount,
                            $summary->totalInstances(),
                        )),
                    ]),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            BeginFleetMigrationRunAction::make()->record($this->run()),
            CompleteFleetMigrationRunAction::make()->record($this->run()),
            RollbackFleetMigrationRunAction::make()->record($this->run()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                return app(FleetMigrationOrchestrationService::class)->instancesFor($this->run());
            })
            ->columns([
                TextColumn::make('firm.name')->label('Firm')->placeholder('Unknown firm'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InstanceStatus $state): string => Str::headline($state->value))
                    ->color(fn (InstanceStatus $state): string => match ($state) {
                        InstanceStatus::Applied => 'success',
                        InstanceStatus::Pending => 'warning',
                        InstanceStatus::Failed => 'danger',
                        InstanceStatus::RolledBack, InstanceStatus::Skipped => 'gray',
                    }),
                TextColumn::make('error_detail')->label('Error detail')->placeholder('No error recorded')->limit(60),
                TextColumn::make('attempted_at')->label('Attempted at')->dateTime()->placeholder('Not attempted'),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('Not completed'),
            ])
            ->recordActions([
                ApplyFleetMigrationInstanceAction::make(),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('No enrolled instances')
            ->paginated(false);
    }

    public function getBreadcrumb(): string
    {
        return $this->run()->migration_identifier;
    }
}
