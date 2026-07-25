<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformAdministratorResource\Pages;

use App\Filament\Actions\Platform\AssignPlatformAdminRoleAction;
use App\Filament\Actions\Platform\ResetPlatformAdminMfaAction;
use App\Filament\Actions\Platform\RevokePlatformAdminRoleAction;
use App\Filament\Actions\Platform\TogglePlatformAdminActiveStatusAction;
use App\Filament\Resources\PlatformAdministratorResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewPlatformAdministrator — the standard Filament ViewRecord page
 * (unlike ViewFirmUser, platform_admins is not RLS-scoped, so ordinary
 * {record} route-model-binding by uuid works with no special handling
 * — see PlatformAdministratorResource's own docblock).
 *
 * Every mutating Action for this resource lives here as a header
 * action, per-record — never on the List page (see
 * ListPlatformAdministrators). All four Action classes are
 * type-hinted against `PlatformAdmin $record` in their own `action()`
 * closures; Filament resolves that parameter automatically from this
 * page's current record.
 *
 * "Require re-enrollment" is deliberately NOT a separate header action
 * — ResetPlatformAdminMfaAction serves both purposes (see that class's
 * own docblock for the design proposal's §6 finding that these are the
 * same underlying mechanism).
 *
 * "Revoke active sessions" is deliberately NOT a separate header
 * action either — see PlatformAdministratorResource's own docblock
 * section (below) for the reasoning, mirrored here since this is where
 * a user would otherwise look for it:
 *
 * This codebase has no existing session-revocation primitive for the
 * `platform_admin` guard (SESSION_DRIVER=database, but nothing sets
 * `session.guard`, so the `sessions` table's `user_id` column is not
 * reliably populated from PlatformAdmin authentication — building a
 * genuinely separate, reliable session-invalidation mechanism would
 * need to resolve that ambiguity first, which is its own,
 * separately-reviewable change). ResetPlatformAdminMfaAction's
 * two_factor_reset_at stamp, combined with
 * EnsurePlatformAdminMfaIsEnrolledAndVerified's step 5, already forces
 * any active session for the target to be logged out on its very next
 * request — judged sufficient for the "administrator's device/account
 * is compromised" scenario this action category exists for. The one
 * gap this leaves, disclosed rather than hidden: an admin whose
 * PASSWORD alone is compromised but whose MFA is intact has no
 * dedicated "kick their session without touching MFA" action in this
 * checkpoint — the operator's fallback today is the same as it was
 * before this checkpoint existed (there is no admin-initiated
 * password-reset-for-another-admin flow either), unchanged scope.
 */
class ViewPlatformAdministrator extends ViewRecord
{
    protected static string $resource = PlatformAdministratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TogglePlatformAdminActiveStatusAction::make(),
            AssignPlatformAdminRoleAction::make(),
            RevokePlatformAdminRoleAction::make(),
            ResetPlatformAdminMfaAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Platform Administrator')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('roles')
                        ->label('Roles')
                        ->state(fn ($record): array => $record->roles()
                            ->whereNull('revoked_at')
                            ->get()
                            ->pluck('role_code')
                            ->map(fn ($role): string => Str::headline($role->value))
                            ->all())
                        ->badge()
                        ->placeholder('No roles assigned'),
                    TextEntry::make('two_factor_confirmed_at')
                        ->label('MFA status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Enrolled' : 'Not enrolled')
                        ->color(fn (?string $state): string => filled($state) ? 'success' : 'danger'),
                    TextEntry::make('two_factor_reset_at')
                        ->label('Last MFA reset')
                        ->dateTime()
                        ->placeholder('Never'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                ]),
        ]);
    }
}
