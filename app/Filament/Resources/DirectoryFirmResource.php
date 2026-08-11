<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ActivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\DeactivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\PublishDirectoryFirmAction;
use App\Filament\Actions\Platform\RemoveDirectoryFirmAction;
use App\Filament\Actions\Platform\SuspendDirectoryFirmAction;
use App\Filament\Resources\DirectoryFirmResource\Pages\ListDirectoryFirms;
use App\Filament\Resources\DirectoryFirmResource\Pages\ViewDirectoryFirm;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * DirectoryFirmResource — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 11 (sections 56-58). Cross-firm List+View oversight over
 * `directory_firms`, the GLOBAL marketplace catalog (no firm_id RLS
 * scoping — see that table's own migration docblock). Ordinary
 * Eloquent ->query(), same shape as PlanResource.
 *
 * Reuses the existing Admin Control Center (no new panel) and the
 * existing PlatformStaffAccessPolicyService gating convention — see
 * canManageMarketplaceGovernance()'s own docblock for why this is a
 * single-role-split gate with no separate read-only audience.
 */
class DirectoryFirmResource extends Resource
{
    protected static ?string $model = DirectoryFirm::class;

    protected static ?string $slug = 'directory-firms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Directory Firms';

    protected static string|\UnitEnum|null $navigationGroup = 'MyAttorney Marketplace';

    protected static ?string $recordTitleAttribute = 'display_name';

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
                TextColumn::make('display_name')->label('Firm')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('publication_state')
                    ->badge()
                    ->formatStateUsing(fn (DirectoryPublicationState $state): string => Str::headline($state->value))
                    ->color(fn (DirectoryPublicationState $state): string => match ($state) {
                        DirectoryPublicationState::Published => 'success',
                        DirectoryPublicationState::Draft => 'gray',
                        DirectoryPublicationState::Suspended => 'warning',
                        DirectoryPublicationState::Removed, DirectoryPublicationState::Archived => 'danger',
                    })
                    ->sortable(),
                IconColumn::make('is_claimed')->label('Claimed')->boolean(),
                IconColumn::make('is_marketplace_member')->label('Member')->boolean(),
                IconColumn::make('accepting_inquiries')->label('Accepting inquiries')->boolean(),
                TextColumn::make('completeness_score')->label('Completeness')->sortable(),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('publication_state')
                    ->options(collect(DirectoryPublicationState::cases())->mapWithKeys(fn (DirectoryPublicationState $s) => [$s->value => Str::headline($s->value)])->all()),
                TernaryFilter::make('is_claimed'),
                TernaryFilter::make('is_marketplace_member')->label('Member'),
            ])
            ->recordActions([
                PublishDirectoryFirmAction::make(),
                SuspendDirectoryFirmAction::make(),
                ActivateMarketplaceMembershipAction::make(),
                DeactivateMarketplaceMembershipAction::make(),
                RemoveDirectoryFirmAction::make(),
            ])
            ->emptyStateHeading('No directory firms found')
            ->defaultSort('display_name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectoryFirms::route('/'),
            'view' => ViewDirectoryFirm::route('/{record}'),
        ];
    }
}
