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
 * AttorneyReviewSignatureRequestAction — calls
 * SignatureRequestWorkflowService::attorneyReview() directly. This is
 * the literal enforcement point of "E-signature is not a substitute
 * for legal review of whether a specific document can be signed
 * electronically" — send() is hard-gated on this having run first, and
 * this Action's own visible() ceiling (FirmOwner/Attorney only, via
 * SignatureAndPdfAccessPolicyService::canReviewAsAttorney()) is the
 * same ceiling the service itself enforces, never a looser UI-side
 * check.
 */
class AttorneyReviewSignatureRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'attorneyReviewSignatureRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Attorney Review');
        $this->icon(Heroicon::OutlinedCheckBadge);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalDescription('Records your legal sign-off that this specific document is suitable to be signed electronically. Required before this request can be sent.');
        $this->modalSubmitActionLabel('Confirm Review');

        $this->schema([
            Textarea::make('notes')
                ->label('Review Notes')
                ->required()
                ->rows(3)
                ->helperText('e.g. "Suitable for e-signature under UETA/ESIGN."'),
        ]);

        $this->visible(function (SignatureRequest $record): bool {
            if ($record->status !== SignatureRequestStatus::Draft || $record->isAttorneyReviewed()) {
                return false;
            }

            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(SignatureAndPdfAccessPolicyService::class)->canReviewAsAttorney($firmUser);
        });

        $this->action(function (array $data, SignatureRequest $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(SignatureAndPdfAccessPolicyService::class)->canReviewAsAttorney($firmUser)) {
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
                        app(SignatureRequestWorkflowService::class)->attorneyReview($fresh, $firmUser, (string) $data['notes']);
                        Notification::make()->title('Attorney review recorded')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Could not record review')->body($e->getMessage())->danger()->send();
                    }
                },
            );
        });
    }
}
