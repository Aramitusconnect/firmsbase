<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Filament\Resources\FirmUserResource\Pages\ListFirmUsers;
use App\Filament\Resources\FirmUserResource\Pages\ViewFirmUser;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmUserDirectoryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * FirmUserResource — Phase 1 FirmsVault Admin Control Center. Cross-firm,
 * read-only administrative oversight over `firm_users`.
 *
 * UNLIKE FirmResource, `firm_users` carries permanent FORCE ROW LEVEL
 * SECURITY with no cross-firm-read policy — see
 * PlatformFirmUserDirectoryService's own docblock for the full
 * architectural explanation. That constraint drives two structural
 * differences from the Firm panel's FirmIntegration Resource's plain `->query()`-backed
 * table convention, both mirrored from existing, already-approved
 * precedent elsewhere in this codebase (App\Filament\Pages\
 * PlatformIntegrationOverviewPage's own ->records() closure table):
 *
 *  1. table() uses ->records(closure) — a raw, merged Collection built
 *     by PlatformFirmUserDirectoryService::listAll() (one
 *     runWithFirmContext() call per firm) — never an Eloquent
 *     ->query(), since no single query can read across every firm's
 *     rows under this table's RLS policies.
 *  2. The View page is NOT the standard Filament ViewRecord
 *     (`{record}` route-model-binding by primary key) — a FirmUser row
 *     cannot be looked up by its own uuid alone without already knowing
 *     which firm's context to activate first (confirmed empirically by
 *     the 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy
 *     migration's own docblock: "even a raw DB::table('firm_users')->
 *     count() returns 0 with no context set... regardless of which
 *     columns are filtered on"). The view route therefore carries BOTH
 *     firmUuid and firmUserUuid, exactly mirroring
 *     App\Filament\Pages\PlatformFirmIntegrationDetailPage's own
 *     established `{firmUuid}/{connectionUuid}` composite-route shape.
 */
class FirmUserResource extends Resource
{
    protected static ?string $model = FirmUser::class;

    protected static ?string $slug = 'firm-users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Firm Users';

    protected static ?string $recordTitleAttribute = 'uuid';

    /**
     * Policy-driven (FirmUserPolicy::viewAny(), registered in
     * PlatformAdminPolicyServiceProvider) — see FirmResource::canAccess()
     * for the identical reasoning.
     */
    public static function canAccess(): bool
    {
        return parent::canAccess();
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
                $firmUuid = $filters['firm_uuid']['value'] ?? null;
                $role = $filters['role']['value'] ?? null;
                $status = $filters['status']['value'] ?? null;

                // Narrow the per-firm loop to exactly one firm when a
                // firm filter is applied — the one available
                // optimization against PlatformFirmUserDirectoryService's
                // otherwise O(firm count) read; see that service's own
                // docblock.
                $onlyFirmId = null;

                if (filled($firmUuid)) {
                    $onlyFirmId = Firm::query()->where('uuid', $firmUuid)->value('id');
                }

                $rows = app(PlatformFirmUserDirectoryService::class)->listAll($admin, $onlyFirmId);

                return $rows
                    ->when(filled($role), fn (Collection $r): Collection => $r->where('role', $role))
                    ->when(filled($status), fn (Collection $r): Collection => $r->where('status', $status))
                    ->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('role')
                    ->options(collect(FirmUserRole::cases())
                        ->mapWithKeys(fn (FirmUserRole $role): array => [$role->value => Str::headline($role->value)])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(FirmUserStatus::cases())
                        ->mapWithKeys(fn (FirmUserStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('user_name')->label('Name')->searchable()->placeholder('—'),
                TextColumn::make('user_email')->label('Email')->searchable()->placeholder('—'),
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        'suspended', 'removed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('seat_class')
                    ->label('Seat class')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
                TextColumn::make('invitation_accepted_at')->label('Invitation accepted')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewFirmUser::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'firmUserUuid' => $record['uuid'],
                    ])),
            ])
            ->emptyStateHeading('No firm users found')
            ->defaultSort('firm_name')
            // Disables Filament's default row-click action/url resolution
            // (ListRecords::makeTable()'s own built-in ->recordAction()
            // closure defaults to a `Model $record`-typed closure, which
            // crashes against this table's array-shaped records() rows)
            // — the explicit recordActions() "View" Action above is the
            // only navigation affordance, mirroring
            // PlatformFirmIntegrationDetailPage::table()'s identical
            // ->recordAction(null)->recordUrl(null) combination for the
            // same reason.
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFirmUsers::route('/'),
            'view' => ViewFirmUser::route('/{firmUuid}/{firmUserUuid}'),
        ];
    }
}
