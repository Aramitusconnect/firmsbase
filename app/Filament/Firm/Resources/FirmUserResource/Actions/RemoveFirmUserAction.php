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
 * RemoveFirmUserAction — a row/header Action, reused identically on
 * FirmUserResource's own table AND ViewFirmUser's header. Visible for
 * any row not already Removed (Invited/Active/Suspended). Wired
 * directly to `FirmUserInvitationService::remove()` — which itself
 * enforces the "never remove the last remaining active owner" guard —
 * never a bare `FirmUser::update()`/`delete()`. Removal is a status
 * transition (Removed), never a hard delete — the row and its audit
 * trail (invited_by, invitation_accepted_at, timestamps) are preserved.
 */
class RemoveFirmUserAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'removeFirmUser';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Remove');
        $this->icon(Heroicon::OutlinedTrash);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Remove team member');
        $this->modalDescription('This permanently revokes their access to this firm. This cannot be undone from this screen.');
        $this->modalSubmitActionLabel('Remove');

        $this->visible(function (FirmUser $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            if ($record->status === FirmUserStatus::Removed) {
                return false;
            }

            return app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role);
        });

        $this->action(function (FirmUser $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                Notification::make()->title('Could not remove team member')->body('This team member could not be found for your firm.')->danger()->send();

                return;
            }

            if (! app(FirmMembershipAccessPolicyService::class)->canManageMembers($firmUser->role)) {
                Notification::make()->title('Not permitted')->body('Your role may not remove team members.')->danger()->send();

                return;
            }

            try {
                app(FirmUserInvitationService::class)->remove($record);
            } catch (LastFirmOwnerRemovalException $e) {
                Notification::make()->title('Could not remove team member')->body($e->getMessage())->danger()->send();

                return;
            } catch (Throwable $e) {
                report($e);
                Notification::make()->title('Could not remove team member')->danger()->send();

                return;
            }

            Notification::make()->title('Team member removed')->success()->send();
        });
    }
}
