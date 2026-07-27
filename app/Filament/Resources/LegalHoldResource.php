<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Filament\Actions\Platform\ReleaseLegalHoldAction;
use App\Filament\Resources\LegalHoldResource\Pages\ListLegalHolds;
use App\Filament\Resources\LegalHoldResource\Pages\ViewLegalHold;
use App\Models\Firm;
use App\Models\LegalHold;
use App\Models\PlatformAdmin;
use App\Services\PlatformLegalHoldDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * LegalHoldResource — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category. Cross-firm List+View over `legal_holds` — the
 * most CRUD-complete, lowest-risk mutation candidate among the
 * Governance modules (see the Phase 4 architecture map §B.3).
 *
 * Read gate: canAccessGovernance(). Mutating gate (Place/Release):
 * canManageLegalHolds() — a NEW gate this phase adds, since
 * LegalHoldService::place()/release() carry no authorization of their
 * own (see that service's own docblock).
 *
 * FORCE RLS, firm-scoped only — queried exclusively via
 * PlatformLegalHoldDirectoryService's per-firm-loop pattern.
 */
class LegalHoldResource extends Resource
{
    protected static ?string $model = LegalHold::class;

    protected static ?string $slug = 'legal-holds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Legal Holds';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessGovernance($admin)->allowed;
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

                return app(PlatformLegalHoldDirectoryService::class)->list($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                    'scope_type' => $filters['scope_type']['value'] ?? null,
                ])->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('status')
                    ->options(collect(LegalHoldStatus::cases())
                        ->mapWithKeys(fn (LegalHoldStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                SelectFilter::make('scope_type')
                    ->label('Scope')
                    ->options(collect(LegalHoldScope::cases())
                        ->mapWithKeys(fn (LegalHoldScope $scope): array => [$scope->value => Str::headline($scope->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('scope_type')->label('Scope')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('reason')->label('Reason')->limit(60),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state): string => $state === LegalHoldStatus::Active->value ? 'danger' : 'success')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('placed_at')->label('Placed at')->dateTime(),
                TextColumn::make('released_at')->label('Released at')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                ReleaseLegalHoldAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewLegalHold::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No legal holds found')
            ->defaultSort('placed_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalHolds::route('/'),
            'view' => ViewLegalHold::route('/{firmUuid}/{id}'),
        ];
    }
}
