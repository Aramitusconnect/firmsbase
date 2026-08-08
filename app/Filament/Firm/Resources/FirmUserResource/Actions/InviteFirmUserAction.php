<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Actions;

use App\Enums\FirmUserRole;
use App\Exceptions\FirmSeatLimitExceededException;
use App\Services\FirmMembershipAccessPolicyService;
use App\Services\FirmUserInvitationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * InviteFirmUserAction — the product-required "+ Invite Team Member"
 * header action on ListFirmUsers (Firm Feature Manifest §12). Gated
 * FirmOwner-only via `FirmMembershipAccessPolicyService::
 * canManageMembers()` — both `visible()` and, again, inside the
 * `action()` closure itself (defense-in-depth, matching every other
 * Action in this panel, e.g. `AddClientAction`).
 *
 * The role Select offers EXACTLY the 6 real `FirmUserRole` cases —
 * `FirmUserRole::cases()` structurally cannot contain a platform-admin
 * concept (that enum has no such case at all, see its own docblock), so
 * no separate allowlist/denylist is needed here to keep one out.
 *
 * Wired directly to `FirmUserInvitationService::invite()` — never a
 * bare `FirmUser::create()`. `invite()` itself opens its own
 * `TenantContextService::runWithFirmContext()` wrap internally, so this
 * Action does not need (and must not add) a second, redundant wrap
 * around the call.
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

        $this->label('+ Invite Team Member');
        $this->icon(Heroicon::OutlinedUserPlus);
        $this->color('primary');
        $this->modalHeading('Invite Team Member');
        $this->modalDescription('Sends an email invitation. The invitee sets their own password and gains access once they accept.');
        $this->modalSubmitActionLabel('Send Invitation');
        $this->modalWidth('lg');

        $this->schema([
            TextInput::make('name')->label('Full Name')->required()->maxLength(255),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
            Select::make('role')
                ->label('Role')
                ->options(collect(FirmUserRole::cases())->mapWithKeys(fn (FirmUserRole $role): array => [$role->value => Str::headline($role->value)])->all())
                ->required()
                ->native(false),
        ]);

        $this->visible(function (): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role);
        });

        $this->action(function (array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('You do not have access to invite a team member.')->danger()->send();

                return;
            }

            if (! app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not invite team members.')->danger()->send();

                return;
            }

            try {
                app(FirmUserInvitationService::class)->invite(
                    $firmUser->firm,
                    (string) $data['email'],
                    (string) $data['name'],
                    FirmUserRole::from($data['role']),
                    $firmUser->user,
                );
            } catch (FirmSeatLimitExceededException|RuntimeException $e) {
                Notification::make()->title('Could not invite team member')->body($e->getMessage())->danger()->send();

                return;
            } catch (Throwable $e) {
                report($e);
                Notification::make()->title('Could not invite team member')->danger()->send();

                return;
            }

            Notification::make()->title('Invitation sent')->success()->send();
        });
    }
}
