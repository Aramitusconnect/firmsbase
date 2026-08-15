<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\BackupRestoreTestStatus;
use App\Models\BackupRestoreTest;
use App\Models\PlatformAdmin;
use App\Services\BackupRestoreCapabilityService;
use App\Services\BackupRestoreTestService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformBackupsPage — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations"). READ-ONLY over `backup_restore_tests`
 * (disaster-recovery drill results, NOT a backup inventory — see this
 * class's own disclosure below). Mirrors
 * app/Filament/Pages/PlatformResellersPage.php's (Phase 3) established
 * "structurally required but backend-incomplete: honest disclosure +
 * real, differently-scoped adjacent data" pattern exactly (Resolved
 * Decision confirmed for this pass — Open Decision 4 of
 * phase4-architecture-map-operations-governance.md).
 *
 * NO "Run Drill" action is registered anywhere in this class —
 * `BackupRestoreDrillRunner`'s only implementation anywhere in this
 * codebase is `FakeBackupRestoreDrillRunner`; a fake "Passed" result
 * clicked from a platform-admin console would create false confidence
 * about real disaster-recovery readiness, a materially worse outcome
 * than a merely-inert page. See BackupRestoreTestServiceTest and this
 * class's own docblock precedent (PlatformRefundResource's identical
 * "no Issue Refund action" reasoning re: FakeStripeGateway).
 *
 * `backup_restore_tests` carries FORCE ROW LEVEL SECURITY with the
 * same "nullable-firm_id, universal read" shape as `health_checks` —
 * firm_id IS NULL (platform-wide drill) rows are visible under the
 * read policy regardless of active tenant context, so no context wrap
 * is needed for the platform-wide history this page shows.
 */
class PlatformBackupsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Backups';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 84;

    protected static ?string $title = 'Backups';

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
            $this->disclosureSection(),
            $this->inventorySection(),
            $this->recoveryObjectivesSection(),
            $this->latestDrillSection(),
            EmbeddedTable::make(),
        ]);
    }

    private function disclosureSection(): Section
    {
        return Section::make('No Real Backup/Restore Drill Capability Exists')
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->schema([
                Text::make(app(BackupRestoreCapabilityService::class)->disclosure())->color('danger'),
                Text::make(
                    'A "Run Drill" action is deliberately NOT provided anywhere on this page. The only runner that '.
                    'exists would record a "Passed" result without restoring anything, and a passed drill in the '.
                    'history is exactly the artefact someone would later cite as evidence of recovery readiness.'
                )->color('gray'),
            ]);
    }

    /**
     * Recovery objectives, with target and actual kept rigidly apart.
     *
     * Target is policy and comes from the drill record. Actual is a
     * measurement and comes only from a real restore — while none has
     * happened, it reads Not Yet Measured no matter what numbers a
     * simulated drill stored.
     */
    private function recoveryObjectivesSection(): Section
    {
        $capability = app(BackupRestoreCapabilityService::class);
        $latest = app(BackupRestoreTestService::class)->latestFor(null);

        return Section::make('Recovery Objectives')
            ->icon(Heroicon::OutlinedClock)
            ->schema([
                Text::make('Target RPO (policy): '.($latest?->rpo_target_seconds !== null ? $latest->rpo_target_seconds.'s' : 'Not Configured')),
                Text::make('Actual RPO (measured in a real recovery): '.$capability->actualRpoLabel())
                    ->color($capability->measuredActualRpoSeconds() === null ? 'warning' : 'success'),
                Text::make('Target RTO (policy): '.($latest?->rto_target_seconds !== null ? $latest->rto_target_seconds.'s' : 'Not Configured')),
                Text::make('Actual RTO (measured in a real recovery): '.$capability->actualRtoLabel())
                    ->color($capability->measuredActualRtoSeconds() === null ? 'warning' : 'success'),
                Text::make(
                    'A target is what this platform intends to achieve. An actual is what it has been observed to '.
                    'achieve while genuinely recovering. A configured backup interval is not an actual RPO, and a '.
                    'passing unit test is not an actual RTO.'
                )->color('gray'),
            ]);
    }

    /**
     * What this platform can and cannot see about backups at all.
     */
    private function inventorySection(): Section
    {
        $capability = app(BackupRestoreCapabilityService::class);

        return Section::make('Backup Inventory & PITR')
            ->icon(Heroicon::OutlinedCircleStack)
            ->schema([
                Text::make('Backup inventory: '.($capability->hasBackupInventory() ? 'Available' : 'Not Available'))
                    ->color($capability->hasBackupInventory() ? 'success' : 'warning'),
                Text::make('Point-in-time recovery: '.($capability->hasVerifiedPitr() ? 'Verified' : 'Unknown — Not Verified'))
                    ->color($capability->hasVerifiedPitr() ? 'success' : 'warning'),
                Text::make('Last verified real restore: '.($capability->hasVerifiedRestore() ? 'See history below' : 'Never'))
                    ->color($capability->hasVerifiedRestore() ? 'success' : 'danger'),
                Text::make(
                    'No verified backup inventory is available: this application holds no AWS Backup, RDS, or object '.
                    'storage client, so it cannot enumerate snapshots, read a retention setting, or query a '.
                    'restorable time window. Reading any of those requires new AWS integration and IAM permissions, '.
                    'which need owner approval and are not part of this change.'
                )->color('gray'),
            ]);
    }

    private function latestDrillSection(): Section
    {
        $service = app(BackupRestoreTestService::class);
        $capability = app(BackupRestoreCapabilityService::class);
        $latest = $service->latestFor(null);

        if ($latest === null) {
            return Section::make('Latest Recorded Drill')
                ->schema([Text::make('No platform-wide drill has ever been recorded.')]);
        }

        $qualifier = $capability->recordedFigureQualifier();

        return Section::make('Latest Recorded Drill')
            ->schema([
                Text::make('Status: '.Str::headline($latest->status->value)),
                Text::make('Recorded RPO figure: '.($latest->rpo_actual_seconds !== null ? $latest->rpo_actual_seconds.'s ('.$qualifier.')' : 'Not recorded')),
                Text::make('Recorded RTO figure: '.($latest->rto_actual_seconds !== null ? $latest->rto_actual_seconds.'s ('.$qualifier.')' : 'Not recorded')),
                Text::make('Recorded figures within recorded targets: '.($latest->meetsTargets() ? 'Yes' : 'No').
                    ($capability->hasRealDrillRunner() ? '' : ' — against simulated figures, so this proves nothing about real recovery')),
                Text::make('All 6 required components marked verified: '.($service->fullyVerified($latest) ? 'Yes' : 'No').
                    ($capability->hasRealDrillRunner() ? '' : ' — marked by the fake runner, not actually verified')),
                Text::make('Completed at: '.($latest->completed_at?->toDayDateTimeString() ?? 'Not completed')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => BackupRestoreTest::query()->whereNull('firm_id'))
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BackupRestoreTestStatus::cases())
                        ->mapWithKeys(fn (BackupRestoreTestStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BackupRestoreTestStatus $state): string => Str::headline($state->value))
                    ->color(fn (BackupRestoreTestStatus $state): string => match ($state) {
                        BackupRestoreTestStatus::Passed => 'success',
                        BackupRestoreTestStatus::InProgress => 'warning',
                        BackupRestoreTestStatus::Failed, BackupRestoreTestStatus::Skipped => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('evidence_kind')
                    ->label('Evidence')
                    ->badge()
                    // The most important column in this table: whether
                    // the row's numbers came from a real restore or a
                    // test double.
                    ->state(fn (): string => app(BackupRestoreCapabilityService::class)->hasRealDrillRunner()
                        ? 'Measured'
                        : 'Simulated')
                    ->color(fn (): string => app(BackupRestoreCapabilityService::class)->hasRealDrillRunner()
                        ? 'success'
                        : 'warning')
                    ->tooltip('Simulated rows were produced by FakeBackupRestoreDrillRunner and involved no real restore.'),
                IconColumn::make('meets_targets')
                    ->label('Within targets')
                    ->boolean()
                    ->state(fn (BackupRestoreTest $record): bool => $record->meetsTargets())
                    ->tooltip('Compares the recorded figures against the recorded targets. With simulated figures this says nothing about real recovery.'),
                TextColumn::make('rpo_actual_seconds')
                    ->label('Recorded RPO (s)')
                    ->placeholder('Not recorded')
                    ->alignEnd(),
                TextColumn::make('rto_actual_seconds')
                    ->label('Recorded RTO (s)')
                    ->placeholder('Not recorded')
                    ->alignEnd(),
                TextColumn::make('started_at')->label('Started at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('Not completed')->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No backup/restore drills recorded yet')
            ->emptyStateDescription('No drill of any kind has been recorded. Note that no real restore capability exists to record one from.')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
