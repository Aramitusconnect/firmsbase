<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlatformRoleCode;
use App\Filament\Support\StepUp\StepUpAuthentication;
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
 * AssignPlatformAdminRoleAction — Platform Administrators resource.
 * Thin UI layer over the already-existing PlatformRoleService::grant()
 * — no role-grant business logic lives here. Offers only roles the
 * target does not already actively hold (grant() is itself idempotent,
 * but narrowing the Select avoids a confusing no-op submission).
 *
 * CORE SuperAdmin mission: granting a role — especially SuperAdmin — is
 * a privileged capability change, so this now requires a fresh step-up
 * verification via StepUpAuthentication::mergeInto() (appends the
 * password field to the existing role_code schema rather than
 * replacing it), matching section 29's "fresh MFA/re-auth where
 * supported/required" requirement for role grants.
 */
class AssignPlatformAdminRoleAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'assignPlatformAdminRole';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Assign Role');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        StepUpAuthentication::mergeInto($this, [
            Select::make('role_code')
                ->label('Role')
                ->options(fn (PlatformAdmin $record): array => collect(PlatformRoleCode::cases())
                    ->reject(fn (PlatformRoleCode $role): bool => in_array($role, app(PlatformRoleService::class)->activeRolesFor($record), true))
                    ->mapWithKeys(fn (PlatformRoleCode $role): array => [$role->value => Str::headline($role->value)])
                    ->all())
                ->required()
                ->native(false),
        ], 'platform_admin');

        $this->modalHeading('Assign role');

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

            $roleService->grant($target, $role, $actor);

            $auditRecorder->recordPlatformEvent(
                $actor,
                'platform_admin_role_granted',
                'platform_admin_management',
                [
                    'target_platform_admin_id' => $target->id,
                    'target_platform_admin_uuid' => $target->uuid,
                    'role_code' => $role->value,
                ],
            );

            Notification::make()->title('Role assigned')->success()->send();
        });
    }
}
