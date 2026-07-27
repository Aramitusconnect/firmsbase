<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\BackupRestoreTestStatus;
use App\Models\BackupRestoreTest;
use App\Models\PlatformAdmin;
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

    protected static ?int $navigationSort = 83;

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
            $this->latestDrillSection(),
            EmbeddedTable::make(),
        ]);
    }

    private function disclosureSection(): Section
    {
        return Section::make('No Real Backup/Restore Drill Capability Exists')
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->schema([
                Text::make(
                    'This is NOT a live backup inventory or a "run a real disaster-recovery drill" tool. '.
                    'backup_restore_tests records the RESULT of a BackupRestoreDrillRunner — and the only '.
                    'implementation of that interface anywhere in this codebase is FakeBackupRestoreDrillRunner. '.
                    'No production implementation performing a real infrastructure backup/restore exists (an explicit '.
                    'project rule, mirroring the FakeStripeGateway precedent). A "Run Drill" action is deliberately '.
                    'NOT provided here — a fake "Passed" result could create false confidence about real '.
                    'disaster-recovery readiness. Below is real, honestly-labeled drill HISTORY data only.'
                )->color('danger'),
            ]);
    }

    private function latestDrillSection(): Section
    {
        $service = app(BackupRestoreTestService::class);
        $latest = $service->latestFor(null);

        if ($latest === null) {
            return Section::make('Latest Platform-Wide Drill')
                ->schema([Text::make('No platform-wide drill has ever been recorded.')]);
        }

        return Section::make('Latest Platform-Wide Drill')
            ->schema([
                Text::make('Status: '.Str::headline($latest->status->value)),
                Text::make('Meets RPO/RTO targets: '.($latest->meetsTargets() ? 'Yes' : 'No')),
                Text::make('Fully verified (all 6 required components): '.($service->fullyVerified($latest) ? 'Yes' : 'No')),
                Text::make('RPO target/actual: '.$latest->rpo_target_seconds.'s / '.($latest->rpo_actual_seconds ?? '—').'s'),
                Text::make('RTO target/actual: '.$latest->rto_target_seconds.'s / '.($latest->rto_actual_seconds ?? '—').'s'),
                Text::make('Completed at: '.($latest->completed_at?->toDayDateTimeString() ?? '—')),
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
                IconColumn::make('meets_targets')
                    ->label('Meets targets')
                    ->boolean()
                    ->state(fn (BackupRestoreTest $record): bool => $record->meetsTargets()),
                TextColumn::make('rpo_actual_seconds')->label('RPO actual (s)')->placeholder('—')->alignEnd(),
                TextColumn::make('rto_actual_seconds')->label('RTO actual (s)')->placeholder('—')->alignEnd(),
                TextColumn::make('started_at')->label('Started at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->label('Completed at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No backup/restore drills recorded yet')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
