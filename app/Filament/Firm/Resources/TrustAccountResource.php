<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\TrustAccountStatus;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\CloseTrustAccountAction;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\SuspendTrustAccountAction;
use App\Filament\Firm\Resources\TrustAccountResource\Pages\ListTrustAccounts;
use App\Filament\Firm\Resources\TrustAccountResource\Pages\ViewTrustAccount;
use App\Filament\Firm\Resources\TrustAccountResource\RelationManagers\LedgersRelationManager;
use App\Filament\Firm\Resources\TrustAccountResource\RelationManagers\ReconciliationsRelationManager;
use App\Models\TrustAccount;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * TrustAccountResource — Firm Feature Manifest §7 (Trust/IOLTA). The
 * first Filament UI of any kind for this domain — no prior Trust
 * precedent exists in this codebase, so this class follows
 * Payment/InvoiceResource's own established "List + View pages ONLY,
 * Action-based mutation" shape instead (see this module's own
 * governing rule #3: "every mutation must be a Filament Action calling
 * exactly one Trust*Service method — never a CreateRecord/EditRecord
 * page bound to model fields").
 *
 * No `form()` method exists on this Resource at all. Open/Suspend/Close
 * are dedicated Actions in TrustAccountResource\Actions\*, each calling
 * exactly one TrustAccountService method.
 *
 * Entitlement/eligibility gating (rule #4 — "Trust-mode eligibility
 * gates ALL visibility, not just actions"): `canAccess()`/
 * `shouldRegisterNavigation()` both additionally require
 * TrustEligibilityService::isEligible($firm) — mirrors
 * FirmIntegrationResource's own "hide the feature ENTIRELY for a
 * disentitled firm, never merely grey it out" ruling, here for trust
 * mode instead of a paid integration entitlement. This is the ONE
 * gate that hides the entire "Trust Accounting" nav group — the other
 * two Resources in this module use the exact same pattern.
 *
 * `TrustAccountPolicy` (viewAny()/view() only) covers role authority
 * via Laravel's standard policy mechanism; `isFirmTrustEligible()`
 * below is a separate, UX-layer, non-throwing check layered on top,
 * identical in spirit to FirmIntegrationResource's `isFirmEntitled()`.
 * Neither substitutes for the REAL boundary, which is every mutating
 * Trust*Service method's own `TrustEligibilityService::assertEligible()`
 * call, re-checked unconditionally inside each Action's own closure.
 */
class TrustAccountResource extends Resource
{
    protected static ?string $model = TrustAccount::class;

    protected static ?string $slug = 'trust-accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Trust Accounts';

    protected static string|\UnitEnum|null $navigationGroup = 'Trust Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'account_name';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmTrustEligible();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmTrustEligible();
    }

    private static function isFirmTrustEligible(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(TrustEligibilityService::class)->isEligible($firmUser->firm)
            && app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_name')->label('Account Name')->searchable(),
                TextColumn::make('bank_name_reference')->label('Bank Reference')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('opened_at')->label('Opened')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(TrustAccountStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
            ])
            ->recordActions([
                SuspendTrustAccountAction::make(),
                CloseTrustAccountAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LedgersRelationManager::class,
            ReconciliationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrustAccounts::route('/'),
            'view' => ViewTrustAccount::route('/{record}'),
        ];
    }
}
