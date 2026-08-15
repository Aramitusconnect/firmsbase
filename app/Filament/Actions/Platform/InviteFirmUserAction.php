<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\FirmUserRole;
use App\Exceptions\FirmSeatLimitExceededException;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\FirmUserInvitationService;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * InviteFirmUserAction — CORE SuperAdmin mission, section 22.
 * FirmUserResource had no invitation capability at all in the admin
 * panel before this mission (List+View only, by design). Thin UI layer
 * over the already-existing FirmUserInvitationService::invite() — no
 * new invitation/seat/last-owner logic lives here; that service already
 * enforces seat capacity (FirmSeatLimitExceededException) and creates
 * the invited User the identical way FirmProvisioningService creates a
 * new owner (never a SuperAdmin-chosen password — see that service's
 * own docblock).
 *
 * A header action on ListFirmUsers rather than a full Filament
 * CreateRecord page: FirmUserResource's own table is backed by
 * ->records() (a merged cross-firm Collection, not an Eloquent query —
 * see that Resource's own docblock for why firm_users' FORCE RLS
 * requires this), so the standard CreateRecord page shape does not
 * apply here; a modal Action calling the canonical service directly is
 * the correct fit, mirroring every other mutation in this panel.
 */
class InviteFirmUserAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'inviteFirmUser';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Invite User');
        $this->icon(Heroicon::OutlinedUserPlus);
        $this->color('primary');

        $this->schema([
            Select::make('firm_id')
                ->label('Firm')
                ->searchable()
                ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255),
            Select::make('role')
                ->options(collect(FirmUserRole::cases())
                    ->mapWithKeys(fn (FirmUserRole $role): array => [$role->value => Str::headline($role->value)])
                    ->all())
                ->required()
                ->native(false),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Invite a firm user');
        $this->modalDescription('Sends an invitation email through the same flow a Firm Owner uses to invite team members. The invitee sets their own password.');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, FirmUserInvitationService $invitationService, PlatformAdminAuditEventRecorder $auditRecorder): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageFirms($actor)->allowed) {
                Notification::make()->title('You are not authorized to manage firm users.')->danger()->send();

                return;
            }

            $firm = Firm::query()->find($data['firm_id']);

            if ($firm === null) {
                Notification::make()->title('That firm could not be found.')->danger()->send();

                return;
            }

            try {
                $firmUser = $invitationService->invite(
                    $firm,
                    (string) $data['email'],
                    (string) $data['name'],
                    FirmUserRole::from((string) $data['role']),
                    // No User to attribute this to — the actor is a
                    // PlatformAdmin, not a firm_users-linked User (see
                    // FirmUserInvitationService::invite()'s own
                    // docblock). The PlatformAdmin actor is recorded in
                    // full by this action's own audit event below.
                    null,
                );
            } catch (FirmSeatLimitExceededException $e) {
                Notification::make()->title('No remaining licensed seat for this firm')->body($e->getMessage())->danger()->send();

                return;
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not invite this user')->body($e->getMessage())->danger()->send();

                return;
            }

            $auditRecorder->record($firm, $actor, 'firm_user_invited_by_platform_admin', 'platform_administration', [
                'firm_user_id' => $firmUser->id,
                'firm_user_uuid' => $firmUser->uuid,
                'role' => $firmUser->role->value,
            ]);

            Notification::make()->title('Invitation sent')->success()->send();
        });
    }
}
