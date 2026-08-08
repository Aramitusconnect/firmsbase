<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Actions;

use App\Enums\FirmUserStatus;
use App\Models\FirmUser;
use App\Services\FirmMembershipAccessPolicyService;
use App\Services\FirmUserInvitationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ReactivateFirmUserAction — a row/header Action, reused identically on
 * FirmUserResource's own table AND ViewFirmUser's header. Only visible
 * on a Suspended row. Wired directly to
 * `FirmUserInvitationService::reactivate()` — never a bare
 * `FirmUser::update()`.
 */
class ReactivateFirmUserAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'reactivateFirmUser';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reactivate');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalHeading('Reactivate team member');
        $this->modalDescription('This restores their access to the firm panel.');
        $this->modalSubmitActionLabel('Reactivate');

        $this->visible(function (FirmUser $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if ($record->status !== FirmUserStatus::Suspended) {
                return false;
            }

            return app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role);
        });

        $this->action(function (FirmUser $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                Notification::make()->title('Could not reactivate team member')->body('This team member could not be found for your firm.')->danger()->send();

                return;
            }

            if (! app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not reactivate team members.')->danger()->send();

                return;
            }

            try {
                app(FirmUserInvitationService::class)->reactivate($record);
            } catch (Throwable $e) {
                report($e);
                Notification::make()->title('Could not reactivate team member')->danger()->send();

                return;
            }

            Notification::make()->title('Team member reactivated')->success()->send();
        });
    }
}
