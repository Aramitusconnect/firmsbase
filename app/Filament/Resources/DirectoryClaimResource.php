<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\ApproveDirectoryClaimAction;
use App\Filament\Actions\Platform\MarkClaimUnderReviewAction;
use App\Filament\Actions\Platform\RejectDirectoryClaimAction;
use App\Filament\Actions\Platform\RequireClaimEvidenceAction;
use App\Filament\Actions\Platform\RevokeDirectoryClaimAction;
use App\Filament\Resources\DirectoryClaimResource\Pages\ListDirectoryClaims;
use App\Filament\Resources\DirectoryClaimResource\Pages\ViewDirectoryClaim;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
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
 * DirectoryClaimResource — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 11. Cross-firm List+View oversight over `directory_claims`
 * — Global (RLS-exempt) despite carrying a real non-nullable firm_id,
 * exactly as that table's own migration docblock explains.
 */
class DirectoryClaimResource extends Resource
{
    protected static ?string $model = DirectoryClaim::class;

    protected static ?string $slug = 'directory-claims';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Claims';

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
                TextColumn::make('id')->label('Claim ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('directoryFirm.display_name')->label('Listing')->searchable(),
                TextColumn::make('firm.legal_name')->label('Claimant Firm')->searchable(),
                TextColumn::make('claimant.user.name')->label('Claimant')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (ClaimState $state): string => Str::headline($state->value))
                    ->color(fn (ClaimState $state): string => match ($state) {
                        ClaimState::Approved => 'success',
                        ClaimState::Rejected, ClaimState::Revoked, ClaimState::Expired => 'danger',
                        ClaimState::Disputed => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('submitted_at')->label('Age')->since()->sortable(),
                TextColumn::make('updated_at')->label('Last updated')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decided_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(collect(ClaimState::cases())->mapWithKeys(fn (ClaimState $s) => [$s->value => Str::headline($s->value)])->all()),
            ])
            ->recordActions([
                MarkClaimUnderReviewAction::make(),
                RequireClaimEvidenceAction::make(),
                ApproveDirectoryClaimAction::make(),
                RejectDirectoryClaimAction::make(),
                RevokeDirectoryClaimAction::make(),
            ])
            ->emptyStateHeading('No claims to show')
            ->emptyStateDescription('No ownership claims match the current filters, or the queue is fully caught up.')
            ->defaultSort('submitted_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectoryClaims::route('/'),
            'view' => ViewDirectoryClaim::route('/{record}'),
        ];
    }
}
