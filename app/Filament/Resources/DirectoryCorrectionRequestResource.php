<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ApproveCorrectionRequestAction;
use App\Filament\Actions\Platform\RejectCorrectionRequestAction;
use App\Filament\Actions\Platform\ResolveCorrectionRequestAction;
use App\Filament\Resources\DirectoryCorrectionRequestResource\Pages\ListDirectoryCorrectionRequests;
use App\Filament\Resources\DirectoryCorrectionRequestResource\Pages\ViewDirectoryCorrectionRequest;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * DirectoryCorrectionRequestResource — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 11 (section 51). Cross-firm List+View
 * oversight over `directory_correction_requests` — Global, submittable
 * by an unauthenticated public visitor as well as an authenticated
 * FirmUser (see that table's own migration docblock).
 */
class DirectoryCorrectionRequestResource extends Resource
{
    protected static ?string $model = DirectoryCorrectionRequest::class;

    protected static ?string $slug = 'directory-correction-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Corrections & Removals';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

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

        return app(PlatformStaffAccessPolicyService::class)->canManageMarketplaceGovernance($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('directoryFirm.display_name')->label('Listing')->searchable(),
                TextColumn::make('correction_type')
                    ->label('Type')
                    ->formatStateUsing(fn (CorrectionType $state): string => $state->label())
                    ->badge(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (CorrectionState $state): string => Str::headline($state->value))
                    ->color(fn (CorrectionState $state): string => match ($state) {
                        CorrectionState::Resolved => 'success',
                        CorrectionState::Rejected => 'danger',
                        CorrectionState::Approved => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('reporter_name')->label('Reporter')->placeholder('Anonymous'),
                TextColumn::make('created_at')->label('Reported')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(CorrectionState::cases())->mapWithKeys(fn (CorrectionState $s) => [$s->value => Str::headline($s->value)])->all()),
                SelectFilter::make('correction_type')
                    ->label('Type')
                    ->options(collect(CorrectionType::cases())->mapWithKeys(fn (CorrectionType $t) => [$t->value => $t->label()])->all()),
            ])
            ->recordActions([
                ApproveCorrectionRequestAction::make(),
                RejectCorrectionRequestAction::make(),
                ResolveCorrectionRequestAction::make(),
            ])
            ->emptyStateHeading('No correction requests found')
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectoryCorrectionRequests::route('/'),
            'view' => ViewDirectoryCorrectionRequest::route('/{record}'),
        ];
    }
}
