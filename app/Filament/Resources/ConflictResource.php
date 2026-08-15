<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ConflictResource\Pages\ListConflicts;
use App\Filament\Resources\ConflictResource\Pages\ViewConflict;
use App\Integrations\Enums\ConflictStatus;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * ConflictResource — Phase 2 (FirmsVault Platform Admin Control Center,
 * "Integration Operations Center"). Global, cross-firm MONITORING-ONLY
 * view of `integration_conflicts` rows.
 *
 * THIS IS AN EXPLICIT, CONFIRMED HUMAN DECISION, NOT AN OVERSIGHT:
 * Conflicts stays permanently read-only for PlatformAdmin.
 * `IntegrationConflictService::transitionStatus()`/`proposeResolution()`
 * require two distinct, real FirmUser actors for dual-approval (see
 * IntegrationConflict's own class docblock and the `integration_conflicts`
 * migration's `privileged_resource_dual_approval`/`flagged_dual_approval`
 * CHECK constraints) — an Admin console has no second, independent
 * FirmUser identity to supply and structurally cannot satisfy that
 * invariant. Nothing in this class, its Pages, or
 * PlatformIntegrationCrossFirmDirectoryService ever calls
 * transitionStatus()/proposeResolution(), imports
 * IntegrationConflictService, or registers ANY Filament Action —
 * mutating or otherwise — against a conflict record. This resource has
 * List+View pages ONLY.
 *
 * `local_value`/`external_value`/`resolution_note`/
 * `resolved_by_firm_user_id`/`resolution_approved_by_firm_user_id` are
 * never selected anywhere in the read path behind this resource (see
 * PlatformIntegrationCrossFirmDirectoryService::CONFLICT_COLUMNS — an
 * explicit SQL column allowlist) — "involved entities" here means only
 * a safely-summarized `local_type #local_id` pointer, never a raw
 * before/after value dump.
 */
class ConflictResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = IntegrationConflict::class;

    protected static ?string $slug = 'conflicts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    /**
     * Naming (§18/§137): "Integration Conflicts" — "Conflicts" alone
     * collides with the unrelated legal conflict-of-interest checking
     * this platform also has (App\Services\ConflictCheckService), which
     * is a materially different thing for an operator to be looking at.
     */
    protected static ?string $navigationLabel = 'Integration Conflicts';

    protected static ?string $modelLabel = 'Integration Conflict';

    protected static ?string $pluralModelLabel = 'Integration Conflicts';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 23;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];

                $rows = app(PlatformIntegrationCrossFirmDirectoryService::class)->listConflicts($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'provider_code' => $filters['provider_code']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                    'from' => $filters['date_range']['from'] ?? null,
                    'to' => $filters['date_range']['to'] ?? null,
                ]);

                return $rows->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('provider_code')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationProvider::query()->orderBy('display_name')->pluck('display_name', 'code')->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ConflictStatus::cases())
                        ->mapWithKeys(fn (ConflictStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                Filter::make('date_range')
                    ->label('Detected between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('provider_display_name')->label('Provider')->placeholder('—'),
                TextColumn::make('conflict_type')->label('Conflict type'),
                TextColumn::make('resource_type')->label('Resource type'),
                TextColumn::make('involved_entity')->label('Involved entity')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'detected', 'awaiting_review' => 'warning',
                        'expired' => 'gray',
                        default => 'success',
                    }),
                IconColumn::make('requires_manual_review')->label('Manual review')->boolean(),
                TextColumn::make('detected_at')->label('Detected at')->dateTime(),
                TextColumn::make('resolved_at')->label('Resolved at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewConflict::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No conflicts found')
            ->emptyStateDescription('This is a monitoring-only view. Conflict resolution happens exclusively through the normal FirmUser dual-approval workflow inside the firm panel — never from this console.')
            ->defaultSort('detected_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConflicts::route('/'),
            'view' => ViewConflict::route('/{firmUuid}/{id}'),
        ];
    }
}
