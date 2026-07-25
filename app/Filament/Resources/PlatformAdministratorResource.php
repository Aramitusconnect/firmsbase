<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformAdministratorResource\Pages\ListPlatformAdministrators;
use App\Filament\Resources\PlatformAdministratorResource\Pages\ViewPlatformAdministrator;
use App\Models\PlatformAdmin;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PlatformAdministratorResource — FirmsVault Admin Control Center.
 * Cross-cutting management of the `platform_admins` identity table
 * itself (roles, activation status, MFA), mirroring FirmResource/
 * FirmUserResource's established conventions (layered canAccess(),
 * List+View pages, no Create/Edit form — mutations exclusively via
 * Filament\Actions\Action subclasses under app/Filament/Actions/Platform/).
 *
 * No Create page: nothing in this codebase creates platform_admins rows
 * today (no self-registration — AdminPanelProvider never calls
 * ->registration() — and no existing invite/create flow anywhere), so
 * there is no established pattern to extend here and building one is
 * out of this checkpoint's scope. Administrator accounts remain an
 * out-of-band/ops-provisioned concern (seeder/tinker), unchanged by
 * this resource.
 *
 * `platform_admins` is not tenant-owned (see that model's own
 * docblock) — an ordinary Eloquent ->query() table is correct here,
 * unlike FirmUserResource's ->records() closure workaround for
 * firm_users' FORCE RLS.
 *
 * Gate: App\Policies\PlatformAdminPolicy (registered in
 * PlatformAdminPolicyServiceProvider), delegating to
 * PlatformStaffAccessPolicyService::canManagePlatformAdministrators()
 * — SuperAdmin only, for BOTH viewing and mutating (see that policy's
 * own docblock for why this resource has no broader read-only ceiling
 * the way Firms/Firm Users do).
 *
 * "Last login" column: platform_admins carries no last_login_at (or
 * equivalent) column — confirmed by reading its migration directly,
 * not assumed. A real signal DOES exist (AppServiceProvider's
 * platform_admin Login-event listener writes a 'login_succeeded'
 * security_events row on every successful login), so rather than
 * fabricating the column or omitting the signal entirely, this
 * Resource derives it with exactly ONE extra batched query (never
 * per-row — see getLastLoginAtByAdminId()) run under
 * TenantContextService::runWithoutFirmContext() (security_events'
 * null-firm_id rows, which is what these are, are only readable with
 * no tenant context active — see PlatformAdminAuditEventRecorder::
 * recordPlatformEvent()'s own docblock for the same constraint on the
 * write side).
 */
class PlatformAdministratorResource extends Resource
{
    protected static ?string $model = PlatformAdmin::class;

    protected static ?string $slug = 'platform-administrators';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Platform Administrators';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Policy-driven (PlatformAdminPolicy::viewAny()) — see FirmResource::
     * canAccess() for the identical reasoning.
     */
    public static function canAccess(): bool
    {
        return parent::canAccess();
    }

    public static function table(Table $table): Table
    {
        $lastLoginAtByAdminId = static::lastLoginAtByAdminId();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['roles' => fn ($q) => $q->whereNull('revoked_at')]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->state(fn (PlatformAdmin $record): array => $record->roles
                        ->pluck('role_code')
                        ->map(fn ($role): string => Str::headline($role->value))
                        ->all())
                    ->badge()
                    ->separator(','),
                TextColumn::make('two_factor_confirmed_at')
                    ->label('MFA status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Enrolled' : 'Not enrolled')
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'danger'),
                TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->state(fn (PlatformAdmin $record) => $lastLoginAtByAdminId->get($record->id))
                    ->dateTime()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformAdministrators::route('/'),
            'view' => ViewPlatformAdministrator::route('/{record}'),
        ];
    }

    /**
     * One batched query for the whole table — never per-row — under
     * TenantContextService::runWithoutFirmContext() (see this class's
     * own docblock). Returns actor_id => latest created_at.
     *
     * @return Collection<int, string>
     */
    private static function lastLoginAtByAdminId(): Collection
    {
        return app(TenantContextService::class)->runWithoutFirmContext(
            fn (): Collection => DB::table('security_events')
                ->where('actor_type', PlatformAdmin::class)
                ->where('event_type', 'login_succeeded')
                ->selectRaw('actor_id, MAX(created_at) as last_login_at')
                ->groupBy('actor_id')
                ->pluck('last_login_at', 'actor_id')
        );
    }
}
