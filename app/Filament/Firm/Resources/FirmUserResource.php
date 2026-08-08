<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Filament\Firm\Resources\FirmUserResource\Actions\ReactivateFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\RemoveFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\SuspendFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Pages\ListFirmUsers;
use App\Filament\Firm\Resources\FirmUserResource\Pages\ViewFirmUser;
use App\Models\FirmUser;
use App\Services\FirmMembershipAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * FirmUserResource — Firm Feature Manifest §12 ("Firm Team / Access").
 * The firm-facing counterpart to the platform-admin-only, List+View-only
 * `App\Filament\Resources\FirmUserResource` (a DIFFERENT class, under a
 * DIFFERENT namespace — that one is never touched by this feature). This
 * one runs inside the already-active tenant context a normal firm-panel
 * page load already establishes (`EstablishFirmTenantContext` +
 * `ApplyTenantDatabaseContext`, wired into `FirmPanelProvider`'s
 * `authMiddleware`), so — unlike the platform-admin resource, which must
 * read cross-firm and therefore cannot use a plain Eloquent query at all
 * — this one uses a completely ordinary `static::getEloquentQuery()`
 * (not overridden, matching `ClientResource`'s/`DocumentChaseRuleResource`'s
 * own "no override needed" precedent): `FirmUser`'s `BelongsToTenant`
 * global scope plus `firm_users`' own FORCE ROW LEVEL SECURITY already
 * confine every query — list, filter, search, and a direct `/{record}`
 * URL guess — to exactly the acting user's own firm. A foreign firm's
 * FirmUser rows are structurally invisible, never merely hidden.
 *
 * AUTHORIZATION — deliberately bypasses Laravel's/Filament's default
 * Gate-based policy resolution entirely (`canAccess()`/`canViewAny()`/
 * `canView()` below are hard overrides, never delegating to
 * `parent::`): `App\Models\FirmUser` already has an explicit, GLOBAL
 * `Gate::policy(FirmUser::class, FirmUserPolicy::class)` registration
 * (`PlatformAdminPolicyServiceProvider`) whose methods are strictly
 * typed to `App\Models\PlatformAdmin` — calling `Gate::authorize()`/
 * `$user->can()` against a `FirmUser` instance from this, `web`-guard
 * panel would resolve to THAT policy and fatal with a `TypeError` the
 * instant a real `App\Models\User` actor reached it (confirmed via
 * direct read of `Illuminate\Auth\Access\Gate::canBeCalledWithUser()` —
 * it only special-cases a NULL/guest user, never a type mismatch). Both
 * `FirmPolicy`'s and `FirmUserPolicy`'s own docblocks already flagged
 * this exact "future firm-panel use case" as an open hazard — this is
 * that future use case, resolved by never calling Gate for this model
 * at all. Every check instead goes through
 * `FirmMembershipAccessPolicyService` directly — see that service's own
 * docblock for the full reasoning and the role-ceiling decision
 * (FirmOwner-only for invite/suspend/reactivate/remove; every active
 * role may view the roster).
 *
 * NO GENERIC EDIT/CREATE PAGE — matching this mission's established
 * "action-based mutation for anything with lifecycle semantics"
 * convention (`CommunicationConsentResource`/`PaymentResource`'s own
 * "Action-based, never Form-backed Create/Edit" ruling): List + View
 * only. "Invite Team Member" is `InviteFirmUserAction` (a header Action
 * on `ListFirmUsers` calling `FirmUserInvitationService::invite()`);
 * role/status changes are `SuspendFirmUserAction`/
 * `ReactivateFirmUserAction`/`RemoveFirmUserAction` (row Actions calling
 * the same service's `suspend()`/`reactivate()`/`remove()`) — never a
 * raw `FirmUser::update()` reachable from a generic form.
 */
class FirmUserResource extends Resource
{
    protected static ?string $model = FirmUser::class;

    protected static ?string $slug = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Team';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmMembershipAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        if (! $record instanceof FirmUser) {
            return false;
        }

        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null
            && (int) $firmUser->firm_id === (int) $record->firm_id
            && app(FirmMembershipAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? Str::headline($state->value) : Str::headline((string) $state)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? Str::headline($state->value) : Str::headline((string) $state))
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        'suspended', 'removed' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_primary')->label('Primary')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invitation_accepted_at')->label('Joined')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Invited On')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')
                    ->options(collect(FirmUserRole::cases())->mapWithKeys(fn (FirmUserRole $role): array => [$role->value => Str::headline($role->value)])->all()),
                SelectFilter::make('status')
                    ->options(collect(FirmUserStatus::cases())->mapWithKeys(fn (FirmUserStatus $status): array => [$status->value => Str::headline($status->value)])->all()),
            ])
            ->recordActions([
                ReactivateFirmUserAction::make(),
                SuspendFirmUserAction::make(),
                RemoveFirmUserAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFirmUsers::route('/'),
            'view' => ViewFirmUser::route('/{record}'),
        ];
    }
}
