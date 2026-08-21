<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\SignatureRequestResource\Actions;

use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Services\SignatureAndPdfAccessPolicyService;
use App\Services\SignatureRequestWorkflowService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * SendSignatureRequestAction — calls
 * SignatureRequestWorkflowService::send() directly, which itself
 * mints a fresh per-recipient access token, transitions the request
 * and every recipient to Sent, and (best-effort) emails each
 * client-linked recipient their signer link — see that service's own
 * docblock. Visible only for a Draft, attorney-reviewed request with
 * at least one recipient already added (RecipientsRelationManager),
 * matching send()'s own guards exactly; a violated guard surfaces as
 * this Action's own danger notification rather than a raw exception.
 */
class SendSignatureRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sendSignatureRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Send');
        $this->icon(Heroicon::OutlinedPaperAirplane);
        $this->color('primary');
        $this->requiresConfirmation();
        $this->modalDescription('Sends this request to every recipient and emails each client-linked signer a secure link to review and sign, where eligible.');

        $this->visible(function (SignatureRequest $record): bool {
            if ($record->status !== SignatureRequestStatus::Draft) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(SignatureAndPdfAccessPolicyService::class)->canManageRequests($firmUser);
        });

        $this->action(function (SignatureRequest $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(SignatureAndPdfAccessPolicyService::class)->canManageRequests($firmUser)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser): void {
                    $fresh = SignatureRequest::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this signature request.')->danger()->send();

                        return;
                    }

                    try {
                        app(SignatureRequestWorkflowService::class)->send($fresh, $firmUser);
                        Notification::make()->title('Signature request sent')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not send signature request')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
