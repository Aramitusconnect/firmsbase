<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\Actions;

use App\Enums\ClientPortalStatus;
use App\Models\Client;
use App\Services\ClientPortalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * InvitePortalAccessAction — Mission 4 (Client Portal Activation),
 * finding 4.2. ClientPortalService::invite() had exactly one production
 * caller (marketplace conversion) — a firm had no way to invite an
 * already-existing client to the portal from ClientResource itself.
 * Routes exclusively through ClientPortalService::invite(), never a
 * bare mutation of portal_status/portal_invitation_token — that service
 * already enforces the granted-portal-consent precondition and sends
 * (afterCommit) the real invitation email.
 *
 * Visible whenever the client is not already Active (mirrors
 * invite()'s own "always regenerates a fresh token, even for an
 * already-Invited client" resend/revoke semantics — see that method's
 * own docblock) — an Active client already has full portal access and
 * has nothing to (re-)invite.
 */
class InvitePortalAccessAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'invitePortalAccess';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Client $record): string => $record->portal_status === ClientPortalStatus::Invited
            ? 'Resend Portal Invitation'
            : 'Invite to Client Portal');
        $this->icon(Heroicon::OutlinedEnvelope);
        $this->color('primary');
        $this->requiresConfirmation();
        $this->modalDescription('Sends this client a secure link to set up their Client Portal login. Requires a granted, unrevoked portal consent record on file.');

        $this->visible(fn (Client $record): bool => $record->portal_status !== ClientPortalStatus::Active);

        $this->action(function (Client $record, ClientPortalService $clientPortalService): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                $clientPortalService->invite($record);

                Notification::make()->title('Portal invitation sent')->success()->send();
            } catch (\RuntimeException $e) {
                Notification::make()->title('Could not invite client to the portal')->body($e->getMessage())->danger()->send();
            }
        });
    }
}
