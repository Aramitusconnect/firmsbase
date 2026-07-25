<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformRoleService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * RevokePlatformAdminRoleAction — Platform Administrators resource.
 * Thin UI layer over the already-existing PlatformRoleService::revoke().
 *
 * Last-SuperAdmin protection: PlatformRoleService::
 * wouldLeaveNoActiveSuperAdmin($target, $role) is checked BEFORE
 * calling revoke() — that method itself short-circuits to false for
 * any role other than SuperAdmin, so this check is cheap and correct
 * to run unconditionally regardless of which role is being revoked
 * here (including the self-revocation case: a sole SuperAdmin
 * attempting to revoke their OWN SuperAdmin role is blocked by the
 * exact same guard, with no separate "is this actor the target"
 * special-casing needed — see that method's own docblock for why).
 */
class RevokePlatformAdminRoleAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'revokePlatformAdminRole';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Revoke Role');
        $this->icon(Heroicon::OutlinedMinusCircle);
        $this->color('danger');

        $this->schema([
            Select::make('role_code')
                ->label('Role')
                ->options(fn (PlatformAdmin $record): array => collect(app(PlatformRoleService::class)->activeRolesFor($record))
                    ->mapWithKeys(fn (PlatformRoleCode $role): array => [$role->value => Str::headline($role->value)])
                    ->all())
                ->required()
                ->native(false),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Revoke role');

        $this->action(function (array $data, PlatformAdmin $record, PlatformStaffAccessPolicyService $accessPolicy, PlatformRoleService $roleService, PlatformAdminAuditEventRecorder $auditRecorder): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageRoles($actor)->allowed) {
                Notification::make()->title('You are not authorized to manage platform administrator roles.')->danger()->send();

                return;
            }

            $target = PlatformAdmin::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That platform administrator could not be found.')->danger()->send();

                return;
            }

            $role = PlatformRoleCode::from((string) $data['role_code']);

            if ($roleService->wouldLeaveNoActiveSuperAdmin($target, $role)) {
                Notification::make()
                    ->title('Cannot revoke this role')
                    ->body('This would leave zero active SuperAdmins. Grant SuperAdmin to another active administrator first.')
                    ->warning()
                    ->send();

                return;
            }

            $roleService->revoke($target, $role);

            $auditRecorder->recordPlatformEvent(
                $actor,
                'platform_admin_role_revoked',
                'platform_admin_management',
                [
                    'target_platform_admin_id' => $target->id,
                    'target_platform_admin_uuid' => $target->uuid,
                    'role_code' => $role->value,
                ],
            );

            Notification::make()->title('Role revoked')->success()->send();
        });
    }
}
