<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Actions;

use App\Enums\FirmUserStatus;
use App\Exceptions\LastFirmOwnerRemovalException;
use App\Models\FirmUser;
use App\Services\FirmMembershipAccessPolicyService;
use App\Services\FirmUserInvitationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * SuspendFirmUserAction — a row/header Action, reused identically on
 * FirmUserResource's own table AND ViewFirmUser's header (same shared
 * class, matching RevokeConsentAction's "one Action class, several
 * tables" precedent). Only visible on an Active row. Wired directly to
 * `FirmUserInvitationService::suspend()` — which itself enforces the
 * "never suspend the last remaining active owner" guard — never a bare
 * `FirmUser::update()`.
 */
class SuspendFirmUserAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'suspendFirmUser';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Suspend');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Suspend team member');
        $this->modalDescription('This immediately revokes their access to the firm panel. They can be reactivated later.');
        $this->modalSubmitActionLabel('Suspend');

        $this->visible(function (FirmUser $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if ($record->status !== FirmUserStatus::Active) {
                return false;
            }

            return app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role);
        });

        $this->action(function (FirmUser $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                Notification::make()->title('Could not suspend team member')->body('This team member could not be found for your firm.')->danger()->send();

                return;
            }

            if (! app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not suspend team members.')->danger()->send();

                return;
            }

            try {
                app(FirmUserInvitationService::class)->suspend($record);
            } catch (LastFirmOwnerRemovalException $e) {
                Notification::make()->title('Could not suspend team member')->body($e->getMessage())->danger()->send();

                return;
            } catch (Throwable $e) {
                report($e);
                Notification::make()->title('Could not suspend team member')->danger()->send();

                return;
            }

            Notification::make()->title('Team member suspended')->success()->send();
        });
    }
}
