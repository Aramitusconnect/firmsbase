<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Resources\PlatformIncidentResource\Pages\ListPlatformIncidents;
use App\Filament\Resources\PlatformIncidentResource\Pages\ViewPlatformIncident;
use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformIncidentResource — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). `incident_events` is append-only and
 * event-sourced (no separate "incidents" parent table — see that
 * model's own docblock): "current state" for a given correlation_id is
 * always the latest row, "timeline" is every row in order. This
 * Resource's list/view "current state" query selects exactly the
 * latest row per correlation_id via a MAX(id)-per-correlation_id
 * subquery — each resulting row is a genuine IncidentEvent with a real
 * bigint id, so ordinary {record} route-model-binding by id works with
 * no special handling.
 *
 * Deliberately scoped to PLATFORM-WIDE incidents only (firm_id IS
 * NULL) — the majority expected use case per
 * phase4-architecture-map-operations-governance.md §A.6 ("platform-
 * wide incident rows need no per-firm loop at all... only firm-
 * specific incident rows would need the per-firm-loop pattern for a
 * genuine cross-firm view"). Firm-specific incidents (e.g. an
 * escalated tenant-isolation anomaly) are intentionally out of this
 * pass's scope, matching that architecture note's own recommendation
 * not to force a per-firm loop where the read policy already makes the
 * platform-wide case simple. `incident_events` carries FORCE ROW LEVEL
 * SECURITY with the "nullable-firm_id, universal read" shape — firm_id
 * IS NULL rows are visible under the read policy regardless of active
 * tenant context, so this Resource's plain query needs no context
 * wrap.
 *
 * The FULL lifecycle is exposed: OpenIncidentAction (header action on
 * the List page) plus UpdateIncidentSeverityAction/
 * UpdateIncidentStatusAction/RecordIncidentRootCauseAction/
 * FlagIncidentCustomerImpactAction/FlagIncidentNotificationNeededAction/
 * ResolveIncidentAction (header actions on the View page) — the
 * strongest mutation candidate in this whole phase's scope, per that
 * same architecture-map section.
 */
class PlatformIncidentResource extends Resource
{
    protected static ?string $model = IncidentEvent::class;

    protected static ?string $slug = 'platform-incidents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Incidents';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 85;

    protected static ?string $recordTitleAttribute = 'correlation_id';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    /**
     * "Current state" query — the latest incident_events row for each
     * distinct platform-wide correlation_id.
     */
    public static function currentStateQuery(): Builder
    {
        return IncidentEvent::query()
            ->whereNull('firm_id')
            ->whereIn('id', function ($query): void {
                $query->selectRaw('MAX(id)')
                    ->from('incident_events')
                    ->whereNull('firm_id')
                    ->groupBy('correlation_id');
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::currentStateQuery())
            ->filters([
                SelectFilter::make('severity')
                    ->options(collect(IncidentSeverity::cases())
                        ->mapWithKeys(fn (IncidentSeverity $s): array => [$s->value => Str::headline($s->value)])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(IncidentStatus::cases())
                        ->mapWithKeys(fn (IncidentStatus $s): array => [$s->value => Str::headline($s->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('correlation_id')->label('Incident')->limit(12)->fontFamily('mono'),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (IncidentSeverity $state): string => Str::headline($state->value))
                    ->color(fn (IncidentSeverity $state): string => match ($state) {
                        IncidentSeverity::Critical => 'danger',
                        IncidentSeverity::High => 'warning',
                        IncidentSeverity::Medium => 'info',
                        IncidentSeverity::Low => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (IncidentStatus $state): string => Str::headline($state->value))
                    ->color(fn (IncidentStatus $state): string => $state === IncidentStatus::Resolved ? 'success' : 'warning')
                    ->sortable(),
                TextColumn::make('message')->label('Description')->limit(60)->placeholder('—'),
                IconColumn::make('customer_impact')->label('Customer impact')->boolean(),
                IconColumn::make('notification_needed')->label('Notification needed')->boolean(),
                TextColumn::make('created_at')->label('Last updated')->dateTime()->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No platform-wide incidents recorded yet')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformIncidents::route('/'),
            'view' => ViewPlatformIncident::route('/{record}'),
        ];
    }
}
