<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\SignatureRequestResource\Actions;

use App\Enums\SignatureRequestStatus;
use App\Models\SignatureRequest;
use App\Services\SignatureAndPdfAccessPolicyService;
use App\Services\SignatureRequestWorkflowService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * VoidSignatureRequestAction — calls
 * SignatureRequestWorkflowService::void() directly, which cascades to
 * every non-terminal recipient. Visible for any non-terminal status
 * (matches SignatureWorkflowTransitionService's own graph: Completed/
 * Declined/Expired/Voided all have zero allowed outgoing transitions).
 * Gated on canVoid() — FirmOwner/Attorney only, same narrow ceiling as
 * attorney-review, given the legal weight of voiding a request that
 * may already have been sent to and viewed by an external signer.
 */
class VoidSignatureRequestAction extends Action
{
    private const TERMINAL_STATUSES = [
        SignatureRequestStatus::Completed,
        SignatureRequestStatus::Declined,
        SignatureRequestStatus::Expired,
        SignatureRequestStatus::Voided,
    ];

    public static function getDefaultName(): ?string
    {
        return 'voidSignatureRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Void');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalDescription('Voids this signature request and cascades to every non-terminal recipient. This cannot be undone through this UI.');
        $this->modalSubmitActionLabel('Void Request');

        $this->schema([
            Textarea::make('reason')->label('Reason')->rows(2)->required(),
        ]);

        $this->visible(function (SignatureRequest $record): bool {
            if (in_array($record->status, self::TERMINAL_STATUSES, true)) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(SignatureAndPdfAccessPolicyService::class)->canVoid($firmUser);
        });

        $this->action(function (array $data, SignatureRequest $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(SignatureAndPdfAccessPolicyService::class)->canVoid($firmUser)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            app(TenantContextService::class)->runWithFirmContext(
                (int) $firmUser->firm_id,
                function () use ($record, $firmUser, $data): void {
                    $fresh = SignatureRequest::query()->where('id', $record->id)->firstOrFail();

                    if ((int) $firmUser->firm_id !== (int) $fresh->firm_id) {
                        Notification::make()->title('You do not have access to this signature request.')->danger()->send();

                        return;
                    }

                    try {
                        app(SignatureRequestWorkflowService::class)->void($fresh, $firmUser, (string) $data['reason']);
                        Notification::make()->title('Signature request voided')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not void signature request')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
